<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Ai\Http\Parsers;

use Illuminate\Support\Arr;
use Sidetower\Laravel\Ai\Http\HttpExchange;

/**
 * Anthropic Messages. `input_tokens` already excludes the cache tokens, so it is used as is; cache reads and writes are
 * `cache_read_input_tokens` and `cache_creation_input_tokens`. In a buffered event stream the usage is spread over
 * `message_start` (input and cache) and `message_delta` (the running output total): later numbers replace earlier ones.
 */
class AnthropicParser extends TokenParser
{
    public function handles(HttpExchange $call): bool
    {
        return $call->method() === 'POST' && str_ends_with(rtrim($call->path(), '/'), '/messages');
    }

    protected function usage(array $payloads, HttpExchange $call): ?array
    {
        $numbers = [];

        foreach ($payloads as $payload) {
            $usage = ($payload['type'] ?? null) === 'message_start' ? Arr::get($payload, 'message.usage') : ($payload['usage'] ?? null);

            foreach (is_array($usage) ? $usage : [] as $key => $value) {
                if (($count = $this->count($value)) !== null) {
                    $numbers[$key] = $count;
                }
            }
        }

        if (! isset($numbers['input_tokens']) && ! isset($numbers['output_tokens'])) {
            return null;
        }

        $fields = [];

        foreach (['input_tokens' => 'inputTokens', 'output_tokens' => 'outputTokens'] as $from => $to) {
            if (isset($numbers[$from])) {
                $fields[$to] = $numbers[$from];
            }
        }

        foreach (['cache_read_input_tokens' => 'cacheReadTokens', 'cache_creation_input_tokens' => 'cacheWriteTokens'] as $from => $to) {
            if (($numbers[$from] ?? 0) > 0) {
                $fields[$to] = $numbers[$from];
            }
        }

        return $fields;
    }

    protected function responseModel(array $payloads): ?string
    {
        return $this->firstString($payloads, ['model', 'message.model']);
    }
}
