<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Ai\Http\Parsers;

use Sidetower\Laravel\Ai\Http\HttpExchange;

/**
 * ElevenLabs text to speech and text to dialogue, including their `/stream` and `/with-timestamps` forms. The answer is
 * audio, so the response body is never read: the units are the characters of the request's `text` (the `inputs[].text` of a
 * dialogue), or the `character-cost` header of the response when there is one. The model is the request's `model_id`, which
 * ElevenLabs defaults to `eleven_multilingual_v2`. Other endpoints (speech to text, voices) are not captured.
 */
class ElevenLabsParser implements ProviderParser
{
    private const DEFAULT_MODEL = 'eleven_multilingual_v2';

    public function handles(HttpExchange $call): bool
    {
        $path = rtrim($call->path(), '/');

        return $call->method() === 'POST' && (
            preg_match('~/text-to-speech/[^/]+(?:/stream)?(?:/with-timestamps)?$~', $path) === 1
            || preg_match('~/text-to-dialogue(?:/stream)?(?:/with-timestamps)?$~', $path) === 1
        );
    }

    public function parse(HttpExchange $call): ?array
    {
        $request = $call->requestJson() ?? [];
        $model = is_string($request['model_id'] ?? null) && trim($request['model_id']) !== '' ? $request['model_id'] : self::DEFAULT_MODEL;

        $fields = ['provider' => $call->provider, 'model' => $model, 'durationMs' => $call->durationMs()];

        if (! $call->successful()) {
            return [...$fields, 'status' => 'error'];
        }

        $units = $this->costHeader($call) ?? $this->characters($request);

        if ($units === null) {
            return [...$fields, 'status' => 'invalid_response'];
        }

        return [...$fields, 'status' => 'ok', 'units' => $units, 'unitType' => 'character'];
    }

    protected function costHeader(HttpExchange $call): ?int
    {
        $cost = $call->header('character-cost');

        return $cost !== null && ctype_digit($cost) ? (int) $cost : null;
    }

    /**
     * @param  array<string, mixed>  $request
     */
    protected function characters(array $request): ?int
    {
        if (is_string($request['text'] ?? null)) {
            return mb_strlen($request['text']);
        }

        if (! is_array($request['inputs'] ?? null)) {
            return null;
        }

        $characters = 0;

        foreach ($request['inputs'] as $input) {
            $characters += is_array($input) && is_string($input['text'] ?? null) ? mb_strlen($input['text']) : 0;
        }

        return $characters;
    }
}
