<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Ai;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Throwable;

/**
 * Everything the AI part registers: bindings, the event listeners, its commands. The service provider calls these two
 * methods and nothing else of the AI part, so the AI part owns its wiring.
 *
 * Config keys read, all with defaults in code: `sidetower.ai.http` (true), `sidetower.ai.hosts` ([]) and
 * `sidetower.ai.history_sources` ([]).
 */
class AiServices
{
    public static function register(Application $app): void
    {
        $app->singleton(OperationResolver::class);
        $app->singleton(AiUsage::class);
    }

    public static function boot(Application $app): void
    {
        $events = $app->make(Dispatcher::class);

        static::listenForOperationNames($app, $events);
    }

    /**
     * @return list<class-string>
     */
    public static function commands(): array
    {
        return [];
    }

    /**
     * Queue and console events tell the resolver which job or command is running, for the default operation name.
     */
    protected static function listenForOperationNames(Application $app, Dispatcher $events): void
    {
        $resolver = fn (): OperationResolver => $app->make(OperationResolver::class);

        $events->listen(JobProcessing::class, static::safely(fn (JobProcessing $event) => $resolver()->jobStarted($event->job)));

        foreach ([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class, JobAttempted::class] as $ended) {
            $events->listen($ended, static::safely(fn (object $event) => $resolver()->jobEnded($event->job)));
        }

        $events->listen(CommandStarting::class, static::safely(fn (CommandStarting $event) => $resolver()->commandStarted($event->command)));
        $events->listen(CommandFinished::class, static::safely(fn (CommandFinished $event) => $resolver()->commandFinished($event->command)));
    }

    /**
     * A listener that cannot throw into the application.
     */
    protected static function safely(callable $listener): callable
    {
        return static function (mixed ...$arguments) use ($listener): void {
            try {
                $listener(...$arguments);
            } catch (Throwable $e) {
                Debug::caught($e);
            }
        };
    }
}
