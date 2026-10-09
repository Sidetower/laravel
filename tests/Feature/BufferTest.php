<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Sidetower\Laravel\Buffer;

require_once __DIR__.'/../Support/helpers.php';

it('writes the recorded items to the cache in one go and keeps their order', function () {
    sidetower()->record(aCheckin('first'));
    sidetower()->record(aCheckin('second'));

    expect(Cache::get('sidetower:buffer'))->toBeNull();

    sidetower()->persist();
    sidetower()->record(aCheckin('third'));
    sidetower()->persist();

    expect(array_column(Cache::get('sidetower:buffer'), 'monitor'))->toBe(['first', 'second', 'third'])
        ->and(sidetower()->pending())->toBe([]);
});

it('does not touch the cache when there is nothing to write', function () {
    useStore('down');

    sidetower()->persist();

    expect(sidetower()->pending())->toBe([]);
});

it('drops the oldest items past max_items and counts them', function () {
    config()->set('sidetower.buffer.max_items', 3);

    foreach (['a', 'b'] as $monitor) {
        sidetower()->record(aCheckin($monitor));
    }
    sidetower()->persist();

    foreach (['c', 'd', 'e'] as $monitor) {
        sidetower()->record(aCheckin($monitor));
    }
    sidetower()->persist();

    expect(array_column(buffered(), 'monitor'))->toBe(['c', 'd', 'e'])
        ->and(app(Buffer::class)->dropped())->toBe(2)
        ->and(Cache::get('sidetower:dropped'))->toBe(2);
});

it('keeps counting drops that come after an earlier count', function () {
    config()->set('sidetower.buffer.max_items', 1);

    foreach (['a', 'b', 'c'] as $monitor) {
        sidetower()->record(aCheckin($monitor));
        sidetower()->persist();
    }

    expect(array_column(buffered(), 'monitor'))->toBe(['c'])
        ->and(app(Buffer::class)->dropped())->toBe(2);
});

it('removes sent items by id and leaves the ones added meanwhile', function () {
    $buffer = app(Buffer::class);
    $buffer->push([['id' => 'a', 'type' => 'checkin'], ['id' => 'b', 'type' => 'checkin']]);

    $sending = $buffer->all();
    $buffer->push([['id' => 'c', 'type' => 'checkin']]);
    $buffer->remove(array_column($sending, 'id'));

    expect(array_column($buffer->all(), 'id'))->toBe(['c']);

    $buffer->remove(['c']);

    expect(Cache::has('sidetower:buffer'))->toBeFalse();
});

it('takes drops off the counter without losing the ones counted meanwhile', function () {
    $buffer = app(Buffer::class);
    $buffer->cache()->increment('sidetower:dropped', 5);

    $seen = $buffer->dropped();
    $buffer->cache()->increment('sidetower:dropped', 2);
    $buffer->forgetDropped($seen);

    expect($buffer->dropped())->toBe(2);
});

it('uses the cache store named in buffer.store', function () {
    config()->set('cache.stores.sidetower-buffer', ['driver' => 'array']);
    config()->set('sidetower.buffer.store', 'sidetower-buffer');

    sidetower()->record(aCheckin());
    sidetower()->persist();

    expect(Cache::store('sidetower-buffer')->get('sidetower:buffer'))->toHaveCount(1)
        ->and(Cache::store('array')->get('sidetower:buffer'))->toBeNull();
});

it('writes under a lock, and keeps the items in memory when the lock stays taken', function () {
    Sleep::fake(syncWithCarbon: true);
    Carbon::setTestNow(Carbon::now());

    $lock = Cache::lock('sidetower:buffer:lock', 30);
    expect($lock->get())->toBeTrue();

    sidetower()->record(aCheckin());
    sidetower()->persist();

    expect(buffered())->toBe([])
        ->and(sidetower()->pending())->toHaveCount(1);

    $lock->release();
    sidetower()->persist();

    expect(buffered())->toHaveCount(1)
        ->and(sidetower()->pending())->toBe([]);
});

it('releases the lock after a write', function () {
    sidetower()->record(aCheckin());
    sidetower()->persist();

    $lock = Cache::lock('sidetower:buffer:lock', 5);

    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('writes without a lock when the store has none', function () {
    useStore('no-lock');

    sidetower()->record(aCheckin('a'));
    sidetower()->persist();
    sidetower()->record(aCheckin('b'));
    sidetower()->persist();

    expect(array_column(buffered(), 'monitor'))->toBe(['a', 'b']);
});

it('keeps the items in memory while the cache is down and writes them when it is back', function () {
    useStore('down');

    sidetower()->record(aCheckin('a'));
    sidetower()->persist();
    sidetower()->record(aCheckin('b'));
    sidetower()->persist();

    expect(array_column(sidetower()->pending(), 'monitor'))->toBe(['a', 'b']);

    config()->set('sidetower.buffer.store', null);
    sidetower()->persist();

    expect(array_column(buffered(), 'monitor'))->toBe(['a', 'b'])
        ->and(sidetower()->pending())->toBe([]);
});

it('bounds the memory while the cache is down and counts what it dropped once the cache is back', function () {
    config()->set('sidetower.buffer.max_items', 3);
    useStore('down');

    foreach (['a', 'b', 'c', 'd', 'e'] as $monitor) {
        sidetower()->record(aCheckin($monitor));
        sidetower()->persist();
    }

    expect(array_column(sidetower()->pending(), 'monitor'))->toBe(['c', 'd', 'e']);

    config()->set('sidetower.buffer.store', null);
    sidetower()->persist();

    expect(array_column(buffered(), 'monitor'))->toBe(['c', 'd', 'e'])
        ->and(app(Buffer::class)->dropped())->toBe(2);
});

it('survives garbage in the cache', function () {
    Cache::put('sidetower:buffer', 'not a list', 60);

    sidetower()->record(aCheckin());
    sidetower()->persist();

    expect(buffered())->toHaveCount(1);
});

it('reads from the array store like any other', function () {
    expect(Cache::getStore())->toBeInstanceOf(ArrayStore::class);
});
