<?php

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event as Events;
use Sidetower\Laravel\Scheduling\TaskCheckins;

require_once __DIR__.'/../Support/helpers.php';

/**
 * The schedule without the SDK's own task, so that `schedule:run` does not start a second PHP process.
 */
function sdkEmptySchedule(): Schedule
{
    $schedule = app(Schedule::class);
    $events = new ReflectionProperty(Schedule::class, 'events');
    $events->setValue($schedule, []);

    return $schedule;
}

/**
 * @return list<array<string, mixed>>
 */
function sdkCheckins(): array
{
    return sdkBuffered('checkin');
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-09T00:00:30Z');
});

describe('a task that runs', function () {
    it('checks in when it starts and when it is done', function () {
        sdkEmptySchedule()->call(fn () => null)->name('nightly')->everyFiveMinutes();

        $this->artisan('schedule:run')->assertSuccessful();

        [$started, $ok] = sdkCheckins();

        expect(sdkCheckins())->toHaveCount(2)
            ->and($started)->toMatchArray(['monitor' => 'schedule:nightly', 'status' => 'started', 'expectedIntervalS' => 300])
            ->and($started)->not->toHaveKey('durationMs')
            ->and($ok)->toMatchArray(['monitor' => 'schedule:nightly', 'status' => 'ok', 'expectedIntervalS' => 300])
            ->and($ok['durationMs'])->toBeInt()->toBeGreaterThanOrEqual(0)
            ->and($ok)->not->toHaveKey('message')
            ->and($ok['id'])->not->toBe($started['id']);
    });

    it('measures the duration from the start of the task', function () {
        sdkEmptySchedule()->call(function () {
            usleep(30_000);
        })->name('slow')->everyMinute();

        $this->artisan('schedule:run')->assertSuccessful();

        expect(sdkCheckins()[1]['durationMs'])->toBeGreaterThanOrEqual(30)->toBeLessThan(2000);
    });

    it('hands the items to the buffer as they happen, not when the scheduler exits', function () {
        $schedule = sdkEmptySchedule();
        $task = $schedule->call(fn () => null)->name('quick')->everyMinute();

        Events::dispatch(new ScheduledTaskStarting($task));

        expect(array_column(sdkCheckins(), 'status'))->toBe(['started']);

        Events::dispatch(new ScheduledTaskFinished($task, 0.01));

        expect(array_column(sdkCheckins(), 'status'))->toBe(['started', 'ok']);
    });
});

describe('a task that fails', function () {
    it('reports an exception once, with its message', function () {
        sdkEmptySchedule()->call(fn () => throw new RuntimeException('The disk is full'))->name('backup')->daily();

        $this->artisan('schedule:run');

        expect(array_column(sdkCheckins(), 'status'))->toBe(['started', 'error'])
            ->and(sdkCheckins()[1])->toMatchArray(['monitor' => 'schedule:backup', 'message' => 'The disk is full', 'expectedIntervalS' => 86400])
            ->and(sdkCheckins()[1]['durationMs'])->toBeInt();
    });

    it('cuts the message to 500 characters', function () {
        sdkEmptySchedule()->call(fn () => throw new RuntimeException(str_repeat('é', 800)))->name('backup')->daily();

        $this->artisan('schedule:run');

        expect(mb_strlen(sdkCheckins()[1]['message']))->toBe(500);
    });

    it('names the exception when it has no message', function () {
        sdkEmptySchedule()->call(fn () => throw new LogicException)->name('backup')->daily();

        $this->artisan('schedule:run');

        expect(sdkCheckins()[1]['message'])->toBe(LogicException::class);
    });

    it('reports a command that exits with a code once, even though two events fire', function () {
        sdkEmptySchedule()->exec('exit 3')->everyMinute();

        $this->artisan('schedule:run');

        expect(array_column(sdkCheckins(), 'status'))->toBe(['started', 'error'])
            ->and(sdkCheckins()[1]['message'])->toBe('Exited with code 3');
    });

    it('reports a callback that returns false once', function () {
        sdkEmptySchedule()->call(fn () => false)->name('quiet-failure')->everyMinute();

        $this->artisan('schedule:run');

        expect(array_column(sdkCheckins(), 'status'))->toBe(['started', 'error'])
            ->and(sdkCheckins()[1]['message'])->toBe('Exited with code 1');
    });

    it('goes back to ok when the next run works', function () {
        sdkEmptySchedule()->call(fn () => throw new RuntimeException('first run'))->name('flaky')->everyMinute();
        $this->artisan('schedule:run');

        sdkEmptySchedule()->call(fn () => null)->name('flaky')->everyMinute();
        $this->artisan('schedule:run');

        expect(array_column(sdkCheckins(), 'status'))->toBe(['started', 'error', 'started', 'ok']);
    });
});

