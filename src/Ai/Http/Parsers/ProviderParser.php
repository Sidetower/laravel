<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Ai\Http\Parsers;

use Sidetower\Laravel\Ai\Http\HttpExchange;

/**
 * Turns one call of the Http client to an AI provider into the fields of an `ai.usage` item. One parser per provider
 * family. A parser never puts prompts, responses, user ids or headers into the fields.
 */
interface ProviderParser
{
    /**
     * Whether the call is a billable AI call of this provider (right method and path). The listener runs for every response
     * the application gets from a known host, so this stays cheap and reads no body.
     */
    public function handles(HttpExchange $call): bool;

    /**
     * The fields to record: `provider`, `model`, `status`, tokens or units, `reportedCostUsd`, `durationMs`. Null when the
     * call is part of a larger one that is recorded elsewhere (a fal queue submit that succeeded, a status poll).
     *
     * @return array<string, mixed>|null
     */
    public function parse(HttpExchange $call): ?array;
}
