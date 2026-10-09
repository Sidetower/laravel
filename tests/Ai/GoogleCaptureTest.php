<?php

use Illuminate\Support\Facades\Http;
use Sidetower\Laravel\Tests\Ai\AiTestHelpers;

beforeEach(fn () => Http::preventStrayRequests());

function geminiResponse(array $usageMetadata): array
{
    return [
        'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'A private answer']]], 'finishReason' => 'STOP', 'index' => 0]],
        'usageMetadata' => $usageMetadata,
        'modelVersion' => 'gemini-2.5-flash',
        'responseId' => 'abc123',
    ];
}

it('records a Gemini call with cached content taken out of the input and thinking added to the output', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiResponse([
        'promptTokenCount' => 1000,
        'candidatesTokenCount' => 200,
        'totalTokenCount' => 1500,
        'cachedContentTokenCount' => 600,
        'thoughtsTokenCount' => 300,
        'promptTokensDetails' => [['modality' => 'TEXT', 'tokenCount' => 1000]],
    ]))]);

    Http::withHeaders(['x-goog-api-key' => 'AIza-secret-key'])
        ->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent', ['contents' => [['parts' => [['text' => 'Private prompt']]]]]);

    expect(AiTestHelpers::only())->toBe([
        'type' => 'ai.usage',
        'operation' => 'unknown',
        'provider' => 'google',
        'model' => 'gemini-2.5-flash',
        'status' => 'ok',
        'inputTokens' => 400,
        'outputTokens' => 500,
        'cacheReadTokens' => 600,
    ]);

    expect(json_encode(AiTestHelpers::items()))->not->toContain('Private')->not->toContain('AIza');
});

it('records a Gemini call without cache or thinking', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiResponse(['promptTokenCount' => 4, 'candidatesTokenCount' => 57, 'totalTokenCount' => 61]))]);

    Http::post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent', []);

    expect(AiTestHelpers::only())->toEqual([
        'type' => 'ai.usage', 'operation' => 'unknown', 'provider' => 'google', 'model' => 'gemini-2.5-flash', 'status' => 'ok',
        'inputTokens' => 4, 'outputTokens' => 57,
    ]);
});

it('takes the model from the path when the response does not name one', function () {
    $response = geminiResponse(['promptTokenCount' => 4, 'candidatesTokenCount' => 5]);
    unset($response['modelVersion']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($response)]);

    Http::post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent?key=AIza-secret', []);

    expect(AiTestHelpers::only())->toMatchArray(['model' => 'gemini-2.5-pro']);
    expect(json_encode(AiTestHelpers::items()))->not->toContain('AIza');
});

it('takes the model from an encoded path', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 429, 'message' => 'Resource exhausted', 'status' => 'RESOURCE_EXHAUSTED']], 429)]);

    Http::post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash%3AgenerateContent', []);

    expect(AiTestHelpers::only())->toBe([
        'type' => 'ai.usage', 'operation' => 'unknown', 'provider' => 'google', 'model' => 'gemini-2.5-flash', 'status' => 'error',
    ]);
});

it('records a blocked prompt, which has no candidates, with its input', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'promptFeedback' => ['blockReason' => 'SAFETY'],
        'usageMetadata' => ['promptTokenCount' => 12, 'totalTokenCount' => 12],
        'modelVersion' => 'gemini-2.5-flash',
    ])]);

    Http::post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent', []);

    expect(AiTestHelpers::only())->toMatchArray(['inputTokens' => 12, 'outputTokens' => 0, 'status' => 'ok']);
});

it('records a 2xx without usage metadata as an invalid response', function (string|array $body) {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);

    Http::post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent', []);

    expect(AiTestHelpers::only())->toBe([
        'type' => 'ai.usage', 'operation' => 'unknown', 'provider' => 'google', 'model' => 'gemini-2.5-flash', 'status' => 'invalid_response',
    ]);
})->with([
    'no usage' => [['candidates' => []]],
    'empty usage' => [['candidates' => [], 'usageMetadata' => []]],
    'not json' => ['<!doctype html>'],
]);

it('takes the usage of the last chunk of a buffered event stream', function () {
    $chunks = [
        ['candidates' => [['content' => ['parts' => [['text' => 'Hel']]]]], 'usageMetadata' => ['promptTokenCount' => 30, 'candidatesTokenCount' => 1, 'totalTokenCount' => 31], 'modelVersion' => 'gemini-2.5-flash'],
        ['candidates' => [['content' => ['parts' => [['text' => 'lo']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 30, 'candidatesTokenCount' => 9, 'thoughtsTokenCount' => 11, 'totalTokenCount' => 50], 'modelVersion' => 'gemini-2.5-flash'],
    ];
    $body = implode('', array_map(fn (array $chunk) => 'data: '.json_encode($chunk)."\r\n\r\n", $chunks));
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);

    Http::post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:streamGenerateContent?alt=sse', []);

    expect(AiTestHelpers::only())->toMatchArray(['inputTokens' => 30, 'outputTokens' => 20, 'status' => 'ok']);
});

it('takes the usage of the last chunk of a streamed JSON array', function () {
    $chunks = [
        ['candidates' => [['content' => ['parts' => [['text' => 'Hel']]]]], 'usageMetadata' => ['promptTokenCount' => 8, 'candidatesTokenCount' => 1]],
        ['candidates' => [['content' => ['parts' => [['text' => 'lo']]]]], 'usageMetadata' => ['promptTokenCount' => 8, 'candidatesTokenCount' => 4], 'modelVersion' => 'gemini-2.5-flash-lite'],
    ];
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($chunks)]);

    Http::post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:streamGenerateContent', []);

    expect(AiTestHelpers::only())->toMatchArray(['model' => 'gemini-2.5-flash-lite', 'inputTokens' => 8, 'outputTokens' => 4]);
});

it('does not count embedding calls, for which Gemini reports no usage', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['embedding' => ['values' => [0.1, 0.2]]])]);

    Http::post('https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent', []);

    expect(AiTestHelpers::items())->toBe([]);
});
