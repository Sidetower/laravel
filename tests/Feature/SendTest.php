<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Sidetower\Laravel\Sender;
use Sidetower\Laravel\Sidetower;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    Carbon::setTestNow('2026-10-09T18:00:00.250Z');
});

it('posts a gzipped envelope with the key as a bearer token', function () {
    fakeServer();
    fillBuffer(2);

    app(Sender::class)->drain();

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://sidetower.test/v1/ingest'
            && $request->header('Authorization') === ['Bearer sk_test0000000000000000000000000000']
            && $request->header('Content-Type') === ['application/json']
            && $request->header('Content-Encoding') === ['gzip']
            && gzdecode($request->body()) !== false;
    });

    $envelope = sentEnvelopes()[0];

    expect($envelope['protocol'])->toBe(1)
        ->and($envelope['sdk'])->toBe(['name' => 'sidetower-laravel', 'version' => Sidetower::VERSION])
        ->and($envelope['source'])->toBe(['environment' => 'testing', 'host' => gethostname()])
        ->and($envelope['sentAt'])->toBe('2026-10-09T18:00:00.250Z')
        ->and(array_column($envelope['items'], 'id'))->toBe(['item-1', 'item-2']);
});

it('puts the release into the source when there is one', function () {
    fakeServer();
    config()->set('sidetower.release', '2026.10.09-1');
    fillBuffer(1);

    app(Sender::class)->drain();

    expect(sentEnvelopes()[0]['source'])->toBe(['environment' => 'testing', 'release' => '2026.10.09-1', 'host' => gethostname()]);
});

it('sends to the configured endpoint', function () {
    Http::fake(['https://ingest.example.com/*' => Http::response(['accepted' => 1, 'rejected' => []], 202)]);
    config()->set('sidetower.endpoint', 'https://ingest.example.com/v1/ingest');
    fillBuffer(1);

    app(Sender::class)->drain();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://ingest.example.com/v1/ingest');
});

it('sends nothing when the buffer is empty', function () {
    fakeServer();

    $drain = app(Sender::class)->drain();

    Http::assertNothingSent();
    expect($drain->requests)->toBe(0);
});

describe('202', function () {
    it('removes the sent items from the buffer', function () {
        fakeServer();
        fillBuffer(3);

        $drain = app(Sender::class)->drain();

        expect(buffered())->toBe([])
            ->and($drain->sent)->toBe(3)
            ->and($drain->rejected)->toBe(0)
            ->and($drain->remaining)->toBe(0);
    });

    it('keeps items added while the request was on its way', function () {
        fillBuffer(1);
        Http::fake(['sidetower.test/*' => function () {
            fillBuffer(1, ['id' => 'late']);

            return Http::response(['accepted' => 1, 'rejected' => []], 202);
        }]);

        app(Sender::class)->drain();

        expect(array_column(buffered(), 'id'))->toBe(['late']);
    });

    it('removes rejected items too and logs their codes once at debug level', function () {
        Log::spy();
        fakeServer(Http::response(['accepted' => 2, 'rejected' => [['index' => 1, 'error' => 'invalid_item'], ['index' => 3, 'error' => 'stale_item'], ['index' => 4, 'error' => 'invalid_item']]], 202));
        fillBuffer(5);

        $drain = app(Sender::class)->drain();

        expect(buffered())->toBe([])
            ->and($drain->sent)->toBe(2)
            ->and($drain->rejected)->toBe(3);

        Log::shouldHaveReceived('debug')->once()->withArgs(fn (string $message) => str_contains($message, '3 item(s)') && str_contains($message, 'invalid_item x2, stale_item x1'));
        Log::shouldNotHaveReceived('warning');
    });
});