describe('a task in the background', function () {
    it('is done when the background process says so, not when it was launched', function () {
        $task = sdkEmptySchedule()->exec('sleep 1')->runInBackground()->everyMinute();

        Events::dispatch(new ScheduledTaskStarting($task));
        Events::dispatch(new ScheduledTaskFinished($task, 0.01));

        expect(array_column(sdkCheckins(), 'status'))->toBe(['started']);

        $task->exitCode = 0;
        Events::dispatch(new ScheduledBackgroundTaskFinished($task));

        expect(array_column(sdkCheckins(), 'status'))->toBe(['started', 'ok'])
            ->and(sdkCheckins()[1])->not->toHaveKey('durationMs');
    });

    it('reports a background process that exited with a code as an error', function () {
        $task = sdkEmptySchedule()->exec('sleep 1')->runInBackground()->everyMinute();
        $task->exitCode = 2;

        Events::dispatch(new ScheduledBackgroundTaskFinished($task));

        expect(sdkCheckins())->toHaveCount(1)
            ->and(sdkCheckins()[0])->toMatchArray(['status' => 'error', 'message' => 'Exited with code 2']);
    });
});

it('does not call a run that was skipped because the last one is still going ok', function () {
    $task = sdkEmptySchedule()->call(fn () => null)->name('stuck')->everyMinute();
    $task->skippedBecauseOverlapping = true;

    Events::dispatch(new ScheduledTaskStarting($task));
    Events::dispatch(new ScheduledTaskFinished($task, 0.0));

    expect(array_column(sdkCheckins(), 'status'))->toBe(['started']);
});

describe('the monitor name', function () {
    it('is schedule: and the description when there is one', function () {
        $task = sdkEmptySchedule()->exec("'/usr/bin/php' 'artisan' backup:run")->description('Nightly backup');

        expect(app(TaskCheckins::class)->monitorFor($task))->toBe('schedule:Nightly backup');
    });

    it('is the command without the PHP binary and artisan', function (string $command, string $expected) {
        $task = sdkEmptySchedule()->exec($command);

        expect(app(TaskCheckins::class)->monitorFor($task))->toBe("schedule:{$expected}");
    })->with([
        'quoted' => ["'/usr/bin/php8.4' 'artisan' backup:run --only-db", 'backup:run --only-db'],
        'a path with a space' => ["'/opt/my php/bin/php' 'artisan' reports:send --daily", 'reports:send --daily'],
        'double quotes' => ['"C:\\php\\php.exe" "artisan" queue:prune-batches', 'queue:prune-batches'],
        'unquoted' => ['php artisan inspire', 'inspire'],
        'a path to artisan' => ['/usr/bin/php /var/www/artisan cache:prune-stale-tags', 'cache:prune-stale-tags'],
        'not an artisan command' => ['rm -rf /tmp/cache', 'rm -rf /tmp/cache'],
        'artisan alone' => ["'/usr/bin/php' 'artisan'", "'/usr/bin/php' 'artisan'"],
    ]);

    it('is callback for a closure without a name', function () {
        $task = sdkEmptySchedule()->call(fn () => null);

        expect(app(TaskCheckins::class)->monitorFor($task))->toBe('schedule:callback');
    });

    it('is the name given to a closure', function () {
        $task = sdkEmptySchedule()->call(fn () => null)->name('prune-tokens');

        expect(app(TaskCheckins::class)->monitorFor($task))->toBe('schedule:prune-tokens');
    });

    it('is at most 100 characters', function () {
        $task = sdkEmptySchedule()->call(fn () => null)->name(str_repeat('é', 200));
        $monitor = app(TaskCheckins::class)->monitorFor($task);

        expect(mb_strlen($monitor))->toBe(100)
            ->and($monitor)->toStartWith('schedule:éé');
    });
});

