<?php

declare(strict_types=1);

namespace Sidetower\Laravel;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Sidetower\Laravel\Ai\AiServices;

class SidetowerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sidetower.php', 'sidetower');

        $this->app->singleton(Sidetower::class);
        $this->app->singleton(Buffer::class);

        AiServices::register($this->app);
    }

    public function boot(): void
    {
        $this->registerPersistPoints();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sidetower.php' => config_path('sidetower.php'),
            ], 'sidetower-config');

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
}
