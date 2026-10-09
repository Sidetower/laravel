<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Import;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use JsonException;
use Sidetower\Laravel\Sidetower;

/**
 * Sends batches of items to the server's import endpoint (`POST /v1/import`: the ingest body, a secret key, only `ai.usage`
 * and `deploy` items, `ts` up to 400 days back). It is the one place of the import that touches the network, so it can be
 * swapped for the core's transport.
 *
 * A batch is gzipped and sent with the Laravel Http client, 30 seconds to answer. A 413 splits the batch in two. A 429, a 5xx
 * or a connection failure is tried three times (waiting 1 and 3 seconds, or the `Retry-After` of a 429) before the batch
 * fails; any other refusal fails it at once, since the same bytes would be refused again.
 */
class ImportClient
{
    public const TIMEOUT = 30;

    public const ATTEMPTS = 3;

    public function __construct(protected Application $app) {}

    /**
     * The import address: the ingest endpoint with its trailing `/ingest` replaced by `/import`. Null when the endpoint is
     * not set or does not end in `/ingest`.
     */
    public function endpoint(): ?string
    {
        $endpoint = config('sidetower.endpoint');

        if (! is_string($endpoint) || ! preg_match('~/ingest/?$~', $endpoint)) {
            return null;
        }

        return preg_replace('~/ingest/?$~', '/import', $endpoint);
    }

    /**
     * The environment the items are sent for: `sidetower.environment`, else the application's, lower-cased. Null when it is
     * not one the protocol accepts (`^[a-z][a-z0-9-]{0,19}$`).
     */
    public function environment(): ?string
    {
        $environment = config('sidetower.environment');
        $environment = strtolower(is_string($environment) && $environment !== '' ? $environment : $this->app->environment());

        return preg_match('/^[a-z][a-z0-9-]{0,19}$/', $environment) === 1 ? $environment : null;
    }

    /**
     * Sends the items as one request, or as several when the server finds it too large.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function send(array $items): ImportResult
    {
        $result = $this->post($items);

        if (! $result->tooLarge || count($items) < 2) {
            return $result;
        }

        $halves = array_chunk($items, intdiv(count($items) + 1, 2));
        $first = $this->send($halves[0]);

        if (! $first->ok) {
            return $first;
        }

        $second = $this->send($halves[1]);

        return $second->ok ? $first->merge($second) : $second;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    protected function post(array $items): ImportResult
    {
        $endpoint = $this->endpoint();

        if ($endpoint === null) {
            return ImportResult::failed('The endpoint does not end in /ingest, so the import address cannot be derived.');
        }

        try {
            $body = gzencode(json_encode($this->envelope($items), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 6);
        } catch (JsonException $e) {
            return ImportResult::failed('The items could not be encoded: '.$e->getMessage());
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = Http::withToken((string) config('sidetower.key'))
                    ->acceptJson()
                    ->timeout(self::TIMEOUT)
                    ->withoutRedirecting()
                    ->withHeaders(['Content-Encoding' => 'gzip'])
                    ->withBody((string) $body, 'application/json')
                    ->post($endpoint);
            } catch (ConnectionException $e) {
                if ($attempt >= self::ATTEMPTS) {
                    return ImportResult::failed('Could not reach '.$endpoint.': '.$e->getMessage());
                }

                Sleep::for($this->backoff($attempt))->seconds();

                continue;
            }

            if ($response->status() === 429 || $response->serverError()) {
                if ($attempt >= self::ATTEMPTS) {
                    return $this->refused($response);
                }

                Sleep::for($this->backoff($attempt, $response))->seconds();

                continue;
            }

            return $this->result($response);
        }
    }

    /**
     * The request body, as the ingest endpoint takes it. The server reads `source.environment` and checks it against the key.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected function envelope(array $items): array
    {
        $host = gethostname();

        return [
            'protocol' => Sidetower::PROTOCOL,
            'sdk' => ['name' => 'sidetower-laravel', 'version' => Sidetower::VERSION],
            'source' => array_filter([
                'environment' => $this->environment(),
                'host' => is_string($host) && $host !== '' ? mb_substr($host, 0, 100) : null,
            ]),
            'sentAt' => Carbon::now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'items' => $items,
        ];
    }

    protected function result(Response $response): ImportResult
    {
        if ($response->status() === 413) {
            return ImportResult::tooLarge();
        }

        if ($response->status() !== 202) {
            return $this->refused($response);
        }

        $rejected = [];

        foreach ((array) $response->json('rejected', []) as $entry) {
            $code = is_array($entry) && is_string($entry['error'] ?? null) ? $entry['error'] : 'unknown';
            $rejected[$code] = ($rejected[$code] ?? 0) + 1;
        }

        $accepted = $response->json('accepted');

        return ImportResult::accepted(is_int($accepted) ? $accepted : 0, $rejected);
    }

    protected function refused(Response $response): ImportResult
    {
        $code = $response->json('error');
        $code = is_string($code) && $code !== '' ? $code : null;

        return ImportResult::failed('The server answered '.$response->status().($code !== null ? " ({$code})" : '').'.');
    }

    protected function backoff(int $attempt, ?Response $response = null): int
    {
        $retryAfter = $response?->header('Retry-After');

        if ($retryAfter !== null && ctype_digit($retryAfter)) {
            return min((int) $retryAfter, 30);
        }

        return $attempt === 1 ? 1 : 3;
    }
}
