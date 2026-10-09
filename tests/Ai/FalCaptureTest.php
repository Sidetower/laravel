<?php

use Illuminate\Support\Facades\Http;
use Sidetower\Laravel\Tests\Ai\AiTestHelpers;

beforeEach(fn () => Http::preventStrayRequests());

function falImages(int $count): array
{
    return [
        'images' => array_map(fn (int $i) => ['url' => "https://v3.fal.media/files/rabbit/abc{$i}.png", 'width' => 1024, 'height' => 768, 'content_type' => 'image/png'], range(1, $count)),
        'timings' => ['inference' => 0.41],
        'seed' => 42,
        'has_nsfw_concepts' => array_fill(0, $count, false),
        'prompt' => 'a secret prompt',
    ];
}

it('counts the images of a synchronous result', function () {
    Http::fake(['fal.run/*' => Http::response(falImages(2))]);

    Http::withHeaders(['Authorization' => 'Key fal-secret'])->post('https://fal.run/fal-ai/flux/schnell', ['prompt' => 'a secret prompt', 'num_images' => 2]);

    expect(AiTestHelpers::only())->toBe([
        'type' => 'ai.usage',
        'operation' => 'unknown',
        'provider' => 'fal',
        'model' => 'fal-ai/flux/schnell',
        'status' => 'ok',
        'units' => 2,
        'unitType' => 'image',
    ]);

    expect(json_encode(AiTestHelpers::items()))->not->toContain('secret');
});

it('counts a result without images as one request', function () {
    Http::fake(['fal.run/*' => Http::response(['video' => ['url' => 'https://v3.fal.media/files/lion/clip.mp4'], 'seed' => 7])]);

    Http::post('https://fal.run/fal-ai/kling-video/v2/master/text-to-video', ['prompt' => 'x']);

    expect(AiTestHelpers::only())->toMatchArray(['model' => 'fal-ai/kling-video/v2/master/text-to-video', 'units' => 1, 'unitType' => 'request']);
});

it('ignores the submit and the status polls of a queued call and counts its result', function () {
    Http::fake([
        'queue.fal.run/fal-ai/flux/schnell/requests/764cabcf-b745-4b3e-ae38-1200304cf45b/status*' => Http::response(['status' => 'IN_PROGRESS', 'queue_position' => 0]),
        'queue.fal.run/fal-ai/flux/schnell/requests/764cabcf-b745-4b3e-ae38-1200304cf45b' => Http::response(falImages(1)),
        'queue.fal.run/fal-ai/flux/schnell' => Http::response([
            'request_id' => '764cabcf-b745-4b3e-ae38-1200304cf45b',
            'response_url' => 'https://queue.fal.run/fal-ai/flux/schnell/requests/764cabcf-b745-4b3e-ae38-1200304cf45b',
            'status_url' => 'https://queue.fal.run/fal-ai/flux/schnell/requests/764cabcf-b745-4b3e-ae38-1200304cf45b/status',
            'cancel_url' => 'https://queue.fal.run/fal-ai/flux/schnell/requests/764cabcf-b745-4b3e-ae38-1200304cf45b/cancel',
            'queue_position' => 0,
        ]),
    ]);

    Http::post('https://queue.fal.run/fal-ai/flux/schnell', ['prompt' => 'x']);
    Http::get('https://queue.fal.run/fal-ai/flux/schnell/requests/764cabcf-b745-4b3e-ae38-1200304cf45b/status?logs=1');
    Http::get('https://queue.fal.run/fal-ai/flux/schnell/requests/764cabcf-b745-4b3e-ae38-1200304cf45b/status?logs=1');

    expect(AiTestHelpers::items())->toBe([]);

    Http::get('https://queue.fal.run/fal-ai/flux/schnell/requests/764cabcf-b745-4b3e-ae38-1200304cf45b');

    expect(AiTestHelpers::only())->toBe([
        'type' => 'ai.usage', 'operation' => 'unknown', 'provider' => 'fal', 'model' => 'fal-ai/flux/schnell',
        'status' => 'ok', 'units' => 1, 'unitType' => 'image',
    ]);
});

