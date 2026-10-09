<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Import;

/**
 * The id of an imported item: `hist-<source>-<table>-<row id>`, which is the same on every run, so importing twice does not
 * count twice. The protocol allows 64 characters of `[A-Za-z0-9_.:-]`; anything else becomes `_`, and an id that would be
 * longer keeps its start and ends in a hash of the full parts, so it stays unique.
 */
final class HistoryId
{
    private const MAX_LENGTH = 64;

    public static function make(string $source, string $table, string|int $rowId): string
    {
        $id = implode('-', ['hist', ...array_map(self::clean(...), [$source, $table, (string) $rowId])]);

        if (strlen($id) <= self::MAX_LENGTH) {
            return $id;
        }

        $hash = substr(hash('sha256', implode('|', [$source, $table, (string) $rowId])), 0, 12);

        return substr($id, 0, self::MAX_LENGTH - strlen($hash) - 1).'-'.$hash;
    }

    private static function clean(string $part): string
    {
        return preg_replace('/[^A-Za-z0-9_.:-]+/', '_', $part) ?? '';
    }
}
