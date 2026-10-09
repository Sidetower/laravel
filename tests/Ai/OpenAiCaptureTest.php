<?php

use Illuminate\Support\Facades\Http;
use Sidetower\Laravel\Facades\Sidetower;
use Sidetower\Laravel\Tests\Ai\AiTestHelpers;

beforeEach(fn () => Http::preventStrayRequests());

function openAiChatResponse(): array
{
    return [
        'id' => 'chatcmpl-AbC123',
        'object' => 'chat.completion',
        'created' => 1741990201,
        'model' => 'gpt-4o-mini-2024-07-18',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'Once upon a time there was a secret story.', 'refusal' => null],
            'logprobs' => null,
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 1300,
            'completion_tokens' => 120,
            'total_tokens' => 1420,
            'prompt_tokens_details' => ['cached_tokens' => 1024, 'audio_tokens' => 0],
            'completion_tokens_details' => ['reasoning_tokens' => 0, 'audio_tokens' => 0, 'accepted_prediction_tokens' => 0, 'rejected_prediction_tokens' => 0],
        ],
        'service_tier' => 'default',
        'system_fingerprint' => 'fp_34a54ae93c',
    ];
}

it('records a chat completion with the cache tokens taken out of the input', function () {
    Http::fake(['api.openai.com/*' => Http::response(openAiChatResponse())]);

    Http::withToken('sk-secret-openai-key')->post('https://api.openai.com/v1/chat/completions', [
        'model' => 'gpt-4o-mini',
        'messages' => [['role' => 'user', 'content' => 'Tell me a story about my daughter Ayse.']],
    ]);

    expect(AiTestHelpers::only())->toBe([
        'type' => 'ai.usage',
        'operation' => 'unknown',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini-2024-07-18',
        'status' => 'ok',
        'inputTokens' => 276,
        'outputTokens' => 120,
        'cacheReadTokens' => 1024,
    ]);
});

it('never puts a prompt, a response or a secret into the item', function () {
    Http::fake(['api.openai.com/*' => Http::response(openAiChatResponse())]);

    Http::withToken('sk-secret-openai-key')->post('https://api.openai.com/v1/chat/completions', [
        'model' => 'gpt-4o-mini',
        'user' => 'user-4711',
        'messages' => [['role' => 'user', 'content' => 'Tell me a story about my daughter Ayse.']],
    ]);

    $json = json_encode(AiTestHelpers::items());

    expect(AiTestHelpers::items())->toHaveCount(1)
        ->and($json)->not->toContain('Ayse')
        ->and($json)->not->toContain('secret')
        ->and($json)->not->toContain('4711')
        ->and($json)->not->toContain('sk-');
});

it('records a Responses API call with the cached tokens taken out of the input', function () {
    Http::fake(['api.openai.com/*' => Http::response([
        'id' => 'resp_BOicbvFZ0HMexHadbYMkZGU5ct6hB',
        'object' => 'response',
        'created_at' => 1745231097,
        'status' => 'completed',
        'model' => 'gpt-4o-2024-08-06',
        'output' => [['type' => 'message', 'id' => 'msg_1', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Hi', 'annotations' => []]]]],
        'usage' => [
            'input_tokens' => 1111,
            'input_tokens_details' => ['cached_tokens' => 1024],
            'output_tokens' => 109,
            'output_tokens_details' => ['reasoning_tokens' => 64],
            'total_tokens' => 1220,
        ],
        'service_tier' => 'default',
    ])]);

    Http::post('https://api.openai.com/v1/responses', ['model' => 'gpt-4o', 'input' => 'Hello']);

    expect(AiTestHelpers::only())->toEqual([
        'type' => 'ai.usage',
        'operation' => 'unknown',
        'provider' => 'openai',
        'model' => 'gpt-4o-2024-08-06',
        'status' => 'ok',
        'inputTokens' => 87,
        'outputTokens' => 109,
        'cacheReadTokens' => 1024,
    ]);
});

it('records an embeddings call with input tokens only', function () {
    Http::fake(['api.openai.com/*' => Http::response([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.0023064255, -0.009327292, 0.015797347]]],
        'model' => 'text-embedding-3-small',
        'usage' => ['prompt_tokens' => 8, 'total_tokens' => 8],
    ])]);

    Http::post('https://api.openai.com/v1/embeddings', ['model' => 'text-embedding-3-small', 'input' => 'hello world']);

    expect(AiTestHelpers::only())->toBe([
        'type' => 'ai.usage',
        'operation' => 'unknown',
        'provider' => 'openai',
        'model' => 'text-embedding-3-small',
        'status' => 'ok',
        'inputTokens' => 8,
    ]);
});

