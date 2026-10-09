<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Ai;

use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * The operation name of an AI call made outside an `operation()` scope: the queue job that is running, else the named route
 * of the request, else the console command, else `unknown`. The service provider feeds it from queue and console events.
 */
class OperationResolver
{
    /**
     * Running queue jobs by object id. A job is removed by whichever end event comes first, so the others are no-ops.
     *
     * @var array<int, string>
     */
    protected array $jobs = [];

    /**
     * @var list<string>
     */
    protected array $commands = [];

    public function __construct(protected Application $app) {}

    public function jobStarted(object $job): void
    {
        $this->jobs[spl_object_id($job)] = $this->jobName($job);
    }

    public function jobEnded(object $job): void
    {
        unset($this->jobs[spl_object_id($job)]);
    }

    public function commandStarted(?string $name): void
    {
        if (filled($name)) {
            $this->commands[] = $name;
        }
    }

    public function commandFinished(?string $name): void
    {
        $index = array_search($name, array_reverse($this->commands, true), true);

        if ($index !== false) {
            unset($this->commands[$index]);
            $this->commands = array_values($this->commands);
        }
    }

    public function resolve(): string
    {
        if ($this->jobs !== []) {
            return $this->limit('job:'.end($this->jobs));
        }

        if (($route = $this->routeName()) !== null) {
            return $this->limit('route:'.$route);
        }

        if ($this->commands !== []) {
            return $this->limit('command:'.end($this->commands));
        }

        return 'unknown';
    }

    protected function jobName(object $job): string
    {
        try {
            $name = method_exists($job, 'resolveName') ? (string) $job->resolveName() : $job::class;
        } catch (Throwable) {
            $name = $job::class;
        }

        $name = preg_split('/[@\s(]/', $name)[0] ?: 'job';

        return class_basename($name);
    }

    protected function routeName(): ?string
    {
        try {
            $name = $this->app->bound('request') ? $this->app->make('request')->route()?->getName() : null;
        } catch (Throwable) {
            return null;
        }

        return is_string($name) && $name !== '' && ! str_starts_with($name, 'generated::') ? $name : null;
    }

    protected function limit(string $name): string
    {
        return mb_substr($name, 0, 100);
    }
}
