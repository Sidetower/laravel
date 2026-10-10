<?php

use Carbon\CarbonInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Sidetower\Laravel\Import\HistorySource;
use Sidetower\Laravel\Sidetower;
use Sidetower\Laravel\Tests\Import\Support\ArrayHistorySource;
use Sidetower\Laravel\Tests\Import\Support\UsageEventsSource;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    Carbon::setTestNow('2026-10-09T18:00:00Z');
});

/**
 * @return list<array<string, mixed>>
 */
function historyItems(int $count, string $prefix = 'a', array $extra = []): array
{
    return array_map(fn (int $i): array => [
        'id' => "hist-{$prefix}-t-{$i}",
        'ts' => '2026-09-01 10:00:00',
        'operation' => 'story.generate',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'status' => 'ok',
        'inputTokens' => 100 + $i,
        'outputTokens' => 20,
        ...$extra,
    ], range(1, $count));
}

/**
 * The decoded bodies of the requests sent to the import endpoint, oldest first.
 *
 * @return list<array<string, mixed>>
 */
function sentBatches(): array
{
    return Http::recorded()
        ->map(fn (array $pair) => json_decode(gzdecode($pair[0]->body()), true))
        ->values()
        ->all();
}

function acceptEverything(): void
{
    Http::fake(fn (Request $request) => Http::response([
        'accepted' => count(json_decode(gzdecode($request->body()), true)['items']),
        'rejected' => [],
    ], 202));
}

it('sends the items of a source in a gzipped envelope to the import endpoint', function () {
    ArrayHistorySource::register('penguess', historyItems(3));
    Http::fake(function (Request $request, array $options) {
        expect($options['timeout'])->toBe(30);

        return Http::response(['accepted' => 3, 'rejected' => []], 202);
    });

    $this->artisan('sidetower:import-history')
        ->expectsOutputToContain('penguess: sent 3 in 1 batches, accepted 3, rejected 0.')
        ->assertExitCode(0);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $body = json_decode(gzdecode($request->body()), true);

        return $request->url() === 'https://sidetower.test/v1/import'
            && $request->method() === 'POST'
            && $request->header('Authorization') === ['Bearer sk_test0000000000000000000000000000']
            && $request->header('Content-Encoding') === ['gzip']
            && $request->header('Content-Type') === ['application/json']
            && $body['protocol'] === 1
            && $body['sdk'] === ['name' => 'sidetower-laravel', 'version' => Sidetower::VERSION]
            && $body['source']['environment'] === 'testing'
            && is_string($body['source']['host'])
            && $body['sentAt'] === '2026-10-09T18:00:00.000Z'
            && count($body['items']) === 3;
    });
});

it('sends items the protocol accepts, marked as imported', function () {
    ArrayHistorySource::register('penguess', [[
        'id' => 'hist-penguess-t-1',
        'ts' => Carbon::parse('2026-09-01 10:15:30.250', 'Europe/Istanbul'),
        'operation' => 'story.generate',
        'provider' => 'openrouter',
        'model' => 'google/gemini-2.5-flash',
        'status' => 'ok',
        'inputTokens' => '1200',
        'outputTokens' => 640.0,
        'reportedCostUsd' => '0.00184',
        'attributes' => ['variant' => 'b', 'imported' => false],
        'prompt' => 'never sent',
    ]]);
    acceptEverything();

    $this->artisan('sidetower:import-history')->assertExitCode(0);

    expect(sentBatches()[0]['items'])->toBe([[
        'type' => 'ai.usage',
        'id' => 'hist-penguess-t-1',
        'ts' => '2026-09-01T07:15:30.250Z',
        'operation' => 'story.generate',
        'provider' => 'openrouter',
        'model' => 'google/gemini-2.5-flash',
        'status' => 'ok',
        'inputTokens' => 1200,
        'outputTokens' => 640,
        'reportedCostUsd' => 0.00184,
        'attributes' => ['imported' => true, 'variant' => 'b'],
    ]]);
});

it('derives the import endpoint from the ingest endpoint', function (string $endpoint, string $import) {
    config()->set('sidetower.endpoint', $endpoint);
    ArrayHistorySource::register('s', historyItems(1));
    acceptEverything();

    $this->artisan('sidetower:import-history')->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request->url() === $import);
})->with([
    'plain' => ['https://sidetower.test/v1/ingest', 'https://sidetower.test/v1/import'],
    'trailing slash' => ['https://sidetower.test/v1/ingest/', 'https://sidetower.test/v1/import'],
    'worker host' => ['https://ingest.example.workers.dev/v1/ingest', 'https://ingest.example.workers.dev/v1/import'],
    'ingest in the host stays' => ['https://ingest.example.com/v1/ingest', 'https://ingest.example.com/v1/import'],
]);

