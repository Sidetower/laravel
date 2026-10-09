<?php

use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Sidetower\Laravel\Ai\Http\BodyState;
use Sidetower\Laravel\Ai\Http\HttpExchange;
use Sidetower\Laravel\Ai\Http\ProviderRegistry;
use Sidetower\Laravel\Tests\Ai\AiTestHelpers;

beforeEach(fn () => Http::preventStrayRequests());

/**
 * A response body that is a live stream: not seekable, and it tells when anybody reads it.
 */
function liveStream(bool &$touched, string $text = 'data: {"usage":{"prompt_tokens":1}}'): PumpStream
{
    return new PumpStream(function () use (&$touched, $text) {
        $touched = true;

        return $text;
    });
}

it('ignores a host that is not an AI provider without reading anything', function () {
    $touched = false;
    Http::fake(['example.com/*' => Http::response(liveStream($touched), 200, ['Content-Type' => 'application/json'])]);

    Http::post('https://example.com/v1/chat/completions', ['model' => 'gpt-4o-mini']);
    Http::get('https://example.com/health');

    expect($touched)->toBeFalse()
        ->and(AiTestHelpers::items())->toBe([]);
});

it('records a streamed response without touching its body', function (string $contentType) {
    $touched = false;
    Http::fake(['api.openai.com/*' => Http::response(liveStream($touched), 200, ['Content-Type' => $contentType])]);

    $response = Http::withOptions(['stream' => true])->post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o-mini', 'stream' => true]);

    expect($touched)->toBeFalse()
        ->and(AiTestHelpers::only())->toBe([
            'type' => 'ai.usage', 'operation' => 'unknown', 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'status' => 'ok',
        ]);

    expect($response->toPsrResponse()->getBody()->read(10))->toBe('data: {"us')
        ->and($touched)->toBeTrue();
})->with(['event stream' => ['text/event-stream'], 'json' => ['application/json'], 'no type' => ['']]);

it('records no duration for a live stream, whose transfer statistics only cover the wait for its first bytes', function () {
    $touched = false;
    $request = new PsrRequest('POST', 'https://api.openai.com/v1/chat/completions');
    $response = new Response(new PsrResponse(200, ['Content-Type' => 'text/event-stream'], liveStream($touched)));
    $response->transferStats = new TransferStats($request, null, 0.001);

    $fields = app(ProviderRegistry::class)->parserFor('openai')->parse(new HttpExchange('openai', new Request($request), $response));

    expect($fields)->toBe(['provider' => 'openai', 'model' => 'unknown', 'status' => 'ok'])
        ->and($touched)->toBeFalse();
});

it('does not touch the body of a streamed error response either', function () {
    $touched = false;
    Http::fake(['api.anthropic.com/*' => Http::response(liveStream($touched), 529, ['Content-Type' => 'application/json'])]);

    Http::post('https://api.anthropic.com/v1/messages', ['model' => 'claude-sonnet-4-5']);

    expect($touched)->toBeFalse()
        ->and(AiTestHelpers::only())->toMatchArray(['status' => 'error', 'model' => 'claude-sonnet-4-5']);
});

it('leaves a body it read where it found it', function () {
    $body = json_encode(['model' => 'gpt-4o', 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 6]]);
    $response = new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], $body));
    $exchange = new HttpExchange('openai', new Request(new PsrRequest('POST', 'https://api.openai.com/v1/chat/completions')), $response);

    $response->toPsrResponse()->getBody()->seek(7);

    expect($exchange->body()->state)->toBe(BodyState::Json)
        ->and($response->toPsrResponse()->getBody()->tell())->toBe(7)
        ->and($response->body())->toBe($body)
        ->and($response->json('usage.prompt_tokens'))->toBe(5);
});

