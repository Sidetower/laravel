<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Ai;

/**
 * The AI part's on/off settings under `sidetower.ai`, each with its default in code, so the application's published config
 * file may predate them.
 */
final class Setting
{
    public static function enabled(string $key, bool $default = true): bool
    {
        return filter_var(config("sidetower.ai.{$key}") ?? $default, FILTER_VALIDATE_BOOLEAN);
    }
}
