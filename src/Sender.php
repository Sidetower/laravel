<?php

declare(strict_types=1);

namespace Sidetower\Laravel;

use Closure;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One run of `sidetower:send`: the items every run adds, then the buffer emptied over the network. Each step is on its
 * own, so one that fails costs nothing else.
 */
class Sender
{
    /** Requests per run; what is left goes next minute. */
    public const MAX_REQUESTS = 5;

    public function __construct(
        protected Sidetower $sidetower,
        protected Buffer $buffer,
        protected Transport $transport,
    ) {}

    public function run(): Drain
    {
        $this->attempt('record the run', fn () => $this->buffer->cache()->forever(Buffer::LAST_RUN, Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z')));

        $this->attempt('record the scheduler check-in', fn () => $this->sidetower->record([
            'type' => 'checkin',
            'monitor' => 'scheduler',
            'status' => 'ok',
            'expectedIntervalS' => 60,
        ]));

        $dropped = $this->attempt('read the drop counter', fn (): int => $this->buffer->dropped()) ?? 0;

        if ($dropped > 0) {
            $this->sidetower->record([
                'type' => 'metric',
                'name' => 'sidetower.dropped',
                'kind' => 'counter',
                'value' => $dropped,
            ]);
        }

        $this->sidetower->persist();

        if ($dropped > 0 && $this->sidetower->pending() === []) {
            $this->attempt('reset the drop counter', fn () => $this->buffer->forgetDropped($dropped));
        }

        return $this->drain();
    }

    /**
     * Sends what the buffer holds: batches of at most 500 items and 400 KB, at most 5 requests.
     */
    public function drain(): Drain
    {
        $drain = new Drain;

        try {
            $this->send($drain);
        } catch (Throwable $exception) {
            $this->sidetower->log('debug', 'Sidetower could not send: '.$exception->getMessage());
        }

        return $drain;
    }

    protected function send(Drain $drain): void
    {
        $queue = $this->transport->chunk($this->buffer->all());

        while ($queue !== [] && $drain->requests < self::MAX_REQUESTS) {
            $batch = array_shift($queue);
            $ids = array_column($batch, 'id');
            $delivery = $this->transport->send($batch);

            $drain->requests++;
            $drain->attempts[] = ['ids' => $ids, 'delivery' => $delivery];

            switch ($delivery->outcome) {
                case Outcome::Accepted:
                    $this->buffer->remove($ids);
                    $drain->sent += count($batch) - count($delivery->rejected);
                    $drain->rejected += count($delivery->rejected);
                    $this->noteRejected($delivery);
                    break;

                case Outcome::Refused:
                    $this->buffer->remove($ids);
                    $drain->dropped += count($batch);
                    $this->warnRefused($delivery, count($batch));
                    break;

                case Outcome::TooLarge:
                    if (count($batch) > 1) {
                        $half = intdiv(count($batch), 2);
                        array_unshift($queue, array_slice($batch, 0, $half), array_slice($batch, $half));
                        break;
                    }

                    $this->buffer->remove($ids);
                    $drain->dropped++;
                    $this->warnRefused($delivery, 1);
                    break;

                case Outcome::Later:
                    $this->noteLater($delivery);
                    break 2;
            }
        }

        $drain->remaining = count($this->buffer->all());
    }

    protected function noteRejected(Delivery $delivery): void
    {
        if ($delivery->rejected === []) {
            return;
        }

        $codes = array_count_values(array_column($delivery->rejected, 'error'));
        ksort($codes);

        $this->sidetower->log('debug', sprintf(
            'Sidetower: the server rejected %d item(s): %s.',
            count($delivery->rejected),
            implode(', ', array_map(fn (string $code, int $count): string => "{$code} x{$count}", array_keys($codes), $codes)),
        ));
    }

    protected function warnRefused(Delivery $delivery, int $count): void
    {
        $answer = $delivery->status === 413 ? 'HTTP 413 (payload_too_large)' : 'HTTP '.$delivery->status.($delivery->error === null ? '' : " ({$delivery->error})");

        $this->sidetower->log('warning', "Sidetower dropped {$count} item(s): the server answered {$answer}. ".$this->hint($delivery));
    }

    protected function hint(Delivery $delivery): string
    {
        return match ($delivery->error) {
            'invalid_key' => 'Check SIDETOWER_KEY.',
            'project_archived' => 'The project of this key is archived.',
            'environment_mismatch' => "The key was made for another environment than '{$this->sidetower->environment()}'. Check SIDETOWER_ENVIRONMENT or APP_ENV.",
            'unsupported_protocol' => 'The server does not read this version of sidetower/laravel; update the package.',
            'invalid_envelope' => 'The server could not read the request. Check SIDETOWER_ENDPOINT.',
            default => 'Check SIDETOWER_ENDPOINT and SIDETOWER_KEY.',
        };
    }

    protected function noteLater(Delivery $delivery): void
    {
        if ($delivery->error === 'unexpected_response') {
            $this->sidetower->log('warning', "Sidetower kept its items: the endpoint answered HTTP {$delivery->status}, not 202. Check SIDETOWER_ENDPOINT.");

            return;
        }

        $reason = $delivery->status === null ? ($delivery->error ?? 'no answer') : "HTTP {$delivery->status}";

        $this->sidetower->log('debug', "Sidetower will retry: {$reason}.");
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $step
     * @return TResult|null
     */
    protected function attempt(string $what, Closure $step): mixed
    {
        try {
            return $step();
        } catch (Throwable $exception) {
            $this->sidetower->log('debug', "Sidetower could not {$what}: ".$exception->getMessage());

            return null;
        }
    }
}
