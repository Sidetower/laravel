<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Tests\Import\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Sidetower\Laravel\Import\TableHistorySource;

/**
 * An Eloquent-backed source with a uuid primary key and a long table name, for ids that need shortening.
 */
final class GenerationsSource extends TableHistorySource
{
    public function name(): string
    {
        return 'lume image';
    }

    public function table(): string
    {
        return 'a_i_image_generations';
    }

    protected function idColumn(): string
    {
        return 'uuid';
    }

    protected function chunkSize(): int
    {
        return 2;
    }

    protected function query(CarbonInterface $since): Builder|EloquentBuilder
    {
        return (new class extends Model
        {
            protected $table = 'a_i_image_generations';
        })->newQuery()->where('created_at', '>=', $since);
    }

    protected function item(object $row): array
    {
        return [
            'ts' => $row->created_at,
            'operation' => 'cover.generate',
            'provider' => 'fal',
            'model' => 'fal-ai/flux/schnell',
            'units' => $row->images,
            'unitType' => 'image',
        ];
    }
}
