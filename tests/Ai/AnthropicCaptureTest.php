<?php

use Illuminate\Support\Facades\Http;
use Sidetower\Laravel\Tests\Ai\AiTestHelpers;

beforeEach(fn () => Http::preventStrayRequests());

function anthropicMessage(array $usage): array
{
    return [
        'id' => 'msg_01X2Qk7LtNEh4HB9xpYU57XU',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-5-20250929',
        'content' => [['type' => 'text', 'text' => 'A private reply']],
        'stop_reason' => 'end_turn',
        'stop_sequence' => null,
        'usage' => $usage,
    ];
}

it('records a message with its cache reads and writes, none of them part of the input', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage([
        'input_tokens' => 11,
        'cache_creation_input_tokens' => 300,
        'cache_read_input_tokens' => 1500,
        'cache_creation' => ['ephemeral_5m_input_tokens' => 300, 'ephemeral_1h_input_tokens' => 0],
        'output_tokens' => 503,
        'service_tier' => 'standard',
    ]))]);

    Http::withHeaders(['x-api-key' => 'sk-ant-secret', 'anthropic-version' => '2023-06-01'])
        ->post('https://api.anthropic.com/v1/messages', ['model' => 'claude-sonnet-4-5', 'max_tokens' => 1024, 'messages' => [['role' => 'user', 'content' => 'Private prompt']]]);

    expect(AiTestHelpers::only())->toBe([
        'type' => 'ai.usage',
        'operation' => 'unknown',
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-5-20250929',
        'status' => 'ok',
        'inputTokens' => 11,
        'outputTokens' => 503,
        'cacheReadTokens' => 1500,
        'cacheWriteTokens' => 300,
    ]);

    expect(json_encode(AiTestHelpers::items()))->not->toContain('Private')->not->toContain('secret');
});

it('records a message without cache tokens', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage(['input_tokens' => 2095, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0, 'output_tokens' => 31]))]);

    Http::post('https://api.anthropic.com/v1/messages', ['model' => 'claude-sonnet-4-5']);

    expect(AiTestHelpers::only())->toMatchArray(['inputTokens' => 2095, 'outputTokens' => 31])
        ->and(AiTestHelpers::only())->not->toHaveKeys(['cacheReadTokens', 'cacheWriteTokens']);
});

it('records an overloaded response as an error with the requested model', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)]);

    Http::post('https://api.anthropic.com/v1/messages', ['model' => 'claude-haiku-4-5', 'messages' => []]);

    expect(AiTestHelpers::only())->toBe([
        'type' => 'ai.usage', 'operation' => 'unknown', 'provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'status' => 'error',
    ]);
});

it('records a 2xx without usage as an invalid response', function (string|array $body) {
    Http::fake(['api.anthropic.com/*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);

    Http::post('https://api.anthropic.com/v1/messages', ['model' => 'claude-sonnet-4-5']);

    expect(AiTestHelpers::only())->toMatchArray(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'status' => 'invalid_response']);
})->with([
    'no usage' => [['id' => 'msg_1', 'type' => 'message', 'content' => []]],
    'cut off' => ['{"id":"msg_1","type":"mes'],
    'not json' => ['upstream connect error'],
]);

it('puts the usage of a buffered stream together from its start and delta events', function () {
    $events = [
        ['message_start', ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-5-20250929', 'content' => [], 'usage' => ['input_tokens' => 25, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 4000, 'output_tokens' => 1]]]],
        ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hi']]],
        ['message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 15]]],
        ['message_stop', ['type' => 'message_stop']],
    ];
    $body = implode('', array_map(fn (array $event) => "event: {$event[0]}\ndata: ".json_encode($event[1])."\n\n", $events));
    Http::fake(['api.anthropic.com/*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);

    Http::post('https://api.anthropic.com/v1/messages', ['model' => 'claude-sonnet-4-5', 'stream' => true]);

    expect(AiTestHelpers::only())->toBe([
        'type' => 'ai.usage', 'operation' => 'unknown', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-5-20250929',
        'status' => 'ok', 'inputTokens' => 25, 'outputTokens' => 15, 'cacheReadTokens' => 4000,
    ]);
});

it('does not count the token counting or batch endpoints', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['input_tokens' => 14])]);

    Http::post('https://api.anthropic.com/v1/messages/count_tokens', ['model' => 'claude-sonnet-4-5']);
    Http::post('https://api.anthropic.com/v1/messages/batches', ['requests' => []]);

    expect(AiTestHelpers::items())->toBe([]);
});
