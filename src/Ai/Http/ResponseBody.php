<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Ai\Http;

/**
 * The decoded body of a response, or the reason there is none. Holds payloads (decoded JSON objects), never text.
 */
final class ResponseBody
{
    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    private function __construct(
        public readonly BodyState $state,
        public readonly array $payloads = [],
    ) {}

    public static function unreadable(): self
    {
        return new self(BodyState::Unreadable);
    }

    public static function invalid(): self
    {
        return new self(BodyState::Invalid);
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    public static function json(array $payloads): self
    {
        return new self(BodyState::Json, $payloads);
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    public static function stream(array $payloads): self
    {
        return new self(BodyState::Stream, $payloads);
    }
}
