<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Sidetower\Laravel\Buffer;
use Sidetower\Laravel\Health\HealthReport;
use Sidetower\Laravel\Release;

require_once __DIR__.'/../Support/helpers.php';

function sdkHealthReport(): array
{
    return app(HealthReport::class)->build();
}

function sdkCreateFailedJobsTable(): void
{
    Schema::create('failed_jobs', function (Blueprint $table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-09T13:40:00Z');
    config()->set('queue.failed.database', config('database.default'));
});

describe('the report', function () {
    it('has the shape of the contract', function () {
        $report = sdkHealthReport();

        expect($report)->toHaveKeys(['schema', 'app', 'generatedAt', 'runtime', 'system', 'database', 'cache', 'queue', 'scheduler', 'errors'])
            ->and($report['schema'])->toBe(1)
            ->and($report['generatedAt'])->toBe('2026-10-09T13:40:00Z')
            ->and($report['runtime'])->toHaveKeys(['php', 'laravel', 'environment', 'commit'])
            ->and($report['runtime']['php'])->toBe(PHP_VERSION)
            ->and($report['runtime']['laravel'])->toBe(app()->version())
            ->and($report['runtime']['environment'])->toBe('testing')
            ->and($report['system'])->toHaveKeys(['hostname', 'cpuCount', 'load', 'memory', 'disk', 'uptimeSeconds'])
            ->and($report['system']['hostname'])->toBe(gethostname())
            ->and($report['system']['memory'])->toHaveKeys(['totalBytes', 'availableBytes'])
            ->and($report['system']['disk']['path'])->toBe(base_path())
            ->and($report['database'])->toMatchArray(['ok' => true, 'driver' => 'sqlite'])
            ->and($report['database']['latencyMs'])->toBeFloat()
            ->and($report['cache'])->toBe(['ok' => true, 'driver' => 'array'])
            ->and($report['queue']['connection'])->toBe('sync')
            ->and($report['errors'])->toBe([]);
    });

    it('does not carry AI costs: those come as ai.usage items', function () {
        expect(sdkHealthReport())->not->toHaveKey('aiCosts');
    });

    it('is named after the application', function () {
        config()->set('app.name', 'Koreda');

        expect(sdkHealthReport()['app'])->toBe('Koreda');
    });

    it('writes the system numbers from /proc where it can', function () {
        $system = sdkHealthReport()['system'];

        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('/proc is Linux only');
        }

        expect($system['load'])->toHaveCount(3)
            ->and($system['memory']['totalBytes'])->toBeInt()->toBeGreaterThan(0)
            ->and($system['uptimeSeconds'])->toBeInt()
            ->and($system['disk']['totalBytes'])->toBeInt()
            ->and($system['disk']['freeBytes'])->toBeInt();
    });

    it('encodes as JSON the way the panel reads it', function () {
        $json = json_decode(json_encode(sdkHealthReport()));

        expect($json->errors)->toBe([])
            ->and($json->queue->pending)->toBeObject()
            ->and($json->queue->pending->default)->toBe(0);
    });

    it('is a cache probe that leaves nothing behind', function () {
        sdkHealthReport();

        expect(Cache::get('sidetower:health-probe'))->toBeNull();
    });
});

describe('the queue section', function () {
    it('counts failed jobs of the last 24 hours and in total', function () {
        sdkCreateFailedJobsTable();

        foreach ([now()->subHours(2), now()->subDays(3)] as $failedAt) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => '{}',
                'exception' => 'boom',
                'failed_at' => $failedAt,
            ]);
        }

        expect(sdkHealthReport()['queue'])->toMatchArray(['failedLast24h' => 1, 'failedTotal' => 2]);
    });

    it('reports failed jobs as unknown without a failed_jobs table', function () {
        expect(sdkHealthReport()['queue'])->toMatchArray(['failedLast24h' => null, 'failedTotal' => null]);
    });

    it('reports failed jobs as unknown when they are not kept in a database', function () {
        config()->set('queue.failed', ['driver' => 'null']);

        expect(sdkHealthReport()['queue'])->toMatchArray(['failedLast24h' => null, 'failedTotal' => null]);
    });

    it('counts the queues named in health.queues', function () {
        config()->set('sidetower.health.queues', ['emails', 'exports']);

        expect(sdkHealthReport()['queue']['pending'])->toBe(['emails' => 0, 'exports' => 0]);
    });

    it('reads the queues of the Horizon supervisors when none are named', function () {
        config()->set('horizon.defaults', [
            'supervisor-1' => ['connection' => 'redis', 'queue' => ['default', 'emails']],
            'supervisor-2' => ['queue' => 'notifications,default'],
        ]);

        expect(app(HealthReport::class)->queueNames())->toBe(['default', 'emails', 'notifications']);
    });

    it('adds the supervisors of this environment', function () {
        app()['env'] = 'production';
        config()->set('horizon.defaults', ['supervisor-1' => ['queue' => ['default']]]);
        config()->set('horizon.environments', [
            'production' => ['supervisor-1' => ['maxProcesses' => 10], 'supervisor-ai' => ['queue' => ['ai']]],
            'local' => ['supervisor-local' => ['queue' => ['local-only']]],
        ]);

        expect(app(HealthReport::class)->queueNames())->toBe(['default', 'ai']);
    });

    it('prefers the queues that are named over Horizon', function () {
        config()->set('sidetower.health.queues', ['mine']);
        config()->set('horizon.defaults', ['supervisor-1' => ['queue' => ['default']]]);

        expect(app(HealthReport::class)->queueNames())->toBe(['mine']);
    });

    it('falls back to the default queue', function (mixed $queues) {
        config()->set('sidetower.health.queues', $queues);

        expect(app(HealthReport::class)->queueNames())->toBe(['default']);
    })->with([[null], [[]], ['']]);
});

