<?php

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Sidetower\Laravel\Buffer;
use Sidetower\Laravel\Scheduling\TaskCheckins;
use Sidetower\Laravel\Sender;
use Sidetower\Laravel\Sidetower;
use Sidetower\Laravel\Transport;

require_once __DIR__.'/../Support/helpers.php';

/*
 * The copies of the protocol's JSON Schema and fixtures (see sync.php) are the contract. Everything the SDK puts on the wire is
 * held against the schema; the valid fixtures are held against it too, which proves the schema copy and the checker work.
 */

const PROTOCOL_BASE = 'https://schema.sidetower.test/v1/';

function protocolValidator(): Validator
{
    static $validator = null;

    if ($validator === null) {
        $validator = new Validator;
        $validator->setMaxErrors(3);

        foreach (['common', 'item', 'envelope', 'import'] as $name) {
            $schema = json_decode(file_get_contents(__DIR__."/schema/v1/{$name}.json"));
            $schema->{'$id'} = PROTOCOL_BASE."{$name}.json";
            $validator->resolver()->registerRaw($schema);
        }
    }

    return $validator;
}

/**
 * Why a request does not fit the schema; null when it does.
 */
function schemaViolation(mixed $request, string $schema = 'envelope'): ?string
{
    $result = protocolValidator()->validate($request, PROTOCOL_BASE."{$schema}.json");

    return $result->isValid() ? null : json_encode((new ErrorFormatter)->format($result->error()), JSON_PRETTY_PRINT);
}

/**
 * The body of a request as the server would read it: gunzipped, with objects kept as objects.
 */
function wireBody(Request $request): object
{
    return json_decode((string) gzdecode($request->body()), flags: JSON_THROW_ON_ERROR);
}

/**
 * @return list<string>
 */
function fixtureFiles(string $directory): array
{
    $files = glob(__DIR__."/fixtures/v1/{$directory}/*.json");

    return $files === false ? [] : $files;
}

function fixtureDataset(string $directory): array
{
    $dataset = [];

    foreach (fixtureFiles($directory) as $file) {
        $dataset[basename($file, '.json')] = [$file];
    }

    return $dataset;
}

function expectEveryRequestToFit(): void
{
    Http::assertSent(function (Request $request) {
        expect(schemaViolation(wireBody($request)))->toBeNull();

        return true;
    });
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-09T18:00:00Z');
});

describe('the copies', function () {
    it('are there', function () {
        expect(fixtureFiles('valid'))->not->toBeEmpty()
            ->and(fixtureFiles('invalid'))->not->toBeEmpty()
            ->and(fixtureFiles('import/valid'))->not->toBeEmpty()
            ->and(is_file(__DIR__.'/schema/v1/envelope.json'))->toBeTrue();
    });

    it('hold fixtures that the schema accepts when they are valid', function (string $file) {
        $fixture = json_decode(file_get_contents($file));

        expect(schemaViolation($fixture->request))->toBeNull();
    })->with(fixtureDataset('valid'));

    it('hold import fixtures that the import schema accepts when they are valid', function (string $file) {
        $fixture = json_decode(file_get_contents($file));

        expect(schemaViolation($fixture->request, 'import'))->toBeNull();
    })->with(fixtureDataset('import/valid'));

    it('hold invalid fixtures that the schema rejects when the schema can tell', function (string $file) {
        $fixture = json_decode(file_get_contents($file));

        expect(schemaViolation($fixture->request))->not->toBeNull();
    })->with(array_filter(
        fixtureDataset('invalid'),
        fn (string $name) => preg_match('/^(invalid_envelope|invalid_item|unknown_type|unsupported_protocol|mixed)__/', $name) === 1,
        ARRAY_FILTER_USE_KEY,
    ));
});

describe('the limits in the code', function () {
    $schema = fn () => json_decode(file_get_contents(__DIR__.'/schema/v1/envelope.json'), true);
    $common = fn () => json_decode(file_get_contents(__DIR__.'/schema/v1/common.json'), true);
    $item = fn () => json_decode(file_get_contents(__DIR__.'/schema/v1/item.json'), true);

    it('send at most the items an envelope may hold', function () use ($schema) {
        expect(Transport::MAX_ITEMS)->toBe($schema()['properties']['items']['maxItems']);
    });

    it('keep a batch below the body limit of the server', function () {
        expect(Transport::MAX_BYTES)->toBeLessThan(512 * 1024);
    });

    it('use the protocol\'s environment pattern', function () use ($common) {
        expect(Sidetower::ENVIRONMENT_PATTERN)->toBe('/'.$common()['$defs']['environment']['pattern'].'/');
    });

    it('make ids the protocol accepts', function () use ($common) {
        $pattern = '/'.$common()['$defs']['id']['pattern'].'/';

        expect(preg_match($pattern, sidetower()->newId()))->toBe(1);
    });

    it('keep the expected interval inside the protocol\'s range', function () use ($item) {
        $interval = $item()['$defs']['checkin']['properties']['expectedIntervalS'];

        expect(TaskCheckins::MIN_INTERVAL_SECONDS)->toBe($interval['minimum'])
            ->and(TaskCheckins::MAX_INTERVAL_SECONDS)->toBe($interval['maximum']);
    });
});

