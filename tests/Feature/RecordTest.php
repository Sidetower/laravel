<?php

use Illuminate\Support\Carbon;
use Sidetower\Laravel\Facades\Sidetower;

it('fills the id and the timestamp of a recorded item', function () {
    Carbon::setTestNow('2026-10-09T18:00:00.123+03:00');

    Sidetower::record(['type' => 'checkin', 'monitor' => 'scheduler', 'status' => 'ok']);

    $item = app(\Sidetower\Laravel\Sidetower::class)->pending()[0];

    expect($item['ts'])->toBe('2026-10-09T15:00:00.123Z')
        ->and($item['id'])->toMatch('/^[0-9a-z]{26}$/');
});

it('records nothing without a key', function () {
    config()->set('sidetower.key', null);

    Sidetower::record(['type' => 'checkin', 'monitor' => 'scheduler', 'status' => 'ok']);

    expect(app(\Sidetower\Laravel\Sidetower::class)->pending())->toBe([]);
});

it('is off in the application\'s tests unless switched on', function () {
    config()->set('sidetower.enabled', null);

    expect(Sidetower::enabled())->toBeFalse();
});
