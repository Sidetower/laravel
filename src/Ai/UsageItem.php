<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Ai;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Stringable;
use Throwable;

/**
 * Builds a protocol `ai.usage` item (packages/protocol) from loose input. It clamps lengths, drops what the protocol would
 * refuse (negative or fractional counts, nested attributes) and leaves out what is not known: optional fields are omitted,
 * never null. Captured calls, manual records and the history import all go through it, so they all speak the same shape.
 *
 * Token meaning: `inputTokens` excludes cache read and cache write tokens, `outputTokens` includes reasoning tokens. The
 * builder does not convert; the parsers and the caller do.
 */
final class UsageItem
{
    public const STATUSES = ['ok', 'error', 'invalid_response'];

    private const ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/D';

    private const MAX_ATTRIBUTES = 20;

    /**
     * @param  array<string, mixed>  $fields  `id` and `ts` are kept when valid, the rest of the fields are the protocol's
     * @return array<string, mixed>
     */
    public static function make(array $fields): array
    {
        $item = ['type' => 'ai.usage'];

        if (is_string($fields['id'] ?? null) && preg_match(self::ID_PATTERN, $fields['id']) === 1) {
            $item['id'] = $fields['id'];
        }

        if (($ts = self::timestamp($fields['ts'] ?? null)) !== null) {
            $item['ts'] = $ts;
        }

        $status = $fields['status'] ?? null;

        $item['operation'] = self::text($fields['operation'] ?? null, 100) ?? 'unknown';
        $item['provider'] = self::text($fields['provider'] ?? null, 40) ?? 'unknown';
        $item['model'] = self::text($fields['model'] ?? null, 200) ?? 'unknown';
        $item['status'] = in_array($status, self::STATUSES, true) ? $status : 'ok';

        foreach (['inputTokens', 'outputTokens', 'cacheReadTokens', 'cacheWriteTokens', 'durationMs'] as $key) {
            if (($count = self::count($fields[$key] ?? null)) !== null) {
                $item[$key] = $count;
            }
        }

        if (($units = self::amount($fields['units'] ?? null)) !== null) {
            $item['units'] = $units;
        }

        if (($unitType = self::text($fields['unitType'] ?? null, 20)) !== null) {
            $item['unitType'] = $unitType;
        }

        if (($cost = self::amount($fields['reportedCostUsd'] ?? null)) !== null) {
            $item['reportedCostUsd'] = $cost;
        }

        $attributes = self::attributes($fields['attributes'] ?? null);

        if ($attributes !== []) {
            $item['attributes'] = $attributes;
        }

        return $item;
    }

    /**
     * At most 20 flat entries: keys of 1 to 40 characters, values text (200 characters, may be empty), finite numbers or
     * booleans. What does not fit is dropped, not guessed at.
     *
     * @return array<string, string|int|float|bool>
     */
    public static function attributes(mixed $attributes): array
    {
        if (! is_array($attributes)) {
            return [];
        }

        $clean = [];

        foreach ($attributes as $key => $value) {
            if (count($clean) >= self::MAX_ATTRIBUTES) {
                break;
            }

            $key = self::text($key, 40);
            $value = self::attributeValue($value);

            if ($key !== null && $value !== null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    private static function attributeValue(mixed $value): string|int|float|bool|null
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof DateTimeInterface) {
            $value = self::timestamp($value);
        } elseif ($value instanceof Stringable) {
            $value = (string) $value;
        }

        return match (true) {
            is_bool($value), is_int($value) => $value,
            is_float($value) => is_finite($value) ? $value : null,
            is_string($value) => mb_substr(mb_scrub($value), 0, 200),
            default => null,
        };
    }

    private static function timestamp(mixed $value): ?string
    {
        try {
            $at = match (true) {
                $value instanceof DateTimeInterface => Carbon::instance($value),
                is_int($value), is_float($value) => Carbon::createFromTimestamp($value),
                is_string($value) && $value !== '' => Carbon::parse($value),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }

        return $at?->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function text(mixed $value, int $max): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof Stringable) {
            $value = (string) $value;
        }

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim(mb_scrub($value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function count(mixed $value): ?int
    {
        if (is_string($value) && is_numeric($value)) {
            $value = (float) $value;
        }

        return match (true) {
            is_int($value) => $value >= 0 ? $value : null,
            is_float($value) => is_finite($value) && $value >= 0 ? (int) round($value) : null,
            default => null,
        };
    }

    private static function amount(mixed $value): int|float|null
    {
        if (is_string($value) && is_numeric($value)) {
            $value += 0;
        }

        return match (true) {
            is_int($value) => $value >= 0 ? $value : null,
            is_float($value) => is_finite($value) && $value >= 0 ? $value : null,
            default => null,
        };
    }
}
