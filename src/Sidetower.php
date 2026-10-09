<?php

declare(strict_types=1);

namespace Sidetower\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Sidetower\Laravel\Ai\AiUsage;

/**
 * The SDK's entry point. Code records items here; they wait in memory until the request, job or command ends, then in the
 * cache buffer until the scheduled `sidetower:send` command sends them. Nothing here touches the network.
 */
class Sidetower
{
    public const VERSION = '0.1.0';

    public const PROTOCOL = 1;

    /**
     * Items recorded since the last persist.
     *
     * @var list<array<string, mixed>>
     */
    protected array $pending = [];

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

        return filled(config('sidetower.key')) && filled(config('sidetower.endpoint'));
    }

    /**
     * Records one protocol item (packages/protocol). `type` and the fields of the type are the caller's; `id` and `ts` are
     * filled when missing. Never throws and never blocks on I/O.
     *
     * @param  array<string, mixed>  $item
     */
    public function record(array $item): void
    {
        if (! $this->enabled()) {
            return;
        }

        $item['id'] ??= $this->newId();
        $item['ts'] ??= $this->timestamp();

        $this->pending[] = $item;
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
}
