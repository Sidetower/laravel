<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use Sidetower\Laravel\Testing\SidetowerFake;

/**
 * @method static bool enabled()
 * @method static void record(array<string, mixed> $item)
 * @method static \Sidetower\Laravel\Ai\AiUsage ai()
 * @method static void assertRecorded(string $type, (Closure(array<string, mixed>): bool)|null $filter = null)
 * @method static void assertNotRecorded(string $type, (Closure(array<string, mixed>): bool)|null $filter = null)
 * @method static void assertNothingRecorded()
 * @method static list<array<string, mixed>> recorded(string|null $type = null)
 *
 * @see \Sidetower\Laravel\Sidetower
 * @see SidetowerFake
 */
class Sidetower extends Facade
{
    /**
     * Swaps in a Sidetower that keeps what is recorded in memory, for the assertions of {@see SidetowerFake}. It records
     * whether or not a key is set. Code that already holds the real manager records into the fake too.
     */
    public static function fake(): SidetowerFake
    {
        $root = static::getFacadeRoot();
        $real = $root instanceof SidetowerFake ? $root->real() : $root;
        $fake = new SidetowerFake($real);

        $real->redirectTo($fake);
        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return \Sidetower\Laravel\Sidetower::class;
    }
}