describe('what the SDK sends', function () {
    beforeEach(function () {
        fakeServer();
    });

    it('is an envelope the schema accepts, for a run of sidetower:send', function () {
        checkout('ref: refs/heads/main', ['refs/heads/main' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678']);

        $this->artisan('sidetower:send')->assertSuccessful();

        Http::assertSentCount(1);
        expectEveryRequestToFit();

        $types = collect(wireBody(Http::recorded()->first()[0])->items)->pluck('type')->all();

        expect($types)->toBe(['health.snapshot', 'checkin', 'deploy']);
    });

    it('is an envelope the schema accepts, with every item type the SDK makes itself', function () {
        checkout('ref: refs/heads/feature/billing', ['refs/heads/feature/billing' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678']);
        config()->set('sidetower.release', '2026.10.09-1');
        Cache::put(Buffer::DROPPED, 7);

        $task = app(Schedule::class)->call(fn () => null)->name('nightly')->dailyAt('03:00');
        Event::dispatch(new ScheduledTaskStarting($task));
        Event::dispatch(new ScheduledTaskFinished($task, 0.5));
        Event::dispatch(new ScheduledTaskFailed($task, new RuntimeException("Disk full: \u{1F4BE} /var/www")));

        $this->artisan('sidetower:send')->assertSuccessful();

        expectEveryRequestToFit();

        $types = collect(Http::recorded())->flatMap(fn (array $pair) => wireBody($pair[0])->items)->pluck('type')->unique()->sort()->values()->all();

        expect($types)->toBe(['checkin', 'deploy', 'health.snapshot', 'metric']);
    });

    it('is an envelope the schema accepts, for a scheduled task check-in of each status', function () {
        $schedule = app(Schedule::class);
        $ok = $schedule->call(fn () => null)->name('ok-task')->everyFiveMinutes();
        $failing = $schedule->exec('exit 3')->description(str_repeat('é', 150))->hourly();

        $failing->exitCode = 3;

        foreach ([$ok, $failing] as $task) {
            Event::dispatch(new ScheduledTaskStarting($task));
            Event::dispatch(new ScheduledTaskFinished($task, 0.2));
        }

        $this->artisan('sidetower:test')->assertSuccessful();

        expectEveryRequestToFit();

        $monitors = collect(wireBody(Http::recorded()->first()[0])->items)->where('type', 'checkin')->pluck('monitor')->unique()->values()->all();

        $statuses = collect(wireBody(Http::recorded()->first()[0])->items)->where('type', 'checkin')->pluck('status')->unique()->sort()->values()->all();

        expect($monitors)->toContain('schedule:ok-task')->toContain('sidetower:test')
            ->and($statuses)->toBe(['error', 'ok', 'started']);
    });

    it('is an envelope the schema accepts, for every item of every valid fixture', function () {
        $items = [];

        foreach (fixtureFiles('valid') as $file) {
            $fixture = json_decode(file_get_contents($file), true);

            foreach ($fixture['request']['items'] as $item) {
                $items[] = $item;
            }
        }

        app(Buffer::class)->push($items);

        $drain = app(Sender::class)->drain();

        expectEveryRequestToFit();
        expect($drain->requests)->toBeGreaterThan(0)
            ->and($drain->sent)->toBe(count($items));
    });

    it('is an envelope the schema accepts when it holds 500 items', function () {
        fillBuffer(500);

        app(Sender::class)->drain();

        expectEveryRequestToFit();

        expect(count(wireBody(Http::recorded()->first()[0])->items))->toBe(500);
    });

    it('is an envelope the schema accepts without a release, and in another environment', function () {
        app()->setBasePath(sys_get_temp_dir());
        config()->set('sidetower.environment', 'staging-2');
        fillBuffer(1);

        app(Sender::class)->drain();

        expectEveryRequestToFit();

        $body = wireBody(Http::recorded()->first()[0]);

        expect($body->source)->not->toHaveProperty('release')
            ->and($body->source->environment)->toBe('staging-2')
            ->and($body)->not->toHaveProperty('user')
            ->and($body->sentAt)->toBe('2026-10-09T18:00:00.000Z');
    });

    it('has no optional field set to null', function () {
        app()->setBasePath(sys_get_temp_dir());

        $this->artisan('sidetower:send')->assertSuccessful();

        $json = (string) gzdecode(Http::recorded()->first()[0]->body());
        $body = json_decode($json, true);

        $nulls = [];
        array_walk_recursive($body['items'][1], function ($value, $key) use (&$nulls) {
            if ($value === null) {
                $nulls[] = $key;
            }
        });

        expect($nulls)->toBe([])
            ->and($body['source'])->not->toContain(null);
    });
});
