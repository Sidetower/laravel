<?php

declare(strict_types=1);

namespace Sidetower\Laravel;

use Illuminate\Support\ServiceProvider;
use Sidetower\Laravel\Ai\AiServices;

class SidetowerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sidetower.php', 'sidetower');

        $this->app->singleton(Sidetower::class);

        AiServices::register($this->app);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sidetower.php' => config_path('sidetower.php'),
            ], 'sidetower-config');

            $this->commands(AiServices::commands());
        }

        AiServices::boot($this->app);
    }
}
