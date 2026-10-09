<?php

use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../Support/helpers.php';

it('uses the application environment, lower-cased', function () {
    app()['env'] = 'Production';

    expect(sidetower()->environment())->toBe('production');
});

it('prefers the configured environment', function () {
    config()->set('sidetower.environment', 'Staging-2');

    expect(sidetower()->environment())->toBe('staging-2');
});

it('is off with one warning when the environment is not one the protocol knows', function (string $environment) {
    Log::spy();
    config()->set('sidetower.environment', $environment);

    expect(sidetower()->enabled())->toBeFalse()
        ->and(sidetower()->enabled())->toBeFalse();

    sidetower()->record(aCheckin());

    expect(sidetower()->pending())->toBe([]);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, $environment));
})->with(['local_dev', '1prod', 'a-very-long-environment-name', 'with space']);

it('takes an invalid APP_ENV the same way', function () {
    Log::spy();
    app()['env'] = 'under_score';

    expect(sidetower()->enabled())->toBeFalse();

    Log::shouldHaveReceived('warning')->once();
});

it('stays quiet about the environment while the SDK is not configured at all', function () {
    Log::spy();
    config()->set('sidetower.key', null);
    app()['env'] = 'under_score';

    expect(sidetower()->enabled())->toBeFalse();

    Log::shouldNotHaveReceived('warning');
});