it('leaves the request body readable for the application', function () {
    Http::fake(['api.openai.com/*' => Http::response('boom', 500)]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o-mini']);

    Http::assertSent(fn (Request $request) => $request->body() === '{"model":"gpt-4o-mini"}' && $request['model'] === 'gpt-4o-mini');
    expect(AiTestHelpers::only())->toMatchArray(['model' => 'gpt-4o-mini']);
});

it('does not decode a response too large to look at, but records the call', function () {
    Http::fake(['api.openai.com/*' => Http::response(str_repeat(' ', 5_000_000).'{"usage":{"prompt_tokens":1}}', 200, ['Content-Type' => 'application/json'])]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o-mini']);

    expect(AiTestHelpers::only())->toBe([
        'type' => 'ai.usage', 'operation' => 'unknown', 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'status' => 'ok',
    ]);
});

it('does not read a request body that is not JSON', function () {
    Http::fake(['api.openai.com/*' => Http::response('nope', 400)]);

    Http::asMultipart()->attach('file', 'audio-bytes', 'a.wav')->post('https://api.openai.com/v1/embeddings', ['model' => 'whisper-1']);

    expect(AiTestHelpers::only())->toMatchArray(['model' => 'unknown', 'status' => 'error']);
});

it('does not decode audio or images', function () {
    Http::fake(['api.openai.com/*' => Http::response('{"usage":{"prompt_tokens":1}}', 200, ['Content-Type' => 'audio/mpeg'])]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o-mini']);

    expect(AiTestHelpers::only())->toMatchArray(['status' => 'invalid_response'])
        ->and(AiTestHelpers::only())->not->toHaveKey('inputTokens');
});

it('records a failed connection as an error', function () {
    Http::fake(['api.anthropic.com/*' => Http::failedConnection('cURL error 28: Operation timed out after 30001 milliseconds')]);

    expect(fn () => Http::post('https://api.anthropic.com/v1/messages', ['model' => 'claude-sonnet-4-5', 'messages' => [['role' => 'user', 'content' => 'private']]]))
        ->toThrow(ConnectionException::class);

    expect(AiTestHelpers::only())->toBe([
        'type' => 'ai.usage', 'operation' => 'unknown', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'status' => 'error',
    ]);
});

it('records a failed connection of every kind of provider', function (string $url, string $provider, array $body) {
    Http::fake(['*' => Http::failedConnection()]);

    expect(fn () => Http::post($url, $body))->toThrow(ConnectionException::class);

    expect(AiTestHelpers::only())->toMatchArray(['provider' => $provider, 'status' => 'error'])
        ->and(AiTestHelpers::only())->not->toHaveKeys(['inputTokens', 'units']);
})->with([
    'openai' => ['https://api.openai.com/v1/chat/completions', 'openai', ['model' => 'gpt-4o']],
    'google' => ['https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent', 'google', []],
    'fal' => ['https://fal.run/fal-ai/flux/schnell', 'fal', ['prompt' => 'x']],
    'elevenlabs' => ['https://api.elevenlabs.io/v1/text-to-speech/v1', 'elevenlabs', ['text' => 'x']],
]);

it('ignores a failed connection to a host that is not an AI provider', function () {
    Http::fake(['*' => Http::failedConnection()]);

    expect(fn () => Http::post('https://example.com/v1/chat/completions', []))->toThrow(ConnectionException::class);

    expect(AiTestHelpers::items())->toBe([]);
});

it('measures the duration from the transfer statistics', function () {
    $request = new PsrRequest('POST', 'https://api.openai.com/v1/chat/completions');
    $response = new Response(new PsrResponse(200));
    $response->transferStats = new TransferStats($request, null, 2.3104);

    expect((new HttpExchange('openai', new Request($request), $response))->durationMs())->toBe(2310);
});

it('leaves the duration out when there are no transfer statistics', function () {
    Http::fake(['api.openai.com/*' => Http::response(['model' => 'gpt-4o-mini', 'usage' => ['prompt_tokens' => 5]])]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o-mini']);

    expect(AiTestHelpers::only())->not->toHaveKey('durationMs');
});

it('hands the duration to the item', function () {
    $request = new PsrRequest('POST', 'https://api.openai.com/v1/chat/completions');
    $response = new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode(['model' => 'm', 'usage' => ['prompt_tokens' => 5]])));
    $response->transferStats = new TransferStats($request, null, 1.5);

    $fields = app(ProviderRegistry::class)->parserFor('openai')->parse(new HttpExchange('openai', new Request($request), $response));

    expect($fields)->toMatchArray(['durationMs' => 1500, 'inputTokens' => 5]);
});

it('takes the duration of a failed connection from its handler', function () {
    $handler = new class('timed out') extends Exception
    {
        /**
         * @return array<string, float>
         */
        public function getHandlerContext(): array
        {
            return ['total_time' => 30.001];
        }
    };
    $request = new Request(new PsrRequest('POST', 'https://api.openai.com/v1/chat/completions'));

    expect((new HttpExchange('openai', $request, null, new ConnectionException('timed out', 0, $handler)))->durationMs())->toBe(30001)
        ->and((new HttpExchange('openai', $request, null, new ConnectionException('refused')))->durationMs())->toBeNull();
});

it('records nothing when ai.http is off', function () {
    config()->set('sidetower.ai.http', false);
    Http::fake(['api.openai.com/*' => Http::response(['model' => 'm', 'usage' => ['prompt_tokens' => 5]])]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'm']);

    expect(AiTestHelpers::items())->toBe([]);
});

it('records nothing while the SDK is off', function () {
    config()->set('sidetower.key', null);
    Http::fake(['api.openai.com/*' => Http::response(['model' => 'm', 'usage' => ['prompt_tokens' => 5]])]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'm']);

    expect(AiTestHelpers::items())->toBe([]);
});

it('records while ai.http is not configured at all', function () {
    config()->set('sidetower.ai', []);
    Http::fake(['api.openai.com/*' => Http::response(['model' => 'm', 'usage' => ['prompt_tokens' => 5]])]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'm']);

    expect(AiTestHelpers::items())->toHaveCount(1);
});

