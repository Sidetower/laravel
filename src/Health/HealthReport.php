<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Health;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Sidetower\Laravel\Buffer;
use Sidetower\Laravel\Release;
use Throwable;

/**
 * Builds the server health report of the `health.snapshot` item (docs/sozlesmeler.md, section 1 of the Sidetower panel).
 * Every section is built on its own: one that fails becomes null and its message goes to the errors list.
 */
class HealthReport
{
    public const SCHEMA_VERSION = 1;

    /** The protocol allows 16 KB per item; the report leaves room for the item's own fields. */
    protected const MAX_BYTES = 14 * 1024;

    protected const MAX_ERRORS = 10;

    protected const CACHE_PROBE_KEY = 'sidetower:health-probe';

    /**
     * @var list<string>
     */
    protected array $errors = [];

    public function __construct(protected Buffer $buffer, protected Release $release) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $this->errors = [];

        $sections = [
            'runtime' => $this->section('runtime', $this->runtime(...)),
            'system' => $this->section('system', $this->system(...)),
            'database' => $this->section('database', $this->database(...)),
            'cache' => $this->section('cache', $this->cache(...)),
            'queue' => $this->section('queue', $this->queue(...)),
            'scheduler' => $this->section('scheduler', $this->scheduler(...)),
        ];

