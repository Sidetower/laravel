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
 * The Interactions API (`POST /v1beta/interactions`, which laravel/ai's Gemini driver uses) reports `usage` with
 * `total_input_tokens` (cached tokens included), `total_cached_tokens`, `total_output_tokens` and `total_thought_tokens`,
 * read the same way. Its model is the response's or the request's `model`.
 *
 * `embedContent` is not captured: Gemini does not report usage for it.
 */
class GoogleParser extends TokenParser
{
    public function handles(HttpExchange $call): bool
    {
        return $call->method() === 'POST'
            && preg_match('~(?::generateContent|:streamGenerateContent|/interactions)$~', rtrim($call->path(), '/')) === 1;
    }

    protected function usage(array $payloads, HttpExchange $call): ?array
    {
        if (str_ends_with(rtrim($call->path(), '/'), '/interactions')) {
            return $this->interactionUsage($payloads);
        }

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

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @return array<string, int>|null
     */
    protected function interactionUsage(array $payloads): ?array
    {
        $usage = null;

        foreach (array_reverse($payloads) as $payload) {
            $candidate = $payload['usage'] ?? $payload['interaction']['usage'] ?? null;

            if (is_array($candidate) && $candidate !== []) {
                $usage = $candidate;

                break;
            }
        }

        if ($usage === null) {
            return null;
        }

        $input = $this->count($usage['total_input_tokens'] ?? null);
        $output = $this->count($usage['total_output_tokens'] ?? null);
        $thoughts = $this->count($usage['total_thought_tokens'] ?? null);

        if ($input === null && $output === null && $thoughts === null) {
            return null;
        }

        $cached = $this->count($usage['total_cached_tokens'] ?? null) ?? 0;

        $fields = [
            'inputTokens' => max(0, ($input ?? 0) - $cached),
            'outputTokens' => ($output ?? 0) + ($thoughts ?? 0),
        ];

        if ($cached > 0) {
            $fields['cacheReadTokens'] = $cached;
        }

        return $fields;
    }

    protected function responseModel(array $payloads): ?string
    {
        $model = $this->firstString($payloads, ['modelVersion', 'model', 'interaction.model']);

        return $model === null ? null : preg_replace('~^models/~', '', $model);
    }

    protected function pathModel(string $path): ?string
    {
        return preg_match('~/(?:tunedModels|models)/([^/:]+)~', $path, $match) === 1 ? $match[1] : null;
    }
}