describe('refused for good', function () {
    it('drops the batch and warns once', function (int $status, string $error) {
        Log::spy();
        fakeServer(Http::response(['error' => $error, 'message' => 'Turkish text'], $status));
        fillBuffer(3);

        $drain = app(Sender::class)->drain();

        expect(buffered())->toBe([])
            ->and($drain->dropped)->toBe(3);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, "HTTP {$status} ({$error})") && str_contains($message, '3 item(s)'));
    })->with([
        'invalid_envelope' => [400, 'invalid_envelope'],
        'environment_mismatch' => [400, 'environment_mismatch'],
        'unsupported_protocol' => [400, 'unsupported_protocol'],
        'invalid_key' => [401, 'invalid_key'],
        'project_archived' => [403, 'project_archived'],
        'another 4xx' => [415, 'unsupported_encoding'],
    ]);

    it('tells what to check, without the key', function () {
        Log::spy();
        fakeServer(Http::response(['error' => 'invalid_key', 'message' => 'x'], 401));
        fillBuffer(1);

        app(Sender::class)->drain();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'SIDETOWER_KEY') && ! str_contains($message, 'sk_test'));
    });
});

describe('413', function () {
    it('splits the batch in two and sends the halves', function () {
        fakeServer(Http::sequence()
            ->push(['error' => 'payload_too_large', 'message' => 'x'], 413)
            ->push(['accepted' => 3, 'rejected' => []], 202)
            ->push(['accepted' => 3, 'rejected' => []], 202));
        fillBuffer(6);

        $drain = app(Sender::class)->drain();

        $sizes = array_map(fn (array $envelope) => count($envelope['items']), sentEnvelopes());

        expect($sizes)->toBe([6, 3, 3])
            ->and(buffered())->toBe([])
            ->and($drain->requests)->toBe(3);
    });

    it('splits the halves again while they are refused', function () {
        fakeServer(Http::sequence()
            ->push(['error' => 'payload_too_large', 'message' => 'x'], 413)
            ->push(['error' => 'payload_too_large', 'message' => 'x'], 413)
            ->push(['accepted' => 2, 'rejected' => []], 202)
            ->push(['accepted' => 2, 'rejected' => []], 202)
            ->push(['accepted' => 2, 'rejected' => []], 202));
        fillBuffer(8);

        app(Sender::class)->drain();

        $sizes = array_map(fn (array $envelope) => count($envelope['items']), sentEnvelopes());

        expect($sizes)->toBe([8, 4, 2, 2, 4])
            ->and(buffered())->toBe([]);
    });

    it('drops a single item that is still too large and warns', function () {
        Log::spy();
        fakeServer(Http::response(['error' => 'payload_too_large', 'message' => 'x'], 413));
        fillBuffer(1);

        $drain = app(Sender::class)->drain();

        expect(buffered())->toBe([])
            ->and($drain->dropped)->toBe(1);

        Log::shouldHaveReceived('warning')->once();
    });

    it('counts the halves against the five requests of a run', function () {
        fakeServer(Http::response(['error' => 'payload_too_large', 'message' => 'x'], 413));
        fillBuffer(40);

        $drain = app(Sender::class)->drain();

        expect($drain->requests)->toBe(5)
            ->and(buffered())->not->toBe([]);
    });
});

