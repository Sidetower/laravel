<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Sidetower\Laravel\Facades\Sidetower;

require_once __DIR__.'/../Support/helpers.php';

/**
 * Runs the command; the output comes back on one line, since the console wraps long messages.
 *
 * @return array{0: int, 1: string}
 */
function sdkRunTestCommand(): array
{
    $code = Artisan::call('sidetower:test');

    return [$code, preg_replace('/\s+/', ' ', Artisan::output())];
}

it('sends one check-in named sidetower:test and says it was accepted', function () {
    sdkFakeServer();

    [$code, $output] = sdkRunTestCommand();

    expect($code)->toBe(0)
        ->and($output)->toContain("Sending a check-in to https://sidetower.test/v1/ingest as environment 'testing'")
        ->and($output)->toContain('Accepted: Sidetower received the check-in');

    Http::assertSentCount(1);

    $items = sdkSentEnvelopes()[0]['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray(['type' => 'checkin', 'monitor' => 'sidetower:test', 'status' => 'ok'])
        ->and($items[0])->not->toHaveKey('expectedIntervalS')
        ->and(sdkBuffered())->toBe([]);
});

it('says the check-in was rejected, with the error code', function () {
    sdkFakeServer(Http::response(['accepted' => 0, 'rejected' => [['index' => 0, 'error' => 'invalid_item']]], 202));

    [$code, $output] = sdkRunTestCommand();

    expect($code)->toBe(1)
        ->and($output)->toContain('Rejected: the server refused the check-in as invalid_item');

    expect(sdkBuffered())->toBe([]);
});

it('says the request was refused, and what to check', function (int $status, string $error, string $hint) {
    sdkFakeServer(Http::response(['error' => $error, 'message' => 'x'], $status));

    [$code, $output] = sdkRunTestCommand();

    expect($code)->toBe(1)
        ->and($output)->toContain("Refused: the server answered HTTP {$status} ({$error})")
        ->and($output)->toContain($hint);
})->with([
    'a wrong key' => [401, 'invalid_key', 'Check SIDETOWER_KEY'],
    'a key of another environment' => [400, 'environment_mismatch', "another environment than 'testing'"],
    'an archived project' => [403, 'project_archived', 'archived'],
    'a protocol the server does not read' => [400, 'unsupported_protocol', 'update the package'],
]);

it('says the server could not be reached, and leaves nothing in the buffer', function (string $message) {
    Http::fake(fn () => throw new ConnectionException($message));

    [$code, $output] = sdkRunTestCommand();

    expect($code)->toBe(1)
        ->and($output)->toContain('Could not reach Sidetower: '.$message)
        ->and(sdkBuffered())->toBe([]);
})->with([
    'timeout' => 'cURL error 28: Operation timed out after 2001 milliseconds',
    'connection refused' => 'cURL error 7: Failed to connect',
]);

it('says so when the server answers with an error of its own', function () {
    sdkFakeServer(Http::response('Bad gateway', 502));

    [$code, $output] = sdkRunTestCommand();

    expect($code)->toBe(1)
        ->and($output)->toContain('Could not reach Sidetower: the server answered HTTP 502');
});

it('says why the SDK is off', function (Closure $configure, string $reason) {
    sdkFakeServer();
    $configure();

    [$code, $output] = sdkRunTestCommand();

    expect($code)->toBe(1)
        ->and($output)->toContain('Sidetower is off:')
        ->and($output)->toContain($reason);

    Http::assertNothingSent();
})->with([
    'no key' => [fn () => config()->set('sidetower.key', null), 'SIDETOWER_KEY is not set'],
    'no endpoint' => [fn () => config()->set('sidetower.endpoint', ''), 'SIDETOWER_ENDPOINT is not set'],
    'switched off' => [fn () => config()->set('sidetower.enabled', false), 'SIDETOWER_ENABLED=false'],
    'tests' => [fn () => config()->set('sidetower.enabled', null), 'SIDETOWER_ENABLED=true'],
    'a bad environment' => [fn () => config()->set('sidetower.environment', 'Prod_1'), 'SIDETOWER_ENVIRONMENT'],
]);

it('sends the items that were waiting along with it', function () {
    sdkFakeServer();
    sdkFillBuffer(2);

    [$code, $output] = sdkRunTestCommand();

    expect($code)->toBe(0)
        ->and($output)->toContain('Accepted');

    expect(array_column(sdkSentEnvelopes()[0]['items'], 'monitor'))->toBe(['monitor-1', 'monitor-2', 'sidetower:test'])
        ->and(sdkBuffered())->toBe([]);
});

it('says when the check-in waits behind a backlog it could not get through in one run', function () {
    sdkFakeServer();
    sdkFillBuffer(2600);

    [$code, $output] = sdkRunTestCommand();

    expect($code)->toBe(1)
        ->and($output)->toContain('waits behind other items');

    expect(sdkBuffered('checkin'))->not->toBeEmpty()
        ->and(collect(sdkBuffered())->contains(fn (array $item) => $item['monitor'] === 'sidetower:test'))->toBeFalse();
});

it('says unreachable, not backlog, when the server fails before it gets to the check-in', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));
    sdkFillBuffer(1200);

    [$code, $output] = sdkRunTestCommand();

    expect($code)->toBe(1)
        ->and($output)->toContain('Could not reach Sidetower: cURL error 28');
});

it('works under the fake without sending', function () {
    Http::fake();
    Sidetower::fake();

    $this->artisan('sidetower:test');

    Http::assertNothingSent();
    Sidetower::assertRecorded('checkin', fn (array $item) => $item['monitor'] === 'sidetower:test');
});

it('sends with the key as bearer token to the endpoint', function () {
    sdkFakeServer();

    $this->artisan('sidetower:test');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://sidetower.test/v1/ingest'
        && $request->header('Authorization') === ['Bearer sk_test0000000000000000000000000000']);
});

it('says the check-in was accepted when the server first found the batch too large and then took its halves', function () {
    sdkFakeServer(Http::sequence()
        ->push(['error' => 'payload_too_large', 'message' => 'x'], 413)
        ->push(['accepted' => 2, 'rejected' => []], 202)
        ->push(['accepted' => 1, 'rejected' => []], 202));
    sdkFillBuffer(3);

    [$code, $output] = sdkRunTestCommand();

    expect($code)->toBe(0)
        ->and($output)->toContain('Accepted');
});

it('warns when the buffer is in a store that forgets between processes', function () {
    sdkFakeServer();

    [, $output] = sdkRunTestCommand();

    expect($output)->toContain('does not keep items between processes');
});
