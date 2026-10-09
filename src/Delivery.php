<?php

declare(strict_types=1);

namespace Sidetower\Laravel;

/**
 * The answer to one request.
 */
final class Delivery
{
    /**
     * @param  ?int  $status  The HTTP status; null when there was no answer.
     * @param  ?string  $error  The protocol's error code of a refusal (`invalid_key`), or what went wrong without an answer.
     * @param  list<array{index: int, error: string}>  $rejected  Items of a 202 that the server refused one by one.
     */
    public function __construct(
        public readonly Outcome $outcome,
        public readonly ?int $status = null,
        public readonly ?string $error = null,
        public readonly int $accepted = 0,
        public readonly array $rejected = [],
    ) {}
}
