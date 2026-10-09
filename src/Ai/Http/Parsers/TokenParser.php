<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Ai\Http\Parsers;

use Illuminate\Support\Arr;
use Sidetower\Laravel\Ai\Http\BodyState;
use Sidetower\Laravel\Ai\Http\HttpExchange;

/**
 * The shared flow of the providers that bill by tokens and answer with JSON (or a buffered event stream) carrying a usage
 * object. A subclass says which requests it handles and how to read the usage; this class decides the status:
 *
 *  - not 2xx, or the connection failed: `error`, no tokens;
 *  - 2xx with a live stream or a body too large to look at: `ok`, no tokens (the call happened, its usage is out of sight);
 *  - 2xx with a buffered event stream: `ok`, with the last usage the stream carried if it carried one;
 *  - 2xx, readable, but not JSON or without the expected usage: `invalid_response`.
 *
 * Token meaning: `inputTokens` excludes cache read and cache write tokens, `outputTokens` includes reasoning tokens.
 */
abstract class TokenParser implements ProviderParser
{
    /**
     * Tokens, units and cost out of the decoded payloads, last usage winning. Null when there is no usage in them.
     *
     * @param  list<array<string, mixed>>  $payloads
     * @return array<string, mixed>|null
     */
    abstract protected function usage(array $payloads, HttpExchange $call): ?array;

    /**
     * The model the response names.
     *
     * @param  list<array<string, mixed>>  $payloads
     */
    abstract protected function responseModel(array $payloads): ?string;

    /**
     * The model in the path of the request, for providers that put it there.
     */
    protected function pathModel(string $path): ?string
    {
        return null;
    }

    public function parse(HttpExchange $call): ?array
    {
        $fields = ['provider' => $call->provider, 'durationMs' => $call->durationMs()];

        if (! $call->successful()) {
            return [...$fields, 'model' => $this->model([], $call), 'status' => 'error'];
        }

        $body = $call->body();

        if ($body->state === BodyState::Unreadable) {
            return [...$fields, 'model' => $this->model([], $call), 'status' => 'ok'];
        }

        $usage = $body->state === BodyState::Invalid ? null : $this->usage($body->payloads, $call);

        if ($usage !== null) {
            return [...$fields, ...$usage, 'model' => $this->model($body->payloads, $call), 'status' => 'ok'];
        }

        $status = match (true) {
            $body->state === BodyState::Stream => 'ok',
            $this->reportsError($body->payloads) => 'error',
            default => 'invalid_response',
        };

        return [...$fields, 'model' => $this->model($body->payloads, $call), 'status' => $status];
    }

    /**
     * The response's model, else the request's, else the path's: the model of the call as far as it can be known.
     *
     * @param  list<array<string, mixed>>  $payloads
     */
    protected function model(array $payloads, HttpExchange $call): string
    {
        $model = $this->responseModel($payloads) ?? $call->requestJson()['model'] ?? $this->pathModel($call->path());

        return is_string($model) && trim($model) !== '' ? $model : 'unknown';
    }

    /**
     * A 2xx answer that carries an error object instead of a result, as OpenRouter and event streams do.
     *
     * @param  list<array<string, mixed>>  $payloads
     */
    protected function reportsError(array $payloads): bool
    {
        foreach ($payloads as $payload) {
            if (filled($payload['error'] ?? null) || ($payload['type'] ?? null) === 'error') {
                return true;
            }
        }

        return false;
    }

    protected function count(mixed $value): ?int
    {
        return match (true) {
            is_int($value) => $value >= 0 ? $value : null,
            is_float($value) => is_finite($value) && $value >= 0 ? (int) round($value) : null,
            default => null,
        };
    }

    /**
     * The first string at one of the paths in any payload, first payload first.
     *
     * @param  list<array<string, mixed>>  $payloads
     * @param  list<string>  $paths
     */
    protected function firstString(array $payloads, array $paths): ?string
    {
        foreach ($payloads as $payload) {
            foreach ($paths as $path) {
                $value = Arr::get($payload, $path);

                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }
        }

        return null;
    }
}
