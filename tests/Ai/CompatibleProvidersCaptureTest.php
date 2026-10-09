<?php

use Illuminate\Support\Facades\Http;
use Sidetower\Laravel\Tests\Ai\AiTestHelpers;

beforeEach(fn () => Http::preventStrayRequests());

it('records an OpenRouter call with the cost it reports and its cache reads and writes', function () {
    Http::fake(['openrouter.ai/*' => Http::response([
        'id' => 'gen-1737243487-AbCdEf',
        'provider' => 'Google',
        'model' => 'google/gemini-2.5-flash',
        'object' => 'chat.completion',
        'created' => 1737243487,
        'choices' => [['logprobs' => null, 'finish_reason' => 'stop', 'native_finish_reason' => 'STOP', 'index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hello']]],
        'usage' => [
            'prompt_tokens' => 1200,
            'completion_tokens' => 640,
            'total_tokens' => 1840,
            'cost' => 0.00184,
            'is_byok' => false,
            'prompt_tokens_details' => ['cached_tokens' => 800, 'cache_write_tokens' => 100, 'audio_tokens' => 0],
            'cost_details' => ['upstream_inference_cost' => 0.0019],
            'completion_tokens_details' => ['reasoning_tokens' => 0],
        ],
    ])]);

    Http::post('https://openrouter.ai/api/v1/chat/completions', ['model' => 'google/gemini-2.5-flash', 'usage' => ['include' => true]]);

    expect(AiTestHelpers::only())->toBe([
        'type' => 'ai.usage',
        'operation' => 'unknown',
        'provider' => 'openrouter',
        'model' => 'google/gemini-2.5-flash',
        'status' => 'ok',
        'inputTokens' => 300,
        'outputTokens' => 640,
        'cacheReadTokens' => 800,
        'cacheWriteTokens' => 100,
        'reportedCostUsd' => 0.00184,
    ]);
});

it('keeps a reported cost of zero', function () {
    Http::fake(['openrouter.ai/*' => Http::response([
        'model' => 'meta-llama/llama-3.3-70b-instruct:free',
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15, 'cost' => 0],
    ])]);

    Http::post('https://openrouter.ai/api/v1/chat/completions', ['model' => 'meta-llama/llama-3.3-70b-instruct:free']);

    expect(AiTestHelpers::only())->toMatchArray(['reportedCostUsd' => 0.0, 'inputTokens' => 10]);
});