it('refuses to guess the import address of an endpoint that does not end in /ingest', function () {
    config()->set('sidetower.endpoint', 'https://sidetower.test/collect');
    ArrayHistorySource::register('s', historyItems(1));
    Http::fake();

    $this->artisan('sidetower:import-history')
        ->expectsOutputToContain('does not end in /ingest')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('sends 500 items to a batch', function () {
    ArrayHistorySource::register('big', historyItems(1203));
    acceptEverything();

    $this->artisan('sidetower:import-history')
        ->expectsOutputToContain('big: sent 1203 in 3 batches, accepted 1203, rejected 0.')
        ->assertExitCode(0);

    $batches = sentBatches();

    expect(array_map(fn (array $batch) => count($batch['items']), $batches))->toBe([500, 500, 203])
        ->and(array_column(array_merge(...array_column($batches, 'items')), 'id'))->toBe(array_column(historyItems(1203), 'id'));
});

it('sends exactly 500 items in one batch', function () {
    ArrayHistorySource::register('exact', historyItems(500));
    acceptEverything();

    $this->artisan('sidetower:import-history')->assertExitCode(0);

    Http::assertSentCount(1);
});

it('keeps a batch under the size the server takes, whatever the items weigh', function () {
    $heavy = array_fill_keys(array_map(fn (int $i) => "attribute{$i}", range(1, 20)), str_repeat('x', 200));
    ArrayHistorySource::register('heavy', historyItems(300, 'h', ['attributes' => $heavy]));
    acceptEverything();

    $this->artisan('sidetower:import-history')->assertExitCode(0);

    $batches = sentBatches();

    expect(count($batches))->toBeGreaterThan(2)
        ->and(array_sum(array_map(fn (array $batch) => count($batch['items']), $batches)))->toBe(300);

    foreach ($batches as $batch) {
        expect(strlen(json_encode($batch['items'])))->toBeLessThanOrEqual(400_000)
            ->and(count($batch['items']))->toBeLessThanOrEqual(500);
    }
});

it('imports every source and prints its accepted and rejected counts', function () {
    ArrayHistorySource::register('one', historyItems(2, 'one'));
    ArrayHistorySource::register('two', historyItems(3, 'two'));
    Http::fakeSequence()
        ->push(['accepted' => 2, 'rejected' => []], 202)
        ->push(['accepted' => 1, 'rejected' => [['index' => 0, 'error' => 'stale_item'], ['index' => 2, 'error' => 'invalid_item']]], 202);

    $this->artisan('sidetower:import-history')
        ->expectsOutputToContain('one: sent 2 in 1 batches, accepted 2, rejected 0.')
        ->expectsOutputToContain('two: sent 3 in 1 batches, accepted 1, rejected 2 (stale_item: 1, invalid_item: 1).')
        ->assertExitCode(0);
});

it('adds up the rejections of all the batches of a source', function () {
    ArrayHistorySource::register('big', historyItems(600));
    Http::fakeSequence()
        ->push(['accepted' => 499, 'rejected' => [['index' => 3, 'error' => 'stale_item']]], 202)
        ->push(['accepted' => 98, 'rejected' => [['index' => 1, 'error' => 'stale_item'], ['index' => 2, 'error' => 'stale_item']]], 202);

    $this->artisan('sidetower:import-history')
        ->expectsOutputToContain('big: sent 600 in 2 batches, accepted 597, rejected 3 (stale_item: 3).')
        ->assertExitCode(0);
});

it('skips items without a valid id or time and says so', function () {
    ArrayHistorySource::register('messy', [
        ...historyItems(2),
        ['id' => 'no time', 'operation' => 'x', 'provider' => 'p', 'model' => 'm'],
        ['ts' => '2026-09-01 10:00:00', 'operation' => 'x', 'provider' => 'p', 'model' => 'm'],
        ['id' => 'hist-bad-ts', 'ts' => 'last tuesday-ish??', 'operation' => 'x', 'provider' => 'p', 'model' => 'm'],
    ]);
    acceptEverything();

    $this->artisan('sidetower:import-history')
        ->expectsOutputToContain('messy: sent 2 in 1 batches, accepted 2, rejected 0, skipped 3 without a valid id or time.')
        ->assertExitCode(0);
});

it('asks sources for the last 400 days unless told otherwise', function () {
    $source = ArrayHistorySource::register('s', historyItems(1));
    acceptEverything();

    $this->artisan('sidetower:import-history')->assertExitCode(0);

    expect($source->askedSince->toIso8601String())->toBe(Carbon::now()->subDays(400)->toIso8601String());

    $this->artisan('sidetower:import-history', ['--since' => '2026-03-01'])->assertExitCode(0);

    expect($source->askedSince->toDateString())->toBe('2026-03-01');
});

it('accepts a relative date for --since', function () {
    $source = ArrayHistorySource::register('s', historyItems(1));
    acceptEverything();

    $this->artisan('sidetower:import-history', ['--since' => '30 days ago'])->assertExitCode(0);

    expect($source->askedSince->toDateString())->toBe('2026-09-09');
});

it('leaves out the items from --until on and says how many', function () {
    $source = ArrayHistorySource::register('s', [
        ...historyItems(2, 'before'),
        ...historyItems(3, 'after', ['ts' => '2026-10-01 12:00:00']),
        ...historyItems(1, 'edge', ['ts' => '2026-10-01 09:00:00']),
    ]);
    acceptEverything();

    $this->artisan('sidetower:import-history', ['--since' => '2026-08-01', '--until' => '2026-10-01 09:00:00'])
        ->expectsOutputToContain('Importing usage since 2026-08-01 00:00:00, before 2026-10-01 09:00:00.')
        ->expectsOutputToContain('s: sent 2 in 1 batches, accepted 2, rejected 0, left out 4 from --until on.')
        ->assertExitCode(0);

    expect(collect(sentBatches()[0]['items'])->pluck('id')->all())->toBe(['hist-before-t-1', 'hist-before-t-2'])
        ->and($source->askedSince->toDateString())->toBe('2026-08-01');
});

it('refuses an --until that is not a date, or not after --since', function () {
    ArrayHistorySource::register('s', historyItems(1));
    Http::fake();

    $this->artisan('sidetower:import-history', ['--until' => 'soonish'])
        ->expectsOutputToContain('--until=soonish is not a date.')
        ->assertExitCode(1);

    $this->artisan('sidetower:import-history', ['--since' => '2026-09-01', '--until' => '2026-09-01'])
        ->expectsOutputToContain('--until must be later than --since.')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('refuses a --since that is not a date', function () {
    ArrayHistorySource::register('s', historyItems(1));
    Http::fake();

    $this->artisan('sidetower:import-history', ['--since' => 'whenever'])
        ->expectsOutputToContain('--since=whenever is not a date.')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('counts and shows the first item on a dry run, and sends nothing', function () {
    ArrayHistorySource::register('one', historyItems(1203, 'one'));
    ArrayHistorySource::register('two', historyItems(2, 'two'));
    Http::fake();

    $this->artisan('sidetower:import-history', ['--dry-run' => true])
        ->expectsOutputToContain('one: 1203 items in about 3 batches.')
        ->expectsOutputToContain('"id": "hist-one-t-1"')
        ->expectsOutputToContain('two: 2 items in about 1 batches.')
        ->expectsOutputToContain('"id": "hist-two-t-1"')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('runs a dry run without a key', function () {
    config()->set('sidetower.key', null);
    ArrayHistorySource::register('one', historyItems(2));
    Http::fake();

    $this->artisan('sidetower:import-history', ['--dry-run' => true])
        ->expectsOutputToContain('one: 2 items')
        ->assertExitCode(0);
});

it('imports the rows of a table with their cost, and a second run sends the same ids', function () {
    Schema::create('ai_usage_events', function (Blueprint $table) {
        $table->id();
        $table->string('operation');
        $table->string('provider');
        $table->string('model');
        $table->boolean('failed')->default(false);
        $table->unsignedInteger('input_tokens')->nullable();
        $table->unsignedInteger('output_tokens')->nullable();
        $table->unsignedInteger('cached_tokens')->nullable();
        $table->decimal('cost_usd', 12, 6)->nullable();
        $table->unsignedInteger('duration_ms')->nullable();
        $table->string('variant')->nullable();
        $table->timestamp('created_at')->nullable();
    });
    DB::table('ai_usage_events')->insert([
        ['operation' => 'story.generate', 'provider' => 'openrouter', 'model' => 'google/gemini-2.5-flash', 'failed' => false, 'input_tokens' => 1200, 'output_tokens' => 640, 'cached_tokens' => 800, 'cost_usd' => '0.001840', 'duration_ms' => 2310, 'variant' => 'b', 'created_at' => '2026-09-01 10:15:30'],
        ['operation' => 'story.generate', 'provider' => 'openrouter', 'model' => 'google/gemini-2.5-flash', 'failed' => true, 'input_tokens' => null, 'output_tokens' => null, 'cached_tokens' => null, 'cost_usd' => null, 'duration_ms' => 30000, 'variant' => null, 'created_at' => '2026-09-02 10:15:30'],
    ]);
    config()->set('sidetower.ai.history_sources', [UsageEventsSource::class]);
    acceptEverything();

    $this->artisan('sidetower:import-history')->expectsOutputToContain('usage-events: sent 2')->assertExitCode(0);
    $this->artisan('sidetower:import-history')->assertExitCode(0);

    [$first, $second] = sentBatches();

    expect($first['items'][0])->toBe([
        'type' => 'ai.usage',
        'id' => 'hist-usage-events-ai_usage_events-1',
        'ts' => '2026-09-01T10:15:30.000Z',
        'operation' => 'story.generate',
        'provider' => 'openrouter',
        'model' => 'google/gemini-2.5-flash',
        'status' => 'ok',
        'inputTokens' => 1200,
        'outputTokens' => 640,
        'cacheReadTokens' => 800,
        'durationMs' => 2310,
        'reportedCostUsd' => 0.00184,
        'attributes' => ['imported' => true, 'variant' => 'b'],
    ])
        ->and($first['items'][1])->toMatchArray(['id' => 'hist-usage-events-ai_usage_events-2', 'status' => 'error', 'durationMs' => 30000])
        ->and($first['items'][1])->not->toHaveKeys(['inputTokens', 'reportedCostUsd'])
        ->and(array_column($second['items'], 'id'))->toBe(array_column($first['items'], 'id'));
});

it('uses the configured environment, or the application one, in lower case', function (?string $configured, string $expected) {
    config()->set('sidetower.environment', $configured);
    $this->app['env'] = 'Staging';
    ArrayHistorySource::register('s', historyItems(1));
    acceptEverything();

    $this->artisan('sidetower:import-history')->assertExitCode(0);

    expect(sentBatches()[0]['source']['environment'])->toBe($expected);
})->with([
    'application environment' => [null, 'staging'],
    'configured' => ['Production', 'production'],
]);

it('refuses an environment name the protocol does not accept', function () {
    config()->set('sidetower.environment', 'my_env!');
    ArrayHistorySource::register('s', historyItems(1));
    Http::fake();

    $this->artisan('sidetower:import-history')->expectsOutputToContain('environment is not one the protocol accepts')->assertExitCode(1);

    Http::assertNothingSent();
});

it('refuses to run while the SDK is off', function () {
    config()->set('sidetower.key', null);
    ArrayHistorySource::register('s', historyItems(1));
    Http::fake();

    $this->artisan('sidetower:import-history')->expectsOutputToContain('Sidetower is off')->assertExitCode(1);

    Http::assertNothingSent();
});

it('says so when no source is configured', function () {
    Http::fake();

    $this->artisan('sidetower:import-history')->expectsOutputToContain('No history sources')->assertExitCode(0);

    Http::assertNothingSent();
});

it('refuses a source that is not a history source', function () {
    config()->set('sidetower.ai.history_sources', [stdClass::class]);
    Http::fake();

    $this->artisan('sidetower:import-history')->expectsOutputToContain('does not implement')->assertExitCode(1);

    Http::assertNothingSent();
});

it('refuses a source class that does not exist', function () {
    config()->set('sidetower.ai.history_sources', ['App\\Missing\\History']);
    Http::fake();

    $this->artisan('sidetower:import-history')->expectsOutputToContain('could not be made')->assertExitCode(1);
});

it('stops with the reason when the source throws', function () {
    $source = new class implements HistorySource
    {
        public function name(): string
        {
            return 'broken';
        }

        public function items(CarbonInterface $since): iterable
        {
            yield from historyItems(2);

            throw new RuntimeException('table is gone');
        }
    };
    app()->instance('history.broken', $source);
    config()->set('sidetower.ai.history_sources', ['history.broken']);
    Http::fake();

    $this->artisan('sidetower:import-history')->expectsOutputToContain('broken: stopped. The source failed: table is gone')->assertExitCode(1);
});

it('stops at a refusal that retrying cannot fix', function (int $status, string $code) {
    ArrayHistorySource::register('big', historyItems(600));
    Http::fake(['*' => Http::response(['error' => $code, 'message' => 'x'], $status)]);

    $this->artisan('sidetower:import-history')
        ->expectsOutputToContain("big: stopped. The server answered {$status} ({$code}).")
        ->expectsOutputToContain('nothing is counted twice')
        ->assertExitCode(1);

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
})->with([
    'bad key' => [401, 'invalid_key'],
    'client key' => [403, 'key_not_allowed'],
    'wrong environment' => [400, 'environment_mismatch'],
    'old protocol' => [400, 'unsupported_protocol'],
]);

it('tries a busy server three times, waiting between the tries', function () {
    ArrayHistorySource::register('s', historyItems(2));
    Http::fakeSequence()
        ->push(['error' => 'unavailable'], 503)
        ->push('', 502)
        ->push(['accepted' => 2, 'rejected' => []], 202);

    $this->artisan('sidetower:import-history')->expectsOutputToContain('accepted 2')->assertExitCode(0);

    Http::assertSentCount(3);
    Sleep::assertSequence([Sleep::for(1)->seconds(), Sleep::for(3)->seconds()]);
});

it('waits as long as a 429 asks, up to 30 seconds', function () {
    ArrayHistorySource::register('s', historyItems(1));
    Http::fakeSequence()
        ->push(['error' => 'rate_limited'], 429, ['Retry-After' => '7'])
        ->push(['error' => 'rate_limited'], 429, ['Retry-After' => '600'])
        ->push(['accepted' => 1, 'rejected' => []], 202);

    $this->artisan('sidetower:import-history')->assertExitCode(0);

    Sleep::assertSequence([Sleep::for(7)->seconds(), Sleep::for(30)->seconds()]);
});

it('gives up on a server that stays down', function () {
    ArrayHistorySource::register('s', historyItems(2));
    Http::fake(['*' => Http::response('', 500)]);

    $this->artisan('sidetower:import-history')
        ->expectsOutputToContain('s: stopped. The server answered 500.')
        ->assertExitCode(1);

    Http::assertSentCount(3);
});

it('tries a failed connection three times and then stops', function () {
    ArrayHistorySource::register('s', historyItems(2));
    Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);

    $this->artisan('sidetower:import-history')
        ->expectsOutputToContain('s: stopped. Could not reach https://sidetower.test/v1/import')
        ->assertExitCode(1);

    Http::assertSentCount(3);
});

it('splits a batch the server finds too large', function () {
    ArrayHistorySource::register('s', historyItems(5));
    Http::fake(function (Request $request) {
        $items = json_decode(gzdecode($request->body()), true)['items'];

        return count($items) > 2
            ? Http::response(['error' => 'payload_too_large'], 413)
            : Http::response(['accepted' => count($items), 'rejected' => [['index' => 0, 'error' => 'stale_item']]], 202);
    });

    $this->artisan('sidetower:import-history')
        ->expectsOutputToContain('s: sent 5 in 1 batches, accepted 5, rejected 3 (stale_item: 3).')
        ->assertExitCode(0);

    expect(array_map(fn (array $batch) => count($batch['items']), sentBatches()))->toBe([5, 3, 2, 1, 2]);
});

it('stops when a batch cannot be split any further', function () {
    ArrayHistorySource::register('s', historyItems(1));
    Http::fake(['*' => Http::response(['error' => 'payload_too_large'], 413)]);

    $this->artisan('sidetower:import-history')->expectsOutputToContain('413')->assertExitCode(1);

    Http::assertSentCount(1);
});

it('stops at the first source that fails', function () {
    ArrayHistorySource::register('one', historyItems(1, 'one'));
    ArrayHistorySource::register('two', historyItems(1, 'two'));
    Http::fake(['*' => Http::response(['error' => 'invalid_key'], 401)]);

    $this->artisan('sidetower:import-history')->assertExitCode(1);

    Http::assertSentCount(1);
});

it('does not follow a redirect with the key', function () {
    ArrayHistorySource::register('s', historyItems(1));
    Http::fake(['sidetower.test/*' => Http::response('', 308, ['Location' => 'https://elsewhere.example/v1/import'])]);

    $this->artisan('sidetower:import-history')
        ->expectsOutputToContain('s: stopped. The server answered 308.')
        ->assertExitCode(1);

    Http::assertSentCount(1);
});
