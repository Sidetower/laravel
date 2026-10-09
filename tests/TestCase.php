<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Sidetower\Laravel\SidetowerServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SidetowerServiceProvider::class];
    }

    /**
     * A configured, switched-on SDK: tests run with `runningUnitTests()` true, which would otherwise turn it off.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('sidetower.key', 'sk_test0000000000000000000000000000');
        $app['config']->set('sidetower.endpoint', 'https://sidetower.test/v1/ingest');
        $app['config']->set('sidetower.enabled', true);
    }
}