it('reads the result URL a submit gave, which may name only the app', function () {
    Http::fake([
        'queue.fal.run/fal-ai/flux-pro/kontext' => Http::response(['request_id' => 'req-1']),
        'queue.fal.run/fal-ai/flux-pro/requests/req-1' => Http::response(falImages(1)),
        'queue.fal.run/fal-ai/flux-pro/requests/req-2' => Http::response(falImages(1)),
    ]);

    Http::post('https://queue.fal.run/fal-ai/flux-pro/kontext', ['prompt' => 'x']);
    Http::get('https://queue.fal.run/fal-ai/flux-pro/requests/req-1');
    Http::get('https://queue.fal.run/fal-ai/flux-pro/requests/req-2');

    expect(array_column(AiTestHelpers::items(), 'model'))->toBe(['fal-ai/flux-pro/kontext', 'fal-ai/flux-pro']);
});

it('also reads a result URL that ends in /response', function () {
    Http::fake(['queue.fal.run/*' => Http::response(falImages(3))]);

    Http::get('https://queue.fal.run/fal-ai/flux/dev/requests/abc/response');

    expect(AiTestHelpers::only())->toMatchArray(['model' => 'fal-ai/flux/dev', 'units' => 3, 'unitType' => 'image']);
});

it('records a failed submit as an error, since no result will follow', function () {
    Http::fake(['queue.fal.run/*' => Http::response(['detail' => 'Unauthorized'], 401)]);

    Http::post('https://queue.fal.run/fal-ai/flux/schnell', ['prompt' => 'x']);

    expect(AiTestHelpers::only())->toBe([
        'type' => 'ai.usage', 'operation' => 'unknown', 'provider' => 'fal', 'model' => 'fal-ai/flux/schnell', 'status' => 'error',
    ]);
});

it('records a failed synchronous call as an error', function () {
    Http::fake(['fal.run/*' => Http::response(['detail' => [['msg' => 'Field required', 'type' => 'missing']]], 422)]);

    Http::post('https://fal.run/fal-ai/flux/schnell', []);

    expect(AiTestHelpers::only())->toMatchArray(['model' => 'fal-ai/flux/schnell', 'status' => 'error'])
        ->and(AiTestHelpers::only())->not->toHaveKey('units');
});

it('ignores a result poll that is not an answer yet', function () {
    Http::fake(['queue.fal.run/*' => Http::response(['detail' => 'Request is still in progress'], 400)]);

    Http::get('https://queue.fal.run/fal-ai/flux/schnell/requests/abc');

    expect(AiTestHelpers::items())->toBe([]);
});

it('ignores the cancel of a queued call', function () {
    Http::fake(['queue.fal.run/*' => Http::response(['status' => 'CANCELLATION_REQUESTED'], 202)]);

    Http::put('https://queue.fal.run/fal-ai/flux/schnell/requests/abc/cancel');

    expect(AiTestHelpers::items())->toBe([]);
});

it('records a result that is not JSON as an invalid response', function () {
    Http::fake(['fal.run/*' => Http::response('<html>gateway timeout</html>', 200, ['Content-Type' => 'text/html'])]);

    Http::post('https://fal.run/fal-ai/flux/schnell', []);

    expect(AiTestHelpers::only())->toMatchArray(['status' => 'invalid_response'])
        ->and(AiTestHelpers::only())->not->toHaveKey('units');
});

it('records a result with an empty object as an invalid response', function () {
    Http::fake(['fal.run/*' => Http::response('{}', 200, ['Content-Type' => 'application/json'])]);

    Http::post('https://fal.run/fal-ai/flux/schnell', []);

    expect(AiTestHelpers::only())->toMatchArray(['status' => 'invalid_response']);
});
