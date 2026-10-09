<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Ai\Http\Parsers;

use Sidetower\Laravel\Ai\Http\BodyState;
use Sidetower\Laravel\Ai\Http\HttpExchange;

/**
 * fal.ai. One generation takes several requests on the queue (submit, status polls, result) and one on `fal.run`. Only the
 * response that carries the result is counted: the synchronous `fal.run/<endpoint>` answer and the queue's
 * `.../requests/<id>` result. A queue submit and the status polls are ignored, except that a failed submit is a failed call
 * and is recorded as an error, since no result will follow.
 *
 * Units are the images of the result's `images` list (`unitType: image`); a result without images counts as one `request`.
 * The model is the endpoint id of the path (`fal-ai/flux/schnell`). The queue's result URL may name only the app
 * (`fal-ai/flux`), so the endpoint of each submit is remembered by request id for the rest of the process.
 */
class FalParser implements ProviderParser
{
    private const REMEMBERED = 200;

    /**
     * @var array<string, string>
     */
    private array $endpoints = [];

    public function handles(HttpExchange $call): bool
    {
        return $this->classify($call) !== null;
    }

    public function parse(HttpExchange $call): ?array
    {
        $route = $this->classify($call);

        if ($route === null || ($route[0] === 'result' && ! $call->successful())) {
            return null;
        }

        [$kind, $endpoint, $requestId] = $route;

        $fields = ['provider' => $call->provider, 'model' => $endpoint];

        if ($kind === 'result') {
            $fields['model'] = $this->endpoints[$requestId] ?? $endpoint;
        } else {
            $fields['durationMs'] = $call->durationMs();
        }

        if (! $call->successful()) {
            return [...$fields, 'status' => 'error'];
        }

        if ($kind === 'submit') {
            $this->remember($call, $endpoint);

            return null;
        }

        return [...$fields, ...$this->result($call)];
    }

    /**
     * @return array{0: 'sync'|'submit'|'result', 1: string, 2: string|null}|null
     */
    protected function classify(HttpExchange $call): ?array
    {
        $path = trim($call->path(), '/');

        if ($path === '') {
            return null;
        }

        if ($call->method() === 'GET') {
            return preg_match('~^(.+?)/requests/([^/]+)(?:/response)?$~', $path, $match) === 1 ? ['result', $match[1], $match[2]] : null;
        }

        if ($call->method() === 'POST') {
            return [str_starts_with($call->host(), 'queue.') ? 'submit' : 'sync', $path, null];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function result(HttpExchange $call): array
    {
        $body = $call->body();

        if ($body->state === BodyState::Invalid || ($body->state === BodyState::Json && $body->payloads === [])) {
            return ['status' => 'invalid_response'];
        }

        $images = $body->state === BodyState::Json ? ($body->payloads[0]['images'] ?? null) : null;

        return is_array($images) && array_is_list($images)
            ? ['status' => 'ok', 'units' => count($images), 'unitType' => 'image']
            : ['status' => 'ok', 'units' => 1, 'unitType' => 'request'];
    }

    protected function remember(HttpExchange $call, string $endpoint): void
    {
        $body = $call->body();
        $requestId = $body->state === BodyState::Json ? ($body->payloads[0]['request_id'] ?? null) : null;

        if (! is_string($requestId) || $requestId === '') {
            return;
        }

        $this->endpoints[$requestId] = $endpoint;

        if (count($this->endpoints) > self::REMEMBERED) {
            $this->endpoints = array_slice($this->endpoints, -self::REMEMBERED, null, true);
        }
    }
}
