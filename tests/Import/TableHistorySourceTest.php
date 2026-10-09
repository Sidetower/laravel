<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sidetower\Laravel\Tests\Import\Support\GenerationsSource;
use Sidetower\Laravel\Tests\Import\Support\UsageEventsSource;

beforeEach(function () {
    Schema::create('ai_usage_events', function (Blueprint $table) {
        $table->id();
        $table->string('operation');
        $table->string('provider');
        $table->string('model');
        $table->boolean('failed')->default(false);
        $table->unsignedInteger('input_tokens')->nullable();
        $table->unsignedInteger('output_tokens')->nullable();
        $table->unsignedInteger('cached_tokens')->nullable();
        $table->decimal('cost_usd', 12, 6)->nullable();
        $table->unsignedInteger('duration_ms')->nullable();
        $table->string('variant')->nullable();
        $table->timestamp('created_at')->nullable();
    });
});

function usageEvent(array $overrides = []): void
{
    DB::table('ai_usage_events')->insert([
        'operation' => 'story.generate',
        'provider' => 'openrouter',
        'model' => 'google/gemini-2.5-flash',
        'failed' => false,
        'input_tokens' => 1200,
        'output_tokens' => 640,
        'cached_tokens' => 800,
        'cost_usd' => '0.001840',
        'duration_ms' => 2310,
        'variant' => 'b',
        'created_at' => '2026-09-01 10:15:30',
        ...$overrides,
    ]);
}

it('turns the rows of a table into items with stable ids', function () {
    usageEvent();
    usageEvent(['failed' => true, 'input_tokens' => null, 'output_tokens' => null, 'cached_tokens' => null, 'cost_usd' => null, 'created_at' => '2026-09-02 11:00:00']);

    $items = iterator_to_array((new UsageEventsSource)->items(Carbon::parse('2026-01-01')), false);

    expect($items)->toHaveCount(2)
        ->and($items[0])->toEqual([
            'ts' => '2026-09-01 10:15:30',
            'operation' => 'story.generate',
            'provider' => 'openrouter',
            'model' => 'google/gemini-2.5-flash',
            'status' => 'ok',
            'inputTokens' => 1200,
            'outputTokens' => 640,
            'cacheReadTokens' => 800,
            'reportedCostUsd' => '0.001840',
            'durationMs' => 2310,
            'attributes' => ['imported' => true, 'variant' => 'b'],
            'id' => 'hist-usage-events-ai_usage_events-1',
        ])
        ->and($items[1]['id'])->toBe('hist-usage-events-ai_usage_events-2')
        ->and($items[1]['status'])->toBe('error');
});

it('reads only the rows the query asks for and lets the mapping skip rows', function () {
    usageEvent(['created_at' => '2025-12-31 23:59:59']);
    usageEvent(['provider' => 'skip-me']);
    usageEvent();

    $ids = array_column(iterator_to_array((new UsageEventsSource)->items(Carbon::parse('2026-01-01')), false), 'id');

    expect($ids)->toBe(['hist-usage-events-ai_usage_events-3']);
});

it('pages through a table in chunks by its id column, with Eloquent and a uuid key', function () {
    Schema::create('a_i_image_generations', function (Blueprint $table) {
        $table->uuid('uuid')->primary();
        $table->unsignedInteger('images');
        $table->timestamp('created_at')->nullable();
    });

    foreach (range(1, 5) as $i) {
        DB::table('a_i_image_generations')->insert(['uuid' => sprintf('0b9f2c1e-7a3d-4e51-9c0a-1f2e3d4c5b6%d', $i), 'images' => $i, 'created_at' => '2026-09-01 10:00:00']);
    }

    $items = iterator_to_array((new GenerationsSource)->items(Carbon::parse('2026-01-01')), false);

    expect($items)->toHaveCount(5)
        ->and(array_column($items, 'units'))->toBe([1, 2, 3, 4, 5])
        ->and(collect($items)->pluck('id')->unique())->toHaveCount(5)
        ->and(collect($items)->every(fn (array $item) => strlen($item['id']) <= 64 && preg_match('/^[A-Za-z0-9_.:-]+$/', $item['id']) === 1))->toBeTrue()
        ->and($items[0]['id'])->toStartWith('hist-lume_image-a_i_image_generations-');
});