it('reads the reported cost only from providers that report one', function () {
    Http::fake(['api.mistral.ai/*' => Http::response(['model' => 'mistral-small-latest', 'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 12, 'total_tokens' => 19, 'cost' => 99]])]);

    Http::post('https://api.mistral.ai/v1/chat/completions', ['model' => 'mistral-small-latest']);

    expect(AiTestHelpers::only())->not->toHaveKey('reportedCostUsd');
});

it('records a Mistral call', function () {
    Http::fake(['api.mistral.ai/*' => Http::response([
        'id' => '8f82539654874b73a8b8dd1330c80221',
        'object' => 'chat.completion',
        'created' => 1728460792,
        'model' => 'mistral-small-2402',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Bonjour', 'tool_calls' => null], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 7, 'total_tokens' => 19, 'completion_tokens' => 12],
    ])]);

    Http::post('https://api.mistral.ai/v1/chat/completions', ['model' => 'mistral-small-latest']);

    expect(AiTestHelpers::only())->toEqual([
        'type' => 'ai.usage', 'operation' => 'unknown', 'provider' => 'mistral', 'model' => 'mistral-small-2402',
        'status' => 'ok', 'inputTokens' => 7, 'outputTokens' => 12,
    ]);
});

it('records a Mistral embeddings call', function () {
    Http::fake(['api.mistral.ai/*' => Http::response([
        'id' => 'e1', 'object' => 'list', 'model' => 'mistral-embed',
        'data' => [['object' => 'embedding', 'embedding' => [0.1, 0.2], 'index' => 0]],
        'usage' => ['prompt_tokens' => 9, 'total_tokens' => 9, 'completion_tokens' => 0],
    ])]);

    Http::post('https://api.mistral.ai/v1/embeddings', ['model' => 'mistral-embed', 'input' => ['hi']]);

    expect(AiTestHelpers::only())->toMatchArray(['provider' => 'mistral', 'model' => 'mistral-embed', 'inputTokens' => 9, 'outputTokens' => 0]);
});

it('records a Groq call under its OpenAI-compatible path', function () {
    Http::fake(['api.groq.com/*' => Http::response([
        'id' => 'chatcmpl-ea37c181-ed35-4bd4-af20-c1fcf203e0d8',
        'object' => 'chat.completion',
        'created' => 1730033815,
        'model' => 'llama-3.3-70b-versatile',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hi'], 'logprobs' => null, 'finish_reason' => 'stop']],
        'usage' => ['queue_time' => 0.016606609, 'prompt_tokens' => 13, 'prompt_time' => 0.002070851, 'completion_tokens' => 208, 'completion_time' => 0.173333333, 'total_tokens' => 221, 'total_time' => 0.175404184],
        'system_fingerprint' => 'fp_af05557ca2',
        'x_groq' => ['id' => 'req_01jb70t402ff28vnfp2dbp5eyx'],
    ])]);

    Http::post('https://api.groq.com/openai/v1/chat/completions', ['model' => 'llama-3.3-70b-versatile']);

    expect(AiTestHelpers::only())->toEqual([
        'type' => 'ai.usage', 'operation' => 'unknown', 'provider' => 'groq', 'model' => 'llama-3.3-70b-versatile',
        'status' => 'ok', 'inputTokens' => 13, 'outputTokens' => 208,
    ]);
});

it('records a DeepSeek call with the cache hits taken out of the input', function () {
    Http::fake(['api.deepseek.com/*' => Http::response([
        'id' => '85edf901-7432-49c6-baab-91ffb01dbe7a',
        'object' => 'chat.completion',
        'created' => 1737243487,
        'model' => 'deepseek-chat',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Merhaba'], 'logprobs' => null, 'finish_reason' => 'stop']],
        'usage' => [
            'prompt_tokens' => 1200, 'completion_tokens' => 42, 'total_tokens' => 1242,
            'prompt_tokens_details' => ['cached_tokens' => 1000],
            'prompt_cache_hit_tokens' => 1000, 'prompt_cache_miss_tokens' => 200,
        ],
        'system_fingerprint' => 'fp_3a5770e1b4',
    ])]);

    Http::post('https://api.deepseek.com/chat/completions', ['model' => 'deepseek-chat']);

    expect(AiTestHelpers::only())->toMatchArray(['provider' => 'deepseek', 'inputTokens' => 200, 'outputTokens' => 42, 'cacheReadTokens' => 1000]);
});

it('reads the DeepSeek cache hits when only its own field is there', function () {
    Http::fake(['api.deepseek.com/*' => Http::response([
        'model' => 'deepseek-chat',
        'usage' => ['prompt_tokens' => 500, 'completion_tokens' => 10, 'total_tokens' => 510, 'prompt_cache_hit_tokens' => 300, 'prompt_cache_miss_tokens' => 200],
    ])]);

    Http::post('https://api.deepseek.com/v1/chat/completions', ['model' => 'deepseek-chat']);

    expect(AiTestHelpers::only())->toMatchArray(['inputTokens' => 200, 'cacheReadTokens' => 300]);
});

it('adds the reasoning tokens xAI reports beside the completion tokens, and its reported cost', function () {
    Http::fake(['api.x.ai/*' => Http::response([
        'id' => 'febc7de9-9991-4b08-942a-c7082174225a',
        'object' => 'chat.completion',
        'created' => 1752355220,
        'model' => 'grok-4',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => '345', 'reasoning_content' => ''], 'finish_reason' => 'stop']],
        'usage' => [
            'prompt_tokens' => 100,
            'completion_tokens' => 40,
            'total_tokens' => 440,
            'prompt_tokens_details' => ['text_tokens' => 100, 'audio_tokens' => 0, 'image_tokens' => 0, 'cached_tokens' => 20],
            'completion_tokens_details' => ['reasoning_tokens' => 300, 'audio_tokens' => 0, 'accepted_prediction_tokens' => 0, 'rejected_prediction_tokens' => 0],
            'cost_in_usd_ticks' => 158500,
        ],
        'system_fingerprint' => 'fp_acdceb26fb',
    ])]);

    Http::post('https://api.x.ai/v1/chat/completions', ['model' => 'grok-4']);

    expect(AiTestHelpers::only())->toEqual([
        'type' => 'ai.usage', 'operation' => 'unknown', 'provider' => 'xai', 'model' => 'grok-4', 'status' => 'ok',
        'inputTokens' => 80, 'outputTokens' => 340, 'cacheReadTokens' => 20, 'reportedCostUsd' => 0.00001585,
    ]);
});

it('reads a buffered event stream of Chat Completions for its last usage', function () {
    $chunks = [
        ['id' => 'c1', 'object' => 'chat.completion.chunk', 'model' => 'gpt-4o-mini-2024-07-18', 'choices' => [['index' => 0, 'delta' => ['content' => 'Hel']]], 'usage' => null],
        ['id' => 'c1', 'object' => 'chat.completion.chunk', 'model' => 'gpt-4o-mini-2024-07-18', 'choices' => [['index' => 0, 'delta' => ['content' => 'lo']]], 'usage' => null],
        ['id' => 'c1', 'object' => 'chat.completion.chunk', 'model' => 'gpt-4o-mini-2024-07-18', 'choices' => [], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 2, 'total_tokens' => 52, 'prompt_tokens_details' => ['cached_tokens' => 10]]],
    ];
    $body = implode("\n\n", array_map(fn (array $chunk) => 'data: '.json_encode($chunk), $chunks))."\n\ndata: [DONE]\n\n";
    Http::fake(['api.openai.com/*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o-mini', 'stream' => true, 'stream_options' => ['include_usage' => true]]);

    expect(AiTestHelpers::only())->toEqual([
        'type' => 'ai.usage', 'operation' => 'unknown', 'provider' => 'openai', 'model' => 'gpt-4o-mini-2024-07-18',
        'status' => 'ok', 'inputTokens' => 40, 'outputTokens' => 2, 'cacheReadTokens' => 10,
    ]);
});

