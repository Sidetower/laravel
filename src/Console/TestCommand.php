<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Console;

use Illuminate\Console\Command;
use Sidetower\Laravel\Buffer;
use Sidetower\Laravel\Delivery;
use Sidetower\Laravel\Outcome;
use Sidetower\Laravel\Sender;
use Sidetower\Laravel\Sidetower;
use Throwable;

class TestCommand extends Command
{
    protected $signature = 'sidetower:test';

    protected $description = 'Send a test check-in to Sidetower and show whether it was accepted';

    public function handle(Sidetower $sidetower, Buffer $buffer, Sender $sender): int
    {
        if (($reason = $sidetower->offBecause()) !== null) {
            $this->components->error("Sidetower is off: {$reason}.");

            return self::FAILURE;
        }

        $id = $sidetower->newId();

        $this->components->info(sprintf("Sending a check-in to %s as environment '%s'.", config('sidetower.endpoint'), $sidetower->environment()));

        try {
            $sidetower->record(['id' => $id, 'type' => 'checkin', 'monitor' => 'sidetower:test', 'status' => 'ok']);
            $sidetower->persist();

            $drain = $sender->drain();
            $attempt = $drain->attemptFor($id);
            $delivery = $attempt['delivery'] ?? ($drain->attempts === [] ? null : $drain->attempts[array_key_last($drain->attempts)]['delivery']);
        } catch (Throwable $exception) {
            $this->components->error('The test check-in could not be sent: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $this->forget($buffer, $id);
        }

        return $this->report($sender, $delivery, $attempt['index'] ?? null);
    }

    /**
     * A test check-in that did not get through must not wait in the buffer and show up later as a monitor.
     */
    protected function forget(Buffer $buffer, string $id): void
    {
        try {
            $buffer->remove([$id]);
        } catch (Throwable) {
        }
    }

    protected function report(Sender $sender, ?Delivery $delivery, ?int $index): int
    {
        if ($delivery === null) {
            $this->components->error('Nothing was sent. The check-in could not be written to the buffer; is the cache store reachable?');

            return self::FAILURE;
        }

        if ($index === null && $delivery->outcome !== Outcome::Later) {
            $this->components->warn('The check-in waits behind other items in the buffer and was not sent yet. Run the command again.');

            return self::FAILURE;
        }

        switch ($delivery->outcome) {
            case Outcome::Accepted:
                $rejected = collect($delivery->rejected)->firstWhere('index', $index);

                if ($rejected !== null) {
                    $this->components->error("Rejected: the server refused the check-in as {$rejected['error']}.");

                    return self::FAILURE;
                }

                $this->components->info('Accepted: Sidetower received the check-in. Make sure `php artisan schedule:run` runs every minute, so the SDK keeps sending.');

                return self::SUCCESS;

            case Outcome::Later:
                $this->components->error('Could not reach Sidetower: '.($delivery->status === null ? ($delivery->error ?? 'no answer') : "the server answered HTTP {$delivery->status}").'. Check SIDETOWER_ENDPOINT and the network.');

                return self::FAILURE;

            default:
                $this->components->error(sprintf('Refused: the server answered HTTP %s%s. %s', $delivery->status, $delivery->error === null ? '' : " ({$delivery->error})", $sender->hint($delivery)));

                return self::FAILURE;
        }
    }
}
