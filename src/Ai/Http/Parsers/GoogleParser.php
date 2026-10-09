<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Ai\Http\Parsers;

use Sidetower\Laravel\Ai\Http\HttpExchange;

/**
 * Gemini `generateContent` and `streamGenerateContent`. `promptTokenCount` includes the cached content, which is subtracted
 * and reported as `cacheReadTokens`; thinking tokens are billed as output and are added to `candidatesTokenCount`. A
 * streamed answer repeats `usageMetadata` in every chunk, the last one is the total. The model is the response's
 * `modelVersion`, else the one in the path (`models/gemini-2.5-flash:generateContent`).
 *
 * `embedContent` is not captured: Gemini does not report usage for it.
 */
class GoogleParser extends TokenParser
{
    public function handles(HttpExchange $call): bool
    {
        return $call->method() === 'POST'
            && preg_match('~:(?:generateContent|streamGenerateContent)$~', rtrim($call->path(), '/')) === 1;
    }

    protected function usage(array $payloads, HttpExchange $call): ?array
    {
        $metadata = null;

        foreach (array_reverse($payloads) as $payload) {
            if (is_array($payload['usageMetadata'] ?? null) && $payload['usageMetadata'] !== []) {
                $metadata = $payload['usageMetadata'];

                break;
            }
        }

        if ($metadata === null) {
            return null;
        }

        $prompt = $this->count($metadata['promptTokenCount'] ?? null);
        $candidates = $this->count($metadata['candidatesTokenCount'] ?? null);
        $thoughts = $this->count($metadata['thoughtsTokenCount'] ?? null);

        if ($prompt === null && $candidates === null && $thoughts === null) {
            return null;
        }

        $cached = $this->count($metadata['cachedContentTokenCount'] ?? null) ?? 0;

        $fields = [
            'inputTokens' => max(0, ($prompt ?? 0) - $cached),
            'outputTokens' => ($candidates ?? 0) + ($thoughts ?? 0),
        ];

        if ($cached > 0) {
            $fields['cacheReadTokens'] = $cached;
        }

        return $fields;
    }

    protected function responseModel(array $payloads): ?string
    {
        $model = $this->firstString($payloads, ['modelVersion']);

        return $model === null ? null : preg_replace('~^models/~', '', $model);
    }

    protected function pathModel(string $path): ?string
    {
        return preg_match('~/(?:tunedModels|models)/([^/:]+)~', $path, $match) === 1 ? $match[1] : null;
    }
}
