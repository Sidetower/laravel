<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Tests\Ai;

use Sidetower\Laravel\Sidetower;

/**
 * Reads back what the AI part recorded, from the SDK's in-memory items.
 */
final class AiTestHelpers
{
    /**
     * The recorded `ai.usage` items, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function items(): array
    {
        return array_values(array_filter(
            app(Sidetower::class)->pending(),
            fn (array $item): bool => ($item['type'] ?? null) === 'ai.usage',
        ));
    }

    /**
     * The only recorded `ai.usage` item, without its generated `id` and `ts`.
     *
     * @return array<string, mixed>
     */
    public static function only(): array
    {
        $items = self::items();

        expect($items)->toHaveCount(1);

        return array_diff_key($items[0], ['id' => 1, 'ts' => 1]);
    }
}