it('reads the usage of the completed event of a buffered Responses stream', function () {
    $completed = ['type' => 'response.completed', 'response' => [
        'id' => 'resp_1', 'model' => 'gpt-4.1-2025-04-14', 'status' => 'completed',
        'usage' => ['input_tokens' => 200, 'input_tokens_details' => ['cached_tokens' => 128], 'output_tokens' => 30, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 230],
    ]];
    $body = "event: response.output_text.delta\ndata: ".json_encode(['type' => 'response.output_text.delta', 'delta' => 'Hi'])."\n\nevent: response.completed\ndata: ".json_encode($completed)."\n\n";
    Http::fake(['api.openai.com/*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream; charset=utf-8'])]);

    Http::post('https://api.openai.com/v1/responses', ['model' => 'gpt-4.1', 'stream' => true]);

    expect(AiTestHelpers::only())->toMatchArray(['model' => 'gpt-4.1-2025-04-14', 'inputTokens' => 72, 'outputTokens' => 30, 'cacheReadTokens' => 128]);
});

it('skips the comment lines OpenRouter sends before an event stream', function () {
    $chunk = ['model' => 'openai/gpt-4o-mini', 'choices' => [], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 6, 'total_tokens' => 11, 'cost' => 0.0001]];
    Http::fake(['openrouter.ai/*' => Http::response(": OPENROUTER PROCESSING\n\ndata: ".json_encode($chunk)."\n\ndata: [DONE]\n\n")]);

    Http::post('https://openrouter.ai/api/v1/chat/completions', ['model' => 'openai/gpt-4o-mini', 'stream' => true]);

    expect(AiTestHelpers::only())->toMatchArray(['inputTokens' => 5, 'outputTokens' => 6, 'reportedCostUsd' => 0.0001]);
});

it('records a buffered event stream that carries no usage as ok without tokens', function () {
    $body = 'data: '.json_encode(['model' => 'gpt-4o-mini', 'choices' => [['delta' => ['content' => 'Hi']]]])."\n\ndata: [DONE]\n\n";
    Http::fake(['api.openai.com/*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o-mini', 'stream' => true]);

    expect(AiTestHelpers::only())->toBe([
        'type' => 'ai.usage', 'operation' => 'unknown', 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'status' => 'ok',
    ]);
});
