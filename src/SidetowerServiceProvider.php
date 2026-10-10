<?php

declare(strict_types=1);

namespace Sidetower\Laravel;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Sidetower\Laravel\Ai\AiServices;
use Sidetower\Laravel\Console\SendCommand;
use Sidetower\Laravel\Console\TestCommand;
use Sidetower\Laravel\Health\HealthReport;
use Sidetower\Laravel\Scheduling\TaskCheckins;

class SidetowerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sidetower.php', 'sidetower');

        $this->app->singleton(Sidetower::class);
        $this->app->singleton(Buffer::class);
        $this->app->singleton(Release::class);
        $this->app->singleton(Transport::class);
        $this->app->singleton(HealthReport::class);
        $this->app->singleton(Sender::class);
        $this->app->singleton(TaskCheckins::class);

        AiServices::register($this->app);
    }

    public function boot(): void
    {
        $this->registerPersistPoints();
        $this->scheduleSending();
        $this->listenToScheduledTasks();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sidetower.php' => config_path('sidetower.php'),
            ], 'sidetower-config');

            $this->commands([SendCommand::class, TestCommand::class]);
            $this->commands(AiServices::commands());
        }

        AiServices::boot($this->app);
    }

    /**
     * The moments the items in memory move to the buffer: the end of a request or command, the end of every queue job (a
     * worker lives on), and Octane's end of request, task and tick. The container is asked inside the callbacks, not captured
     * here, so a long-lived process always reaches the manager of the work it is doing.
     */
    protected function registerPersistPoints(): void
    {
        $persist = static function (): void {
            app(Sidetower::class)->persist();
        };

        $this->app->terminating($persist);

        foreach ([
            JobProcessed::class,
            JobFailed::class,
            JobExceptionOccurred::class,
            'Laravel\\Octane\\Events\\RequestTerminated',
            'Laravel\\Octane\\Events\\TaskTerminated',
            'Laravel\\Octane\\Events\\TickTerminated',
        ] as $event) {
            Event::listen($event, $persist);
        }
    }

    /**
     * `sidetower:send` once a minute. Not `onOneServer`: every server sends its own health (the buffer's lock keeps them from
     * trampling each other). The mutex expires after 10 minutes, so a killed run does not stop sending for a day. It also runs
     * in maintenance mode: the scheduler is alive during a deploy, and a pause there would read as a stopped scheduler.
     */
    protected function scheduleSending(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('sidetower:send')
                ->everyMinute()
                ->withoutOverlapping(10)
                ->evenInMaintenanceMode()
                ->when(fn (): bool => app(Sidetower::class)->enabled());
        });
    }

    /**
     * Whether a task is reported is decided when it runs (`checkins.scheduled_tasks`), not here.
     */
    protected function listenToScheduledTasks(): void
    {
        Event::listen(ScheduledTaskStarting::class, [TaskCheckins::class, 'starting']);
        Event::listen(ScheduledTaskFinished::class, [TaskCheckins::class, 'finished']);
        Event::listen(ScheduledTaskFailed::class, [TaskCheckins::class, 'failed']);
        Event::listen(ScheduledBackgroundTaskFinished::class, [TaskCheckins::class, 'backgroundFinished']);
    }
}
