<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Import;

/**
 * What came of sending one batch to the import endpoint.
 */
final class ImportResult
{
    /**
     * @param  array<string, int>  $rejected  item error code to number of items, e.g. `['stale_item' => 2]`
     */
    private function __construct(
        public readonly bool $ok,
        public readonly int $accepted = 0,
        public readonly array $rejected = [],
        public readonly ?string $error = null,
        public readonly bool $tooLarge = false,
    ) {}

    /**
     * @param  array<string, int>  $rejected
     */
    public static function accepted(int $accepted, array $rejected = []): self
    {
        return new self(true, $accepted, $rejected);
    }

    public static function failed(string $error): self
    {
        return new self(false, error: $error);
    }

    public static function tooLarge(): self
    {
        return new self(false, error: 'The batch is larger than the server accepts (413).', tooLarge: true);
    }

    public function rejectedCount(): int
    {
        return array_sum($this->rejected);
    }

    public function merge(self $other): self
    {
        $rejected = $this->rejected;

        foreach ($other->rejected as $code => $count) {
            $rejected[$code] = ($rejected[$code] ?? 0) + $count;
        }

        return new self(true, $this->accepted + $other->accepted, $rejected);
    }
}
