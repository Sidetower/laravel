<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Ai;

use Throwable;

/**
 * The AI part never throws into the application. What it catches goes to the log at debug level, and if even that fails it
 * is dropped.
 */
final class Debug
{
    public static function caught(Throwable $e): void
    {
        try {
            logger()->debug('Sidetower AI: '.$e::class.': '.$e->getMessage());
        } catch (Throwable) {
            // Nothing left to do.
        }
    }
}
