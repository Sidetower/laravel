<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Ai\Http\Parsers;

use Illuminate\Support\Arr;
use Sidetower\Laravel\Ai\Http\HttpExchange;

/**
 * OpenAI Chat Completions, Responses and Embeddings, and everything that speaks the same: OpenRouter, Mistral, Groq,
 * DeepSeek, xAI, Azure, Ollama and any host added to `sidetower.ai.hosts` without a parser of its own.
 *
 * OpenAI counts cached tokens inside the prompt, so they are subtracted from `inputTokens` and reported as
 * `cacheReadTokens`. OpenRouter also reports cache writes (`prompt_tokens_details.cache_write_tokens`). DeepSeek names the
 * cached tokens `prompt_cache_hit_tokens`. Reasoning is inside `completion_tokens` for OpenAI; xAI reports it beside it, which
 * shows in `total_tokens`, and the two are added.
 */
class OpenAiCompatibleParser extends TokenParser
{
    public function handles(HttpExchange $call): bool
    {
        return $call->method() === 'POST'
            && preg_match('~/(?:completions|responses|embeddings)$~', rtrim($call->path(), '/')) === 1;
    }

    protected function usage(array $payloads, HttpExchange $call): ?array
    {
        $usage = null;

        foreach (array_reverse($payloads) as $payload) {
            $candidate = $payload['usage'] ?? Arr::get($payload, 'response.usage');

            if (is_array($candidate) && $candidate !== []) {
                $usage = $candidate;

                break;
            }
        }

        if ($usage === null) {
            return null;
        }

        $prompt = $this->count($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null);
        $completion = $this->count($usage['completion_tokens'] ?? $usage['output_tokens'] ?? null);

        if ($prompt === null && $completion === null) {
            return null;
        }

        $cacheRead = $this->count(
            Arr::get($usage, 'prompt_tokens_details.cached_tokens')
            ?? Arr::get($usage, 'input_tokens_details.cached_tokens')
            ?? $usage['prompt_cache_hit_tokens'] ?? null
        ) ?? 0;

        $cacheWrite = $this->count(
            Arr::get($usage, 'prompt_tokens_details.cache_write_tokens')
            ?? Arr::get($usage, 'input_tokens_details.cache_write_tokens')
        ) ?? 0;

        $fields = [];

        if ($prompt !== null) {
            $fields['inputTokens'] = max(0, $prompt - $cacheRead - $cacheWrite);
        }

        if ($completion !== null) {
            $fields['outputTokens'] = $completion + $this->separateReasoning($usage, $prompt, $completion);
        }

        if ($cacheRead > 0) {
            $fields['cacheReadTokens'] = $cacheRead;
        }

        if ($cacheWrite > 0) {
            $fields['cacheWriteTokens'] = $cacheWrite;
        }

        if (($cost = $this->reportedCost($usage, $call->provider)) !== null) {
            $fields['reportedCostUsd'] = $cost;
        }

        return $fields;
    }

    protected function responseModel(array $payloads): ?string
    {
        return $this->firstString($payloads, ['model', 'response.model']);
    }

    /**
     * The reasoning tokens to add to `completion_tokens`: only when the total shows the provider kept them out of it.
     *
     * @param  array<string, mixed>  $usage
     */
    protected function separateReasoning(array $usage, ?int $prompt, int $completion): int
    {
        $reasoning = $this->count(
            Arr::get($usage, 'completion_tokens_details.reasoning_tokens')
            ?? Arr::get($usage, 'output_tokens_details.reasoning_tokens')
        ) ?? 0;

        $total = $this->count($usage['total_tokens'] ?? null);

        return $reasoning > 0 && $prompt !== null && $total === $prompt + $completion + $reasoning ? $reasoning : 0;
    }

    /**
     * The cost the provider itself reported, in dollars: OpenRouter's `usage.cost`, xAI's `usage.cost_in_usd_ticks` (ten
     * billionths of a dollar).
     *
     * @param  array<string, mixed>  $usage
     */
    protected function reportedCost(array $usage, string $provider): ?float
    {
        $cost = match ($provider) {
            'openrouter' => $usage['cost'] ?? null,
            'xai' => is_int($usage['cost_in_usd_ticks'] ?? null) ? $usage['cost_in_usd_ticks'] / 10_000_000_000 : null,
            default => null,
        };

        return (is_int($cost) || is_float($cost)) && is_finite($cost) && $cost >= 0 ? (float) $cost : null;
    }
}
