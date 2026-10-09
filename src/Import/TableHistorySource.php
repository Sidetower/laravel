<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Import;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * A history source that reads one table: a query for the rows since a date, and a mapping from a row to the fields of an
 * `ai.usage` item. The base class pages through the rows by id (so a table of millions of rows is read in chunks), gives
 * every item its stable id `hist-<name>-<table>-<row id>` and marks it `attributes.imported = true`.
 *
 *     class UsageEventsHistory extends TableHistorySource
 *     {
 *         public function name(): string { return 'usage-events'; }
 *
 *         public function table(): string { return 'ai_usage_events'; }
 *
 *         protected function query(CarbonInterface $since): Builder
 *         {
 *             return DB::table('ai_usage_events')->where('created_at', '>=', $since);
 *         }
 *
 *         protected function item(object $row): array
 *         {
 *             return [
 *                 'ts' => $row->created_at,
 *                 'operation' => $row->operation,
 *                 'provider' => $row->provider,
 *                 'model' => $row->model,
 *                 'status' => $row->failed ? 'error' : 'ok',
 *                 'inputTokens' => $row->input_tokens,
 *                 'outputTokens' => $row->output_tokens,
 *                 'reportedCostUsd' => $row->cost_usd,
 *             ];
 *         }
 *     }
 *
 * Token meaning is the protocol's: `inputTokens` excludes cache read and write tokens, `outputTokens` includes reasoning
 * tokens. Convert in `item()` if the table counts them differently.
 */
abstract class TableHistorySource implements HistorySource
{
    /**
     * The table the rows come from. It is part of the item ids, so two tables of one source never collide.
     */
    abstract public function table(): string;

    /**
     * The rows since the given moment. Leave the ordering alone: rows are paged by `idColumn()`.
     */
    abstract protected function query(CarbonInterface $since): Builder|EloquentBuilder;

    /**
     * One row as the fields of an `ai.usage` item, `ts` (when it happened) included; `id` is set by this class. Null skips
     * the row. Put what the table knows about the cost in `reportedCostUsd`. No prompts, no responses, no user ids.
     *
     * @return array<string, mixed>|null
     */
    abstract protected function item(object $row): ?array;

    protected function idColumn(): string
    {
        return 'id';
    }

    protected function chunkSize(): int
    {
        return 500;
    }

    public function items(CarbonInterface $since): iterable
    {
        foreach ($this->query($since)->lazyById($this->chunkSize(), $this->idColumn()) as $row) {
            $fields = $this->item($row);
            $rowId = data_get($row, $this->idColumn());

            if ($fields === null || (! is_string($rowId) && ! is_int($rowId))) {
                continue;
            }

            $attributes = is_array($fields['attributes'] ?? null) ? $fields['attributes'] : [];

            yield [
                ...$fields,
                'id' => HistoryId::make($this->name(), $this->table(), $rowId),
                'attributes' => ['imported' => true, ...array_diff_key($attributes, ['imported' => true])],
            ];
        }
    }
}
