<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Import;

use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Sidetower\Laravel\Ai\UsageItem;
use Sidetower\Laravel\Sidetower;
use Throwable;

/**
 * Sends the AI usage an application stored before the SDK was installed to Sidetower, once. The sources are the classes of
 * `sidetower.ai.history_sources`; items are sent in batches of 500. Their ids are stable, so running it twice, or after a
 * failure halfway, never counts anything twice.
 */
class ImportHistoryCommand extends Command
{
    protected $signature = 'sidetower:import-history
        {--since= : Import usage from this date on (default: 400 days ago, the oldest the server takes)}
        {--dry-run : Send nothing: count the items of every source and show the first}';

    protected $description = 'Send the AI usage stored before the SDK was installed to Sidetower, once';

    private const BATCH_ITEMS = 500;

    private const BATCH_BYTES = 400_000;

    private const ITEM_BYTES = 16_384;

    public function handle(Sidetower $sidetower, ImportClient $client): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (($since = $this->since()) === null) {
            return self::FAILURE;
        }

        $sources = $this->sources();

        if ($sources === null) {
            return self::FAILURE;
        }

        if ($sources === []) {
            $this->warn('No history sources: list the classes in sidetower.ai.history_sources.');

            return self::SUCCESS;
        }

        if (! $dryRun && ! $this->ready($sidetower, $client)) {
            return self::FAILURE;
        }

        $this->line(($dryRun ? 'Dry run, nothing is sent. ' : '').'Importing usage since '.$since->toDateTimeString().'.');

        $failed = false;

        foreach ($sources as $source) {
            $failed = ! $this->importSource($source, $since, $client, $dryRun);

            if ($failed) {
                break;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    protected function since(): ?CarbonInterface
    {
        $since = $this->option('since');

        if (! is_string($since) || $since === '') {
            return Carbon::now()->subDays(400);
        }

        try {
            return Carbon::parse($since);
        } catch (Throwable) {
            $this->error("--since={$since} is not a date.");

            return null;
        }
    }

    /**
     * @return list<HistorySource>|null
     */
    protected function sources(): ?array
    {
        $sources = [];

        foreach ((array) config('sidetower.ai.history_sources', []) as $source) {
            try {
                $source = is_string($source) ? $this->laravel->make($source) : $source;
            } catch (Throwable $e) {
                $this->error('History source '.(is_string($source) ? $source : get_debug_type($source)).' could not be made: '.$e->getMessage());

                return null;
            }

            if (! $source instanceof HistorySource) {
                $this->error('History source '.get_debug_type($source).' does not implement '.HistorySource::class.'.');

                return null;
            }

            $sources[] = $source;
        }

        return $sources;
    }

    protected function ready(Sidetower $sidetower, ImportClient $client): bool
    {
        if (! $sidetower->enabled()) {
            $this->error('Sidetower is off: '.$sidetower->offBecause().'.');

            return false;
        }

        if ($client->endpoint() === null) {
            $this->error('The import address cannot be derived: the endpoint '.config('sidetower.endpoint').' does not end in /ingest.');

            return false;
        }

        if ($client->environment() === null) {
            $this->error('The environment name is not one Sidetower accepts (lower case, letters, digits and dashes, 20 characters at most): set SIDETOWER_ENVIRONMENT.');

            return false;
        }

        $this->line('Sending to '.$client->endpoint().' for environment '.$client->environment().'.');

        return true;
    }

    /**
     * Reads one source and sends it. False when the import has to stop.
     */
    protected function importSource(HistorySource $source, CarbonInterface $since, ImportClient $client, bool $dryRun): bool
    {
        $name = $source->name();
        $counts = ['read' => 0, 'skipped' => 0, 'batches' => 0, 'accepted' => 0];
        $rejected = [];
        $first = null;
        $batch = [];
        $bytes = 0;

        $flush = function () use (&$batch, &$bytes, &$counts, &$rejected, $client): ?string {
            if ($batch === []) {
                return null;
            }

            $result = $client->send($batch);
            $counts['batches']++;
            $batch = [];
            $bytes = 0;

            if (! $result->ok) {
                return $result->error;
            }

            $counts['accepted'] += $result->accepted;

            foreach ($result->rejected as $code => $count) {
                $rejected[$code] = ($rejected[$code] ?? 0) + $count;
            }

            return null;
        };

        try {
            foreach ($source->items($since) as $raw) {
                $item = $this->prepare($raw);
                $size = $item === null ? 0 : strlen((string) json_encode($item)) + 1;

                if ($item === null || $size > self::ITEM_BYTES) {
                    $counts['skipped']++;

                    continue;
                }

                $counts['read']++;
                $first ??= $item;

                if ($dryRun) {
                    continue;
                }

                if ($batch !== [] && (count($batch) >= self::BATCH_ITEMS || $bytes + $size > self::BATCH_BYTES)) {
                    if (($error = $flush()) !== null) {
                        return $this->stop($name, $error);
                    }
                }

                $batch[] = $item;
                $bytes += $size;
            }

            if (! $dryRun && ($error = $flush()) !== null) {
                return $this->stop($name, $error);
            }
        } catch (Throwable $e) {
            return $this->stop($name, 'The source failed: '.$e->getMessage());
        }

        $this->report($name, $counts, $rejected, $first, $dryRun);

        return true;
    }

    /**
     * An item in the shape the protocol takes, or null when it has no valid `id` or `ts`. Imported items are marked as such.
     *
     * @return array<string, mixed>|null
     */
    protected function prepare(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $attributes = is_array($raw['attributes'] ?? null) ? $raw['attributes'] : [];
        $item = UsageItem::make([...$raw, 'attributes' => ['imported' => true, ...array_diff_key($attributes, ['imported' => true])]]);

        return isset($item['id'], $item['ts']) ? $item : null;
    }

    /**
     * @param  array{read: int, skipped: int, batches: int, accepted: int}  $counts
     * @param  array<string, int>  $rejected
     * @param  array<string, mixed>|null  $first
     */
    protected function report(string $name, array $counts, array $rejected, ?array $first, bool $dryRun): void
    {
        $skipped = $counts['skipped'] > 0 ? ", skipped {$counts['skipped']} without a valid id or time" : '';

        if ($dryRun) {
            $batches = (int) ceil($counts['read'] / self::BATCH_ITEMS);
            $this->info("{$name}: {$counts['read']} items in about {$batches} batches{$skipped}.");

            if ($first !== null) {
                $this->line('First item: '.json_encode($first, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            return;
        }

        $rejectedCount = array_sum($rejected);
        $detail = $rejectedCount > 0
            ? ' ('.implode(', ', array_map(fn (string $code, int $count): string => "{$code}: {$count}", array_keys($rejected), $rejected)).')'
            : '';

        $this->info("{$name}: sent {$counts['read']} in {$counts['batches']} batches, accepted {$counts['accepted']}, rejected {$rejectedCount}{$detail}{$skipped}.");
    }

    protected function stop(string $name, string $reason): bool
    {
        $this->error("{$name}: stopped. {$reason}");
        $this->line('What was sent before is stored. Run the command again when the cause is gone: item ids are stable, so nothing is counted twice.');

        return false;
    }
}
