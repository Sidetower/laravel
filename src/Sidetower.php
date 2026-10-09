<?php

declare(strict_types=1);

namespace Sidetower\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Sidetower\Laravel\Ai\AiUsage;
use Throwable;

/**
 * The SDK's entry point. Code records items here; they wait in memory until the request, job or command ends, then in the
 * cache buffer until the scheduled `sidetower:send` command sends them. Nothing here touches the network.
 */
class Sidetower
{
    public const VERSION = '0.1.0';

    public const PROTOCOL = 1;

    /** Items kept in memory before `record()` writes them to the buffer without waiting for the end of the request. */
    public const PERSIST_THRESHOLD = 200;

    /** The protocol's `source.environment`. */
    public const ENVIRONMENT_PATTERN = '/^[a-z][a-z0-9-]{0,19}$/';

    /**
     * Items recorded since the last persist.
     *
     * @var list<array<string, mixed>>
     */
    protected array $pending = [];

    /** The size `pending` has to reach before the next persist is tried; moves up while the cache refuses writes. */
    protected int $persistAt = self::PERSIST_THRESHOLD;

    /** Items dropped from memory because the cache would not take them; added to the counter on the next good persist. */
    protected int $droppedInMemory = 0;

    protected bool $warnedAboutEnvironment = false;

    public function __construct(protected Application $app) {}

    /**
     * Off without a key and an endpoint, when switched off, and while the application runs its tests (unless switched on
     * explicitly).
     */
    public function enabled(): bool
    {
        $enabled = config('sidetower.enabled');

        if ($enabled !== null && ! filter_var($enabled, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        if ($enabled === null && $this->app->runningUnitTests()) {
            return false;
        }

        if (blank(config('sidetower.key')) || blank(config('sidetower.endpoint'))) {
            return false;
        }

        return $this->environment() !== null;
    }

    /**
     * The environment the items belong to: `sidetower.environment`, else the application environment, lower-cased. Null when
     * that is not a valid protocol environment; the SDK is then off, and says so once.
     */
    public function environment(): ?string
    {
        $configured = config('sidetower.environment');
        $environment = strtolower(trim((string) (filled($configured) ? $configured : $this->app->environment())));

        if (preg_match(self::ENVIRONMENT_PATTERN, $environment) === 1) {
            return $environment;
        }

        if (! $this->warnedAboutEnvironment) {
            $this->warnedAboutEnvironment = true;
            $this->log('warning', "Sidetower is off: the environment '{$environment}' does not fit ^[a-z][a-z0-9-]{0,19}$. Set SIDETOWER_ENVIRONMENT.");
        }

        return null;
    }

    /**
     * Records one protocol item (packages/protocol). `type` and the fields of the type are the caller's; `id` and `ts` are
     * filled when missing. Never throws and never touches the network. The cache is written to only once the memory holds
     * `PERSIST_THRESHOLD` items.
     *
     * @param  array<string, mixed>  $item
     */
    public function record(array $item): void
    {
        try {
            if (! $this->enabled()) {
                return;
            }

            $item['id'] ??= $this->newId();
            $item['ts'] ??= $this->timestamp();

            $this->pending[] = $item;

            if (count($this->pending) >= $this->persistAt) {
                $this->persist();
            }
        } catch (Throwable $exception) {
            $this->log('debug', 'Sidetower could not record an item: '.$exception->getMessage());
        }
    }

    /**
     * The items recorded since the last persist, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        return $this->pending;
    }

    /**
     * Moves the items in memory to the cache buffer in one write. Called when a request, command or job ends. Never throws:
     * when the cache refuses, the items stay in memory (up to `buffer.max_items`) for the next attempt.
     */
    public function persist(): void
    {
        if ($this->pending === []) {
            return;
        }

        try {
            $this->app->make(Buffer::class)->push($this->pending, $this->droppedInMemory);
            $this->pending = [];
            $this->droppedInMemory = 0;
            $this->persistAt = self::PERSIST_THRESHOLD;
        } catch (Throwable $exception) {
            $this->persistAt = count($this->pending) + self::PERSIST_THRESHOLD;
            $this->capPending();
            $this->log('debug', 'Sidetower could not write its buffer: '.$exception->getMessage());
        }
    }

    public function ai(): AiUsage
    {
        return $this->app->make(AiUsage::class);
    }

    /**
     * An item id: unique, sortable, within the protocol's `[A-Za-z0-9_.:-]{1,64}`.
     */
    public function newId(): string
    {
        return strtolower((string) Str::ulid());
    }

    /**
     * Now as the protocol writes it: UTC ISO 8601 with milliseconds.
     */
    public function timestamp(?Carbon $at = null): string
    {
        return ($at ?? Carbon::now())->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Logs on the default channel, and never lets the logger's own trouble out.
     *
     * @param  array<string, mixed>  $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        try {
            Log::{$level}($message, $context);
        } catch (Throwable) {
        }
    }

    /**
     * Keeps the memory bounded when the cache stays out of reach: the oldest items go, and are counted for later.
     */
    protected function capPending(): void
    {
        $over = count($this->pending) - max(1, (int) config('sidetower.buffer.max_items', 5000));

        if ($over > 0) {
            $this->pending = array_slice($this->pending, $over);
            $this->droppedInMemory += $over;
        }
    }
}