        return $this->fit([
            'schema' => self::SCHEMA_VERSION,
            'app' => (string) config('app.name'),
            'generatedAt' => $this->timestamp(),
            ...$sections,
            'errors' => $this->errors,
        ]);
    }

    /**
     * The queues whose pending jobs are counted: `health.queues`; else the queues of the Horizon supervisors (the defaults and
     * the ones of this environment); else `default`.
     *
     * @return list<string>
     */
    public function queueNames(): array
    {
        $configured = $this->names(config('sidetower.health.queues'));

        if ($configured !== []) {
            return $configured;
        }

        $supervisors = [];

        if (is_array(config('horizon.defaults'))) {
            $supervisors = array_values(config('horizon.defaults'));
        }

        $environment = (string) (config('horizon.env') ?: app()->environment());

        foreach ((array) config('horizon.environments', []) as $pattern => $environmentSupervisors) {
            if (is_array($environmentSupervisors) && Str::is((string) $pattern, $environment)) {
                $supervisors = [...$supervisors, ...array_values($environmentSupervisors)];
            }
        }

        $horizon = [];

        foreach ($supervisors as $supervisor) {
            if (is_array($supervisor)) {
                $horizon = [...$horizon, ...$this->names($supervisor['queue'] ?? null)];
            }
        }

        $horizon = array_values(array_unique($horizon));

        return $horizon === [] ? ['default'] : $horizon;
    }

    /**
     * Queue names from a list or from a comma separated string (Horizon accepts both).
     *
     * @return list<string>
     */
    protected function names(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        $names = [];

        foreach ($value as $name) {
            if (is_scalar($name) && trim((string) $name) !== '') {
                $names[] = trim((string) $name);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @template TSection
     *
     * @param  Closure(): TSection  $callback
     * @return TSection|null
     */
    protected function section(string $name, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            $this->errors[] = $name.': '.$this->describe($exception);

            return null;
        }
    }

    protected function describe(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return Str::limit($message !== '' ? $message : $exception::class, 200);
    }

    /**
     * @return array{php: string, laravel: string, environment: string, commit: string|null}
     */
    protected function runtime(): array
    {
        return [
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'environment' => (string) app()->environment(),
            'commit' => $this->release->shortCommit(),
        ];
    }

    /**
     * @return array{
     *     hostname: string|null,
     *     cpuCount: int|null,
     *     load: list<float>|null,
     *     memory: array{totalBytes: int|null, availableBytes: int|null},
     *     disk: array{path: string, totalBytes: int|null, freeBytes: int|null},
     *     uptimeSeconds: int|null,
     * }
     */
    protected function system(): array
    {
        $hostname = gethostname();
        $meminfo = $this->readFile('/proc/meminfo');
        $diskPath = base_path();
        $diskTotal = @disk_total_space($diskPath);
        $diskFree = @disk_free_space($diskPath);

        return [
            'hostname' => $hostname === false ? null : $hostname,
            'cpuCount' => $this->cpuCount(),
            'load' => $this->loadAverage(),
            'memory' => [
                'totalBytes' => $this->meminfoBytes($meminfo, 'MemTotal'),
                'availableBytes' => $this->meminfoBytes($meminfo, 'MemAvailable'),
            ],
            'disk' => [
                'path' => $diskPath,
                'totalBytes' => $diskTotal === false ? null : (int) $diskTotal,
                'freeBytes' => $diskFree === false ? null : (int) $diskFree,
            ],
            'uptimeSeconds' => $this->uptimeSeconds(),
        ];
    }

    /**
     * An unreachable database is reported as not ok (with the reason in the errors list) rather than as a missing section, so
     * the panel can tell "database down" apart from "could not be read".
     *
     * @return array{ok: bool, latencyMs: float|null, driver: string}
     */
    protected function database(): array
    {
        $name = (string) config('database.default');
        $driver = (string) config("database.connections.{$name}.driver", $name);
        $startedAt = hrtime(true);

        try {
            DB::connection()->select('select 1');
        } catch (Throwable $exception) {
            $this->errors[] = 'database: '.$this->describe($exception);

            return ['ok' => false, 'latencyMs' => null, 'driver' => $driver];
        }

        return [
            'ok' => true,
            'latencyMs' => round((hrtime(true) - $startedAt) / 1_000_000, 1),
            'driver' => $driver,
        ];
    }

    /**
     * @return array{ok: bool, driver: string}
     */
    protected function cache(): array
    {
        $store = Cache::store();
        $probe = Str::random(16);

        $store->put(self::CACHE_PROBE_KEY, $probe, 30);
        $ok = $store->get(self::CACHE_PROBE_KEY) === $probe;
        $store->forget(self::CACHE_PROBE_KEY);

        $name = (string) config('cache.default');

        return [
            'ok' => $ok,
            'driver' => (string) config("cache.stores.{$name}.driver", $name),
        ];
    }

    /**
     * @return array{connection: string, pending: array<string, int>|object, failedLast24h: int|null, failedTotal: int|null}
     */
    protected function queue(): array
    {
        $connection = (string) config('queue.default');
        $queue = Queue::connection($connection);
        $pending = [];

        foreach ($this->queueNames() as $name) {
            $pending[$name] = (int) $queue->size($name);
        }

        [$failedLast24h, $failedTotal] = $this->failedJobCounts();

        return [
            'connection' => $connection,
            'pending' => $pending === [] ? (object) [] : $pending,
            'failedLast24h' => $failedLast24h,
            'failedTotal' => $failedTotal,
        ];
    }

    /**
     * @return array{lastRunAt: string|null}
     */
    protected function scheduler(): array
    {
        $lastRunAt = $this->buffer->cache()->get(Buffer::LAST_RUN);

        return ['lastRunAt' => is_string($lastRunAt) ? $lastRunAt : null];
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    protected function failedJobCounts(): array
    {
        $failed = (array) config('queue.failed');
        $table = $failed['table'] ?? null;

        if (! is_string($table) || ! in_array($failed['driver'] ?? null, ['database', 'database-uuids'], true)) {
            return [null, null];
        }

        $connectionName = is_string($failed['database'] ?? null) ? $failed['database'] : null;

        if (! Schema::connection($connectionName)->hasTable($table)) {
            return [null, null];
        }

        $query = DB::connection($connectionName)->table($table);

        return [
            (clone $query)->where('failed_at', '>=', Carbon::now()->subDay())->count(),
            $query->count(),
        ];
    }

    /**
     * Shortens the long parts of the report, one step at a time, until it fits the room an item has: the errors, the disk path,
     * and last the list of queues.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    protected function fit(array $report): array
    {
        $report['errors'] = array_slice($report['errors'], 0, self::MAX_ERRORS);

        $steps = [
            fn (array $r): array => $this->shorten($r, path: 120),
            fn (array $r): array => $this->shorten($r, path: 40, errors: 5, lineLength: 100),
            fn (array $r): array => $this->shorten($r, errors: 3, lineLength: 60, queues: 50),
            fn (array $r): array => $this->shorten($r, errors: 1, lineLength: 40, queues: 10),
        ];

        foreach ($steps as $step) {
            if ($this->size($report) <= self::MAX_BYTES) {
                break;
            }

            $report = $step($report);
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    protected function shorten(array $report, ?int $path = null, ?int $errors = null, int $lineLength = 200, ?int $queues = null): array
    {
        if ($path !== null && isset($report['system']['disk']['path'])) {
            $report['system']['disk']['path'] = Str::limit($report['system']['disk']['path'], $path, '');
        }

        if ($errors !== null) {
            $report['errors'] = array_map(fn (string $line): string => Str::limit($line, $lineLength, ''), array_slice($report['errors'], 0, $errors));
        }

        if ($queues !== null && is_array($report['queue']['pending'] ?? null)) {
            $report['queue']['pending'] = array_slice($report['queue']['pending'], 0, $queues, true);
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    protected function size(array $report): int
    {
        return strlen((string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    protected function cpuCount(): ?int
    {
        if (function_exists('shell_exec')) {
            $output = trim((string) @shell_exec('nproc 2>/dev/null'));

            if (ctype_digit($output) && (int) $output > 0) {
                return (int) $output;
            }
        }

        $cpuinfo = $this->readFile('/proc/cpuinfo');
        $count = $cpuinfo === null ? 0 : (int) preg_match_all('/^processor\s*:/m', $cpuinfo);

        return $count > 0 ? $count : null;
    }

    /**
     * @return list<float>|null
     */
    protected function loadAverage(): ?array
    {
        $contents = $this->readFile('/proc/loadavg');
        $parts = $contents === null ? [] : preg_split('/\s+/', trim($contents));

        if (! is_array($parts) || count($parts) < 3) {
            return null;
        }

        $load = [];

        foreach (array_slice($parts, 0, 3) as $value) {
            if (! is_numeric($value)) {
                return null;
            }

            $load[] = (float) $value;
        }

        return $load;
    }

    protected function meminfoBytes(?string $meminfo, string $field): ?int
    {
        if ($meminfo === null || preg_match('/^'.$field.':\s+(\d+)\s*kB$/m', $meminfo, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1] * 1024;
    }

    protected function uptimeSeconds(): ?int
    {
        $contents = $this->readFile('/proc/uptime');
        $seconds = $contents === null ? '' : strtok($contents, ' ');

        return is_string($seconds) && is_numeric($seconds) ? (int) floor((float) $seconds) : null;
    }

    protected function readFile(string $path): ?string
    {
        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    protected function timestamp(): string
    {
        return Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
