<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Import;

use Carbon\CarbonInterface;

/**
 * Where an application keeps the AI usage it recorded itself before the SDK was installed. `sidetower:import-history` reads
 * every source listed in `config('sidetower.ai.history_sources')` and sends what it yields to Sidetower, once.
 */
interface HistorySource
{
    /**
     * A short name for the source: it names the source in the command's output and in item ids (`hist-<name>-...`), so
     * keep it stable and use only letters, digits, `_`, `.`, `:` and `-`.
     */
    public function name(): string;

    /**
     * The usage since the given moment, as `ai.usage` items: arrays with the protocol's fields (`operation`, `provider`,
     * `model`, `status`, tokens, `reportedCostUsd`, ...) plus `ts`, when it happened, and `id`, which must be the same on
     * every run so that importing twice never counts twice (see HistoryId). A generator keeps memory flat.
     *
     * @return iterable<array<string, mixed>>
     */
    public function items(CarbonInterface $since): iterable;
}
