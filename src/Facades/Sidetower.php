<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled()
 * @method static void record(array<string, mixed> $item)
 * @method static \Sidetower\Laravel\Ai\AiUsage ai()
 *
 * @see \Sidetower\Laravel\Sidetower
 */
class Sidetower extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Sidetower\Laravel\Sidetower::class;
    }
}