describe('the expected interval', function () {
    it('is the time between the next two runs of the cron expression', function (string $method, array $arguments, int $seconds) {
        $task = sdkEmptySchedule()->call(fn () => null)->name('t')->{$method}(...$arguments);

        expect(app(TaskCheckins::class)->intervalFor($task))->toBe($seconds);
    })->with([
        'every minute' => ['everyMinute', [], 60],
        'every five minutes' => ['everyFiveMinutes', [], 300],
        'every fifteen minutes' => ['everyFifteenMinutes', [], 900],
        'hourly' => ['hourly', [], 3600],
        'daily' => ['daily', [], 86400],
        'daily at' => ['dailyAt', ['03:30'], 86400],
        'twice daily' => ['twiceDaily', [1, 13], 43200],
        'weekly' => ['weekly', [], 604800],
        'monthly (October 9: November 1 to December 1)' => ['monthly', [], 30 * 86400],
        'custom cron' => ['cron', ['*/10 * * * *'], 600],
    ]);

    it('is never below 60 seconds', function () {
        $task = sdkEmptySchedule()->call(fn () => null)->name('t')->everySecond();

        expect(app(TaskCheckins::class)->intervalFor($task))->toBe(60);
    });

    it('is never above 31 days', function () {
        $task = sdkEmptySchedule()->call(fn () => null)->name('t')->yearly();

        expect(app(TaskCheckins::class)->intervalFor($task))->toBe(2_678_400);
    });

    it('is left out when the expression cannot be read, and the task is still reported', function () {
        $task = sdkEmptySchedule()->call(fn () => null)->name('odd')->cron('not a cron');

        Events::dispatch(new ScheduledTaskStarting($task));

        expect(sdkCheckins())->toHaveCount(1)
            ->and(sdkCheckins()[0])->not->toHaveKey('expectedIntervalS');
    });
});

describe('what is not reported', function () {
    it('is the SDK\'s own sidetower:send task', function () {
        $own = collect(app(Schedule::class)->events())->first(fn (Event $event) => str_contains((string) $event->command, 'sidetower:send'));

        Events::dispatch(new ScheduledTaskStarting($own));
        Events::dispatch(new ScheduledTaskFinished($own, 0.1));

        expect(sdkCheckins())->toBe([]);
    });

    it('is a task named in checkins.ignore, by name or command, with wildcards', function (string $pattern) {
        config()->set('sidetower.checkins.ignore', [$pattern]);
        $task = sdkEmptySchedule()->exec("'/usr/bin/php' 'artisan' telescope:prune --hours=48")->daily();

        Events::dispatch(new ScheduledTaskStarting($task));

        expect(sdkCheckins())->toBe([]);
    })->with([
        'the name' => ['telescope:prune --hours=48'],
        'the monitor' => ['schedule:telescope:prune --hours=48'],
        'a wildcard' => ['telescope:*'],
        'the whole command' => ["'/usr/bin/php' 'artisan' telescope:prune --hours=48"],
    ]);

    it('is not a task that the ignore list does not match', function () {
        config()->set('sidetower.checkins.ignore', ['telescope', 'prune', 'schedule:telescope', 'cache:*']);
        $task = sdkEmptySchedule()->exec("'/usr/bin/php' 'artisan' telescope:prune --hours=48")->daily();

        Events::dispatch(new ScheduledTaskStarting($task));

        expect(array_column(sdkCheckins(), 'monitor'))->toBe(['schedule:telescope:prune --hours=48']);
    });

    it('is a described task named in checkins.ignore', function () {
        config()->set('sidetower.checkins.ignore', ['Pruning']);
        $task = sdkEmptySchedule()->exec('x')->description('Pruning');

        Events::dispatch(new ScheduledTaskStarting($task));

        expect(sdkCheckins())->toBe([]);
    });

    it('is anything while checkins.scheduled_tasks is off', function () {
        config()->set('sidetower.checkins.scheduled_tasks', false);
        sdkEmptySchedule()->call(fn () => null)->name('nightly')->everyMinute();

        $this->artisan('schedule:run');

        expect(sdkCheckins())->toBe([]);
    });

    it('is anything while the SDK is off', function () {
        config()->set('sidetower.enabled', false);
        sdkEmptySchedule()->call(fn () => null)->name('nightly')->everyMinute();

        $this->artisan('schedule:run');

        expect(sdkCheckins())->toBe([]);
    });

    it('leaves the other tasks alone', function () {
        config()->set('sidetower.checkins.ignore', ['ignored']);
        $schedule = sdkEmptySchedule();
        $schedule->call(fn () => null)->name('ignored')->everyMinute();
        $schedule->call(fn () => null)->name('kept')->everyMinute();

        $this->artisan('schedule:run');

        expect(array_unique(array_column(sdkCheckins(), 'monitor')))->toBe(['schedule:kept']);
    });
});

it('never throws into the scheduler', function () {
    sdkUseStore('down');
    $task = sdkEmptySchedule()->call(fn () => null)->name('nightly')->everyMinute();

    Events::dispatch(new ScheduledTaskStarting($task));
    Events::dispatch(new ScheduledTaskFinished($task, 0.1));
    Events::dispatch(new ScheduledTaskFailed($task, new RuntimeException('x')));

    expect(sdk()->pending())->not->toBeEmpty();
});