it('leaves the reasoning tokens inside the output tokens', function () {
    $response = openAiChatResponse();
    $response['usage'] = [
        'prompt_tokens' => 100,
        'completion_tokens' => 500,
        'total_tokens' => 600,
        'prompt_tokens_details' => ['cached_tokens' => 0],
        'completion_tokens_details' => ['reasoning_tokens' => 320],
    ];
    Http::fake(['api.openai.com/*' => Http::response($response)]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'o4-mini']);

    expect(AiTestHelpers::only())->toMatchArray(['inputTokens' => 100, 'outputTokens' => 500])
        ->and(AiTestHelpers::only())->not->toHaveKey('cacheReadTokens');
});

it('records the status and the requested model of an error response', function () {
    Http::fake(['api.openai.com/*' => Http::response([
        'error' => ['message' => 'Incorrect API key provided: sk-proj-abc***', 'type' => 'invalid_request_error', 'param' => null, 'code' => 'invalid_api_key'],
    ], 401)]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o-mini', 'messages' => []]);

    expect(AiTestHelpers::only())->toBe([
        'type' => 'ai.usage',
        'operation' => 'unknown',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'status' => 'error',
    ]);
});

it('records an error object inside a 200 as an error', function () {
    Http::fake(['openrouter.ai/*' => Http::response(['error' => ['code' => 502, 'message' => 'Provider returned error']])]);

    Http::post('https://openrouter.ai/api/v1/chat/completions', ['model' => 'openai/gpt-4o-mini']);

    expect(AiTestHelpers::only())->toMatchArray(['provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'status' => 'error']);
});

it('records a 2xx that is not what the provider answers with as an invalid response', function (string|array $body, array $headers) {
    Http::fake(['api.openai.com/*' => Http::response($body, 200, $headers)]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o-mini']);

    expect(AiTestHelpers::only())->toBe([
        'type' => 'ai.usage',
        'operation' => 'unknown',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'status' => 'invalid_response',
    ]);
})->with([
    'truncated json' => ['{"id":"chatcmpl-1","choices":[{"mess', ['Content-Type' => 'application/json']],
    'html from a proxy' => ['<html><body>Bad gateway</body></html>', ['Content-Type' => 'text/html']],
    'json without usage' => [['id' => 'chatcmpl-1', 'choices' => []], []],
    'empty body' => ['', ['Content-Type' => 'application/json']],
    'json that is not an object' => ['"ok"', ['Content-Type' => 'application/json']],
    'usage without counts' => [['usage' => ['queue_time' => 0.1]], []],
]);

it('takes the model of an invalid response from the response when it names one', function () {
    Http::fake(['api.openai.com/*' => Http::response(['model' => 'gpt-4o-2024-08-06', 'choices' => []])]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);

    expect(AiTestHelpers::only())->toMatchArray(['model' => 'gpt-4o-2024-08-06', 'status' => 'invalid_response']);
});

it('names the model unknown when nothing says which one', function () {
    Http::fake(['api.openai.com/*' => Http::response('boom', 500)]);

    Http::get('https://api.openai.com/v1/chat/completions');
    Http::send('POST', 'https://api.openai.com/v1/chat/completions');

    expect(AiTestHelpers::only())->toMatchArray(['model' => 'unknown', 'status' => 'error']);
});

it('records every attempt of a retried call', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(['error' => ['message' => 'Rate limit reached']], 429)
        ->push(openAiChatResponse())]);

    Http::retry(2, 0)->post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o-mini']);

    expect(array_column(AiTestHelpers::items(), 'status'))->toBe(['error', 'ok']);
});

it('ignores the endpoints of a provider that are not AI calls', function () {
    Http::fake(['api.openai.com/*' => Http::response(['data' => []])]);

    Http::get('https://api.openai.com/v1/models');
    Http::get('https://api.openai.com/v1/responses/resp_123');
    Http::post('https://api.openai.com/v1/files', ['purpose' => 'batch']);

    expect(AiTestHelpers::items())->toBe([]);
});

it('takes the operation name and attributes of the scope the call is made in', function () {
    Http::fake(['api.openai.com/*' => Http::response(openAiChatResponse())]);

    Sidetower::ai()->operation('story.generate', function () {
        Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o-mini']);
    }, ['variant' => 'b']);

    expect(AiTestHelpers::only())->toMatchArray(['operation' => 'story.generate', 'attributes' => ['variant' => 'b']]);
});
