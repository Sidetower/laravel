<?php

declare(strict_types=1);

namespace Sidetower\Laravel;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Puts items into protocol envelopes and posts them. Only the scheduled `sidetower:send` command (and `sidetower:test`) get
 * here; a request path never does. Nothing is thrown: every way a request can end is a {@see Delivery}.
 */
class Transport
{
    /** Items in one envelope. The protocol's limit. */
    public const MAX_ITEMS = 500;

    /** JSON bytes of the items in one envelope, before compression. The protocol allows 512 KB. */
    public const MAX_BYTES = 400 * 1024;

    /** What the envelope around the items takes. */
    protected const ENVELOPE_BYTES = 1024;

    /** Values JSON cannot hold (bad UTF-8, NAN, resources) are replaced rather than failing the whole batch. */
    protected const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    public function __construct(protected Sidetower $sidetower, protected Release $release) {}

    /**
     * Splits items, in order, into batches of at most 500 items and 400 KB of JSON.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<list<array<string, mixed>>>
     */
    public function chunk(array $items): array
    {
        $batches = [];
        $batch = [];
        $bytes = self::ENVELOPE_BYTES;

        foreach ($items as $item) {
            $size = strlen((string) json_encode($item, self::JSON_FLAGS)) + 1;

            if ($batch !== [] && (count($batch) >= self::MAX_ITEMS || $bytes + $size > self::MAX_BYTES)) {
                $batches[] = $batch;
                $batch = [];
                $bytes = self::ENVELOPE_BYTES;
            }

            $batch[] = $item;
            $bytes += $size;
        }

        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }

    /**
     * The request body of the protocol.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function envelope(array $items): array
    {
        $host = gethostname();

        return [
            'protocol' => Sidetower::PROTOCOL,
            'sdk' => ['name' => 'sidetower-laravel', 'version' => Sidetower::VERSION],
            'source' => array_filter([
                'environment' => $this->sidetower->environment(),
                'release' => $this->release->name(),
                'host' => $host === false || $host === '' ? null : mb_substr($host, 0, 100),
            ], fn (?string $value): bool => $value !== null),
            'sentAt' => $this->sidetower->timestamp(),
            'items' => array_values($items),
        ];
    }

    /**
     * Posts one envelope, gzipped, with the key as a bearer token.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function send(array $items, ?string $endpoint = null): Delivery
    {
        try {
            $json = json_encode($this->envelope($items), self::JSON_FLAGS);

            if ($json === false) {
                throw new \RuntimeException(json_last_error_msg());
            }

            $timeout = max(0.1, (float) config('sidetower.timeout', 2.0));

            $response = Http::withToken((string) config('sidetower.key'))
                ->withHeaders(['Content-Encoding' => 'gzip'])
                ->withUserAgent('sidetower-laravel/'.Sidetower::VERSION)
                ->acceptJson()
                ->withoutRedirecting()
                ->timeout($timeout)
                ->connectTimeout($timeout)
                ->withBody((string) gzencode($json, 6), 'application/json')
                ->post($endpoint ?? (string) config('sidetower.endpoint'));
        } catch (Throwable $exception) {
            return new Delivery(Outcome::Later, error: Str::limit(trim($exception->getMessage()) ?: $exception::class, 200));
        }

        return $this->interpret($response);
    }

    protected function interpret(Response $response): Delivery
    {
        $status = $response->status();
        $body = $response->json();
        $error = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : null;

        if ($status === 202) {
            $rejected = [];

            foreach (is_array($body['rejected'] ?? null) ? $body['rejected'] : [] as $entry) {
                if (is_array($entry) && isset($entry['index'], $entry['error'])) {
                    $rejected[] = ['index' => (int) $entry['index'], 'error' => (string) $entry['error']];
                }
            }

            return new Delivery(Outcome::Accepted, $status, null, (int) ($body['accepted'] ?? 0), $rejected);
        }

        if ($status === 413) {
            return new Delivery(Outcome::TooLarge, $status, $error);
        }

        if ($status === 408 || $status === 425 || $status === 429 || $status >= 500) {
            return new Delivery(Outcome::Later, $status, $error);
        }

        if ($status >= 400) {
            return new Delivery(Outcome::Refused, $status, $error);
        }

        return new Delivery(Outcome::Later, $status, 'unexpected_response');
    }
}
