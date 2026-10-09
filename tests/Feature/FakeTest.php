<?php

use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\ExpectationFailedException;
use Sidetower\Laravel\Facades\Sidetower;
use Sidetower\Laravel\Testing\SidetowerFake;

require_once __DIR__.'/../Support/helpers.php';

it('records in memory, whether or not a key is set', function () {
    config()->set('sidetower.key', null);
    config()->set('sidetower.endpoint', null);
    config()->set('sidetower.enabled', null);

    $fake = Sidetower::fake();
    Sidetower::record(aCheckin('nightly'));

    expect($fake)->toBeInstanceOf(SidetowerFake::class)
        ->and($fake->recorded())->toHaveCount(1)
        ->and(Sidetower::enabled())->toBeTrue()
        ->and(app(\Sidetower\Laravel\Sidetower::class))->toBe($fake);
});

it('fills the id and the timestamp like the real one', function () {
    $fake = Sidetower::fake();
    Sidetower::record(aCheckin());

    expect($fake->recorded()[0]['id'])->toMatch('/^[0-9a-z]{26}$/')
        ->and($fake->recorded()[0]['ts'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/');
});

it('lists what was recorded, all of it or by type', function () {
    $fake = Sidetower::fake();
    Sidetower::record(aCheckin('a'));
    Sidetower::record(['type' => 'metric', 'name' => 'x', 'kind' => 'counter', 'value' => 1]);
    Sidetower::record(aCheckin('b'));

    expect($fake->recorded())->toHaveCount(3)
        ->and(array_column($fake->recorded('checkin'), 'monitor'))->toBe(['a', 'b'])
        ->and($fake->recorded('deploy'))->toBe([])
        ->and(Sidetower::recorded('metric'))->toHaveCount(1);
});

describe('assertRecorded', function () {
    it('passes for a type that was recorded', function () {
        Sidetower::fake();
        Sidetower::record(aCheckin());

        Sidetower::assertRecorded('checkin');
    });

    it('fails for a type that was not, and says what was', function () {
        Sidetower::fake();
        Sidetower::record(aCheckin());
        Sidetower::record(aCheckin());

        expect(fn () => Sidetower::assertRecorded('deploy'))->toThrow(ExpectationFailedException::class, 'The expected [deploy] item was not recorded. Recorded: checkin x2.');
    });

    it('takes a filter on the item', function () {
        Sidetower::fake();
        Sidetower::record(aCheckin('nightly', 'error'));

        Sidetower::assertRecorded('checkin', fn (array $item) => $item['status'] === 'error' && $item['monitor'] === 'nightly');

        expect(fn () => Sidetower::assertRecorded('checkin', fn (array $item) => $item['status'] === 'ok'))
            ->toThrow(ExpectationFailedException::class, 'No recorded [checkin] item satisfies the filter.');
    });

    it('fails when nothing was recorded', function () {
        Sidetower::fake();

        expect(fn () => Sidetower::assertRecorded('checkin'))->toThrow(ExpectationFailedException::class, 'Nothing was recorded.');
    });
});

describe('assertNotRecorded', function () {
    it('passes when the type was not recorded', function () {
        Sidetower::fake();
        Sidetower::record(aCheckin());

        Sidetower::assertNotRecorded('deploy');
    });

    it('fails when it was', function () {
        Sidetower::fake();
        Sidetower::record(aCheckin());

        expect(fn () => Sidetower::assertNotRecorded('checkin'))->toThrow(ExpectationFailedException::class);
    });

    it('takes a filter', function () {
        Sidetower::fake();
        Sidetower::record(aCheckin('nightly', 'ok'));

        Sidetower::assertNotRecorded('checkin', fn (array $item) => $item['status'] === 'error');

        expect(fn () => Sidetower::assertNotRecorded('checkin', fn (array $item) => $item['status'] === 'ok'))->toThrow(ExpectationFailedException::class);
    });
});

describe('assertNothingRecorded', function () {
    it('passes for a fresh fake', function () {
        Sidetower::fake();

        Sidetower::assertNothingRecorded();
    });

    it('fails when something was recorded', function () {
        Sidetower::fake();
        Sidetower::record(aCheckin());

        expect(fn () => Sidetower::assertNothingRecorded())->toThrow(ExpectationFailedException::class, 'Recorded: checkin x1.');
    });
});

it('catches what code recorded through a manager it had resolved before the fake', function () {
    $held = app(\Sidetower\Laravel\Sidetower::class);

    $fake = Sidetower::fake();
    $held->record(aCheckin('from a held instance'));

    Sidetower::assertRecorded('checkin', fn (array $item) => $item['monitor'] === 'from a held instance');
    expect($held->pending())->toBe($fake->pending())
        ->and($held->enabled())->toBeTrue();
});

it('starts empty each time it is called, and the latest one gets everything', function () {
    $held = app(\Sidetower\Laravel\Sidetower::class);

    $first = Sidetower::fake();
    Sidetower::record(aCheckin('one'));

    $second = Sidetower::fake();
    $held->record(aCheckin('two'));

    expect($first->recorded())->toHaveCount(1)
        ->and(array_column($second->recorded(), 'monitor'))->toBe(['two']);
});

it('writes nothing to the buffer and sends nothing', function () {
    Http::fake();
    Sidetower::fake();
    Sidetower::record(aCheckin());

    app()->terminate();
    $this->artisan('sidetower:send')->assertSuccessful();

    expect(buffered())->toBe([]);
    Http::assertNothingSent();
});

it('shows what the SDK itself records: the run of sidetower:send', function () {
    Http::fake();
    Sidetower::fake();

    $this->artisan('sidetower:send')->assertSuccessful();

    Sidetower::assertRecorded('health.snapshot');
    Sidetower::assertRecorded('checkin', fn (array $item) => $item['monitor'] === 'scheduler');
});

it('shows the check-ins of scheduled tasks', function () {
    Sidetower::fake();
    $task = app(Schedule::class)->call(fn () => null)->name('nightly')->daily();

    Event::dispatch(new ScheduledTaskStarting($task));

    Sidetower::assertRecorded('checkin', fn (array $item) => $item['monitor'] === 'schedule:nightly' && $item['status'] === 'started');
});