it('never lets a failure inside the capture reach the application', function () {
    Log::spy();
    app()->instance(ProviderRegistry::class, new class extends ProviderRegistry
    {
        public function providerFor(string $host): ?string
        {
            throw new RuntimeException('registry blew up');
        }
    });
    Http::fake(['api.openai.com/*' => Http::response(['ok' => true])]);

    $response = Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'm']);

    expect($response->json('ok'))->toBeTrue()
        ->and(AiTestHelpers::items())->toBe([]);
    Log::shouldHaveReceived('debug')->withArgs(fn (string $message) => str_contains($message, 'registry blew up'))->once();
});

it('knows the default hosts', function () {
    expect(app(ProviderRegistry::class)->hosts())->toBe([
        'api.openai.com' => 'openai',
        'openrouter.ai' => 'openrouter',
        'api.anthropic.com' => 'anthropic',
        'generativelanguage.googleapis.com' => 'google',
        'api.mistral.ai' => 'mistral',
        'api.groq.com' => 'groq',
        'api.deepseek.com' => 'deepseek',
        'api.x.ai' => 'xai',
        'fal.run' => 'fal',
        'queue.fal.run' => 'fal',
        'api.elevenlabs.io' => 'elevenlabs',
    ]);
});

it('adds hosts from the config, read as OpenAI-compatible unless the provider has a parser', function () {
    config()->set('sidetower.ai.hosts', ['llm.internal.example' => 'ollama', 'GEMINI-PROXY.example' => 'google']);
    Http::fake([
        'llm.internal.example:8443/*' => Http::response(['model' => 'llama3.2', 'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 3]]),
        'gemini-proxy.example/*' => Http::response(['usageMetadata' => ['promptTokenCount' => 7, 'candidatesTokenCount' => 2], 'modelVersion' => 'gemini-2.5-flash']),
    ]);

    Http::post('https://llm.internal.example:8443/v1/chat/completions', ['model' => 'llama3.2']);
    Http::post('https://gemini-proxy.example/v1beta/models/gemini-2.5-flash:generateContent', []);

    $items = AiTestHelpers::items();

    expect($items)->toHaveCount(2)
        ->and($items[0])->toMatchArray(['provider' => 'ollama', 'model' => 'llama3.2', 'inputTokens' => 9, 'outputTokens' => 3])
        ->and($items[1])->toMatchArray(['provider' => 'google', 'inputTokens' => 7, 'outputTokens' => 2]);
});

it('lets the config rename the provider of a default host', function () {
    config()->set('sidetower.ai.hosts', ['api.openai.com' => 'azure-openai']);
    Http::fake(['api.openai.com/*' => Http::response(['model' => 'gpt-4o', 'usage' => ['prompt_tokens' => 5]])]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);

    expect(AiTestHelpers::only())->toMatchArray(['provider' => 'azure-openai']);
});

it('lets the config remove a default host', function () {
    config()->set('sidetower.ai.hosts', ['api.groq.com' => null]);
    Http::fake(['api.groq.com/*' => Http::response(['model' => 'm', 'usage' => ['prompt_tokens' => 5]])]);

    Http::post('https://api.groq.com/openai/v1/chat/completions', ['model' => 'm']);

    expect(AiTestHelpers::items())->toBe([]);
});

it('matches a wildcard host', function () {
    config()->set('sidetower.ai.hosts', ['*.openai.azure.com' => 'azure']);
    Http::fake(['*' => Http::response(['model' => 'gpt-4o', 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1]])]);

    Http::post('https://my-resource.openai.azure.com/openai/deployments/gpt4o/chat/completions?api-version=2024-10-21', ['messages' => []]);
    Http::post('https://openai.azure.com.evil.example/openai/deployments/gpt4o/chat/completions', ['messages' => []]);

    expect(AiTestHelpers::only())->toMatchArray(['provider' => 'azure', 'model' => 'gpt-4o']);
});

it('leaves out calls with a stream it cannot read when ai.streams is off', function () {
    config()->set('sidetower.ai.streams', false);
    $touched = false;
    Http::fake(['api.openai.com/*' => Http::response(liveStream($touched), 200, ['Content-Type' => 'text/event-stream'])]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o-mini', 'stream' => true]);

    expect($touched)->toBeFalse()
        ->and(AiTestHelpers::items())->toBe([]);
});

it('still records buffered streams and failures when ai.streams is off', function () {
    config()->set('sidetower.ai.streams', false);
    $body = 'data: '.json_encode(['model' => 'gpt-4o-mini', 'choices' => [], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 6]])."\n\ndata: [DONE]\n\n";
    Http::fake([
        'api.openai.com/*' => Http::sequence()
            ->push($body, 200, ['Content-Type' => 'text/event-stream'])
            ->push(['error' => ['message' => 'x']], 500),
    ]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o-mini']);
    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o-mini']);

    expect(array_column(AiTestHelpers::items(), 'status'))->toBe(['ok', 'error'])
        ->and(AiTestHelpers::items()[0])->toMatchArray(['inputTokens' => 5, 'outputTokens' => 6]);
});
