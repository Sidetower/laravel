<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Ai;

use Illuminate\Contracts\Foundation\Application;

/**
 * Everything the AI part registers: bindings, the Http client listener, its commands. The service provider calls these two
 * methods and nothing else of the AI part, so the AI part owns its wiring.
 */
class AiServices
{
    public static function register(Application $app): void
    {
        $app->singleton(AiUsage::class);
    }

    public static function boot(Application $app): void {}

    /**
     * @return list<class-string>
     */
    public static function commands(): array
    {
        return [];
    }
}
