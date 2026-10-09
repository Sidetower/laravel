<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Scheduling;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Sidetower\Laravel\Sidetower;
use Throwable;

/**
 * One `checkin` item for every start and end of a scheduled task, named `schedule:<task>`. The panel turns a missing or
 * failing check-in into an alert. The listeners never throw.
 */
class TaskCheckins
{
    public const MIN_INTERVAL_SECONDS = 60;

    public const MAX_INTERVAL_SECONDS = 2_678_400;

    /** The SDK's own task is not reported: its run is the `scheduler` check-in. */
    protected const OWN_TASK = 'sidetower:send';

    /**
     * When each task of this process started, by the id of its event object.
     *
     * @var array<int, int>
     */
    protected array $startedAt = [];

    /**
     * The tasks whose failed run was already reported by the finished event (a command that exits non-zero fires both).
     *
     * @var array<int, true>
     */
    protected array $reported = [];

    public function __construct(protected Sidetower $sidetower) {}

    public function starting(ScheduledTaskStarting $event): void
    {
        $this->handle($event->task, function () use ($event): void {
            $this->startedAt[spl_object_id($event->task)] = hrtime(true);
            unset($this->reported[spl_object_id($event->task)]);

            $this->checkin($event->task, 'started');
        });
    }

    /**
     * A task run in the background has only been launched when this fires; its end comes with `backgroundFinished`. A run that
     * was skipped because the last one still holds the mutex did not run at all, and must not look like an `ok`.
     */
    public function finished(ScheduledTaskFinished $event): void
    {
        $this->handle($event->task, function () use ($event): void {
            $id = spl_object_id($event->task);
            $startedAt = $this->startedAt[$id] ?? null;
            unset($this->startedAt[$id]);

            if ($event->task->runInBackground || ($event->task->skippedBecauseOverlapping ?? false)) {
                return;
            }

            $durationMs = $startedAt === null ? (int) round($event->runtime * 1000) : $this->since($startedAt);
            $exitCode = (int) $event->task->exitCode;

            if ($exitCode === 0) {
                $this->checkin($event->task, 'ok', durationMs: $durationMs);

                return;
            }

            $this->reported[$id] = true;
            $this->checkin($event->task, 'error', "Exited with code {$exitCode}", $durationMs);
        });
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->handle($event->task, function () use ($event): void {
            $id = spl_object_id($event->task);
            $startedAt = $this->startedAt[$id] ?? null;
            unset($this->startedAt[$id]);

            if (isset($this->reported[$id])) {
                unset($this->reported[$id]);

                return;
            }

            $message = trim($event->exception->getMessage());

            $this->checkin(
                $event->task,
                'error',
                Str::limit($message !== '' ? $message : $event->exception::class, 500, ''),
                $startedAt === null ? null : $this->since($startedAt),
            );
        });
    }

    public function backgroundFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $this->handle($event->task, function () use ($event): void {
            $exitCode = (int) $event->task->exitCode;

            $this->checkin($event->task, $exitCode === 0 ? 'ok' : 'error', $exitCode === 0 ? null : "Exited with code {$exitCode}");
        });
    }

    /**
     * `schedule:` and the name of the task: its description, else its command without the PHP binary and `artisan`, else
     * `callback`. At most 100 characters.
     */
    public function monitorFor(Event $task): string
    {
        return mb_substr('schedule:'.$this->nameOf($task), 0, 100);
    }

    /**
     * Seconds between the next two runs of the task, from its cron expression; null if the expression cannot be read.
     */
    public function intervalFor(Event $task): ?int
    {
        try {
            $now = Carbon::now();
            $seconds = $task->nextRunDate($now, 1)->getTimestamp() - $task->nextRunDate($now)->getTimestamp();
        } catch (Throwable) {
            return null;
        }

        return max(self::MIN_INTERVAL_SECONDS, min(self::MAX_INTERVAL_SECONDS, $seconds));
    }

    public function isIgnored(Event $task): bool
    {
        $name = $this->nameOf($task);

        if ($name === self::OWN_TASK || str_starts_with($name, self::OWN_TASK.' ')) {
            return true;
        }

        $candidates = array_filter([$name, 'schedule:'.$name, trim((string) $task->command), trim((string) $task->description)]);

        foreach ((array) config('sidetower.checkins.ignore', []) as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            foreach ($candidates as $candidate) {
                if (Str::is($pattern, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function nameOf(Event $task): string
    {
        if (is_string($task->description) && trim($task->description) !== '') {
            return trim($task->description);
        }

        if (is_string($task->command) && trim($task->command) !== '') {
            return $this->withoutBinaries(trim($task->command));
        }

        return 'callback';
    }

    /**
     * `'/usr/bin/php8.4' 'artisan' backup:run --only-db` becomes `backup:run --only-db`.
     */
    protected function withoutBinaries(string $command): string
    {
        $arguments = preg_replace('/^(?:\'[^\']*\'|"[^"]*"|\S+)\s+(?:\'[^\']*artisan\'|"[^"]*artisan"|\S*artisan)\s+/', '', $command, 1);

        return is_string($arguments) && trim($arguments) !== '' ? trim($arguments) : $command;
    }

    protected function checkin(Event $task, string $status, ?string $message = null, ?int $durationMs = null): void
    {
        $item = [
            'type' => 'checkin',
            'monitor' => $this->monitorFor($task),
            'status' => $status,
        ];

        if ($durationMs !== null) {
            $item['durationMs'] = $durationMs;
        }

        if (($interval = $this->intervalFor($task)) !== null) {
            $item['expectedIntervalS'] = $interval;
        }

        if ($message !== null) {
            $item['message'] = $message;
        }

        $this->sidetower->record($item);
        $this->sidetower->persist();
    }

    protected function since(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    /**
     * @param  \Closure(): void  $report
     */
    protected function handle(Event $task, \Closure $report): void
    {
        try {
            if (config('sidetower.checkins.scheduled_tasks', true) && $this->sidetower->enabled() && ! $this->isIgnored($task)) {
                $report();
            }
        } catch (Throwable $exception) {
            $this->sidetower->log('debug', 'Sidetower could not report a scheduled task: '.$exception->getMessage());
        }
    }
}
