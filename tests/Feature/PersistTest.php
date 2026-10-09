<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Sidetower\Laravel\Sidetower;

require_once __DIR__.'/../Support/helpers.php';

it('does not write to the cache while recording', function () {
    sidetower()->record(aCheckin());

    expect(buffered())->toBe([])
        ->and(sidetower()->pending())->toHaveCount(1);
});

it('persists when the request, command or Octane request ends', function () {
    sidetower()->record(aCheckin('terminating'));

    app()->terminate();

    expect(array_column(buffered(), 'monitor'))->toBe(['terminating']);

    foreach (['RequestTerminated', 'TaskTerminated', 'TickTerminated'] as $event) {
        sidetower()->record(aCheckin($event));

        Event::dispatch("Laravel\\Octane\\Events\\{$event}", [new stdClass]);
    }

    expect(array_column(buffered(), 'monitor'))->toBe(['terminating', 'RequestTerminated', 'TaskTerminated', 'TickTerminated']);
});

it('persists when a queue job is processed, fails or throws', function () {
    $job = Mockery::mock(Job::class);
    $events = [
        'processed' => new JobProcessed('sync', $job),
        'failed' => new JobFailed('sync', $job, new RuntimeException('boom')),
        'exception' => new JobExceptionOccurred('sync', $job, new RuntimeException('boom')),
    ];

    foreach ($events as $name => $event) {
        sidetower()->record(aCheckin($name));

        expect(array_column(buffered(), 'monitor'))->not->toContain($name);

        Event::dispatch($event);
    }

    expect(array_column(buffered(), 'monitor'))->toBe(['processed', 'failed', 'exception']);
});

it('persists as soon as 200 items are in memory', function () {
    foreach (range(1, Sidetower::PERSIST_THRESHOLD - 1) as $number) {
        sidetower()->record(aCheckin("m{$number}"));
    }

    expect(buffered())->toBe([]);

    sidetower()->record(aCheckin('the 200th'));

    expect(buffered())->toHaveCount(200)
        ->and(sidetower()->pending())->toBe([]);
});

it('does not try the cache at every record while it is down', function () {
    useStore('down');

    foreach (range(1, Sidetower::PERSIST_THRESHOLD + 50) as $number) {
        sidetower()->record(aCheckin("m{$number}"));
    }

    expect(sidetower()->pending())->toHaveCount(Sidetower::PERSIST_THRESHOLD + 50);
});

it('never throws out of record or persist', function () {
    useStore('down');
    config()->set('sidetower.buffer.max_items', 'many');

    sidetower()->record(aCheckin());
    sidetower()->persist();
    app()->terminate();

    expect(sidetower()->pending())->toHaveCount(1);
});

it('does nothing at all while the SDK is off', function () {
    config()->set('sidetower.enabled', false);

    sidetower()->record(aCheckin());
    app()->terminate();

    expect(sidetower()->pending())->toBe([])
        ->and(buffered())->toBe([]);
});
