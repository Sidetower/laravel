<?php

declare(strict_types=1);

namespace Sidetower\Laravel;

/**
 * What one emptying of the buffer did.
 */
final class Drain
{
    public int $requests = 0;

    public int $sent = 0;

    public int $rejected = 0;

    public int $dropped = 0;

    /** Items still in the buffer afterwards. */
    public int $remaining = 0;

    /**
     * Every request made, with the ids of the items it carried.
     *
     * @var list<array{ids: list<string>, delivery: Delivery}>
     */
    public array $attempts = [];

    /**
     * The last request that carried this item (a batch the server found too large is sent again in halves), and the item's
     * place in it. Null if the item was not sent in this run.
     *
     * @return array{delivery: Delivery, index: int}|null
     */
    public function attemptFor(string $id): ?array
    {
        foreach (array_reverse($this->attempts) as $attempt) {
            $index = array_search($id, $attempt['ids'], true);

            if ($index !== false) {
                return ['delivery' => $attempt['delivery'], 'index' => $index];
            }
        }

        return null;
    }
}