describe('try again later', function () {
    it('keeps the items and stops after the first request', function (int $status) {
        fakeServer(Http::response(['error' => 'whatever'], $status));
        fillBuffer(1200);

        $drain = app(Sender::class)->drain();

        Http::assertSentCount(1);
        expect(buffered())->toHaveCount(1200)
            ->and($drain->remaining)->toBe(1200)
            ->and($drain->dropped)->toBe(0);
    })->with([429, 500, 502, 503, 504, 408]);

    it('keeps the items when the connection fails or times out', function (string $message) {
        Log::spy();
        Http::fake(fn () => throw new ConnectionException($message));
        fillBuffer(3);

        $drain = app(Sender::class)->drain();

        expect(buffered())->toHaveCount(3)
            ->and($drain->requests)->toBe(1);

        Log::shouldNotHaveReceived('warning');
    })->with([
        'timeout' => 'cURL error 28: Operation timed out after 2001 milliseconds',
        'refused' => 'cURL error 7: Failed to connect to sidetower.test port 443',
    ]);

    it('keeps the items and warns when the endpoint answers something that is not the server', function (int $status) {
        Log::spy();
        fakeServer(Http::response('<html>hello</html>', $status));
        fillBuffer(2);

        app(Sender::class)->drain();

        expect(buffered())->toHaveCount(2);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, "HTTP {$status}"));
    })->with([200, 204, 301, 302]);

    it('does not follow a redirect with the key', function () {
        fakeServer(Http::response('', 301, ['Location' => 'https://elsewhere.test/']));
        fillBuffer(1);

        app(Sender::class)->drain();

        Http::assertSentCount(1);
    });

    it('sends them with the next run', function () {
        fakeServer(Http::sequence()->push([], 503)->push(['accepted' => 2, 'rejected' => []], 202));
        fillBuffer(2);

        app(Sender::class)->drain();
        expect(buffered())->toHaveCount(2);

        app(Sender::class)->drain();
        expect(buffered())->toBe([])
            ->and(array_column(sentEnvelopes()[1]['items'], 'id'))->toBe(['item-1', 'item-2']);
    });
});

describe('batches', function () {
    it('holds at most 500 items', function () {
        fakeServer();
        fillBuffer(1200);

        $drain = app(Sender::class)->drain();

        expect(array_map(fn (array $envelope) => count($envelope['items']), sentEnvelopes()))->toBe([500, 500, 200])
            ->and($drain->requests)->toBe(3)
            ->and(buffered())->toBe([]);
    });

    it('holds at most 400 KB of JSON before compression', function () {
        fakeServer();
        fillBuffer(100, ['message' => str_repeat('x', 10_000)]);

        app(Sender::class)->drain();

        $envelopes = sentEnvelopes();

        expect(count($envelopes))->toBeGreaterThan(2);

        foreach (Http::recorded() as [$request]) {
            expect(strlen((string) gzdecode($request->body())))->toBeLessThanOrEqual(400 * 1024);
        }

        expect(array_sum(array_map(fn (array $envelope) => count($envelope['items']), $envelopes)))->toBe(100);
    });

    it('makes at most five requests per run and leaves the rest for the next', function () {
        fakeServer();
        fillBuffer(3000);

        $drain = app(Sender::class)->drain();

        expect($drain->requests)->toBe(5)
            ->and(buffered())->toHaveCount(500)
            ->and($drain->remaining)->toBe(500);

        app(Sender::class)->drain();

        expect(buffered())->toBe([]);
    });

    it('sends the oldest items first', function () {
        fakeServer();
        fillBuffer(600);

        app(Sender::class)->drain();

        $envelopes = sentEnvelopes();

        expect($envelopes[0]['items'][0]['id'])->toBe('item-1')
            ->and($envelopes[1]['items'][0]['id'])->toBe('item-501');
    });

    it('sends an item with text that is not valid UTF-8 instead of failing the batch', function () {
        fakeServer();
        fillBuffer(2, ['message' => "broken \xff text"]);

        app(Sender::class)->drain();

        expect(buffered())->toBe([])
            ->and(sentEnvelopes()[0]['items'][0]['message'])->toBe("broken \u{FFFD} text");
    });
});

it('survives a cache that is down', function () {
    fakeServer();
    useStore('down');

    $drain = app(Sender::class)->drain();

    Http::assertNothingSent();
    expect($drain->requests)->toBe(0);
});

it('keeps the key out of what it logs', function () {
    Log::spy();
    fakeServer(Http::response(['error' => 'invalid_key'], 401));
    fillBuffer(1);

    app(Sender::class)->drain();

    Log::shouldNotHaveReceived('info');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => ! str_contains($message.json_encode($context), config('sidetower.key')));
});
