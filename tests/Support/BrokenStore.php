<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Tests\Support;

use Illuminate\Contracts\Cache\Store;
use RuntimeException;

/**
 * A cache that is down: every call throws, like a Redis that cannot be reached.
 */
class BrokenStore implements Store
{
    public function get($key)
    {
        $this->fail();
    }

    public function many(array $keys)
    {
        $this->fail();
    }

    public function put($key, $value, $seconds)
    {
        $this->fail();
    }

    public function putMany(array $values, $seconds)
    {
        $this->fail();
    }

    public function increment($key, $value = 1)
    {
        $this->fail();
    }

    public function decrement($key, $value = 1)
    {
        $this->fail();
    }

    public function forever($key, $value)
    {
        $this->fail();
    }

    public function touch($key, $seconds)
    {
        $this->fail();
    }

    public function forget($key)
    {
        $this->fail();
    }

    public function flush()
    {
        $this->fail();
    }

    public function getPrefix()
    {
        return '';
    }

    protected function fail(): never
    {
        throw new RuntimeException('Connection refused');
    }
}