describe('a section that fails', function () {
    it('becomes null with its message in the errors list, and the rest is unharmed', function () {
        config()->set('queue.default', 'missing-connection');

        $report = sdkHealthReport();

        expect($report['queue'])->toBeNull()
            ->and($report['database']['ok'])->toBeTrue()
            ->and($report['errors'])->toHaveCount(1)
            ->and($report['errors'][0])->toStartWith('queue: ');
    });

    it('reports an unreachable database as not ok rather than as a missing section', function () {
        config()->set('database.connections.unreachable', ['driver' => 'sqlite', 'database' => '/nonexistent/sidetower.sqlite', 'prefix' => '']);
        config()->set('database.default', 'unreachable');

        $report = sdkHealthReport();

        expect($report['database'])->toBe(['ok' => false, 'latencyMs' => null, 'driver' => 'sqlite'])
            ->and(collect($report['errors'])->contains(fn (string $error) => str_starts_with($error, 'database: ')))->toBeTrue();
    });

    it('cuts a long message to 200 characters and an ellipsis', function () {
        config()->set('queue.default', str_repeat('x', 500));

        expect(mb_strlen(sdkHealthReport()['errors'][0]))->toBeLessThanOrEqual(strlen('queue: ') + 203);
    });
});

describe('the scheduler section', function () {
    it('has no last run until sidetower:send ran', function () {
        expect(sdkHealthReport()['scheduler'])->toBe(['lastRunAt' => null]);
    });

    it('shows when sidetower:send ran last', function () {
        sdkFakeServer();
        config()->set('sidetower.health.enabled', false);

        $this->artisan('sidetower:send')->assertSuccessful();

        Carbon::setTestNow('2026-10-09T13:41:20Z');

        expect(sdkHealthReport()['scheduler'])->toBe(['lastRunAt' => '2026-10-09T13:40:00Z']);
    });
});

describe('the size', function () {
    it('fits an item: 16 KB with a very long disk path and a thousand queues', function () {
        app()->setBasePath('/'.str_repeat('deep/', 4000));
        config()->set('sidetower.health.queues', array_map(fn (int $number) => 'queue-with-a-long-name-'.$number, range(1, 1000)));

        $report = sdkHealthReport();
        $item = ['type' => 'health.snapshot', 'id' => sdk()->newId(), 'ts' => sdk()->timestamp(), 'report' => $report];

        expect(strlen(json_encode($item)))->toBeLessThanOrEqual(16 * 1024)
            ->and(strlen($report['system']['disk']['path']))->toBeLessThanOrEqual(120)
            ->and($report['queue']['pending'])->not->toBeEmpty();
    });

    it('keeps at most ten lines of errors', function () {
        $report = new class(app(Buffer::class), app(Release::class)) extends HealthReport
        {
            public function fitting(array $report): array
            {
                return $this->fit($report);
            }
        };

        $fitted = $report->fitting(['errors' => array_map(fn (int $n) => "line {$n}", range(1, 25))]);

        expect($fitted['errors'])->toHaveCount(10);
    });

    it('leaves a small report alone', function () {
        $report = sdkHealthReport();

        expect($report['system']['disk']['path'])->toBe(base_path());
    });
});

describe('the snapshot item', function () {
    it('is recorded by every run when health.enabled is on', function () {
        sdkFakeServer();

        $this->artisan('sidetower:send')->assertSuccessful();

        $items = sdkSentEnvelopes()[0]['items'];

        expect($items[0]['type'])->toBe('health.snapshot')
            ->and($items[0]['report']['schema'])->toBe(1)
            ->and($items[0]['report']['scheduler']['lastRunAt'])->toBe('2026-10-09T13:40:00Z')
            ->and($items[1]['monitor'])->toBe('scheduler');
    });

    it('is left out when health.enabled is off', function () {
        sdkFakeServer();
        config()->set('sidetower.health.enabled', false);

        $this->artisan('sidetower:send')->assertSuccessful();

        expect(collect(sdkSentEnvelopes()[0]['items'])->pluck('type')->all())->toBe(['checkin']);
    });

    it('is not lost with the rest when the report cannot be built', function () {
        sdkFakeServer();
        config()->set('sidetower.health.queues', [new stdClass]);

        $this->artisan('sidetower:send')->assertSuccessful();

        Http::assertSentCount(1);
    });
});
