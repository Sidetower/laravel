<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Tests\Import\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Sidetower\Laravel\Import\TableHistorySource;

/**
 * The worked example of the docs: an application table of its own AI calls, read with the query builder.
 */
final class UsageEventsSource extends TableHistorySource
{
    public function name(): string
    {
        return 'usage-events';
    }

    public function table(): string
    {
        return 'ai_usage_events';
    }

    protected function query(CarbonInterface $since): Builder|EloquentBuilder
    {
        return DB::table('ai_usage_events')->where('created_at', '>=', $since);
    }

    protected function item(object $row): ?array
    {
        if ($row->provider === 'skip-me') {
            return null;
        }

        return [
            'ts' => $row->created_at,
            'operation' => $row->operation,
            'provider' => $row->provider,
            'model' => $row->model,
            'status' => $row->failed ? 'error' : 'ok',
            'inputTokens' => $row->input_tokens,
            'outputTokens' => $row->output_tokens,
            'cacheReadTokens' => $row->cached_tokens,
            'reportedCostUsd' => $row->cost_usd,
            'durationMs' => $row->duration_ms,
            'attributes' => ['variant' => $row->variant],
        ];
    }
}
