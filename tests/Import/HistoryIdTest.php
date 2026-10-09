<?php

use Sidetower\Laravel\Import\HistoryId;

it('builds the stable id from source, table and row id', function () {
    expect(HistoryId::make('usage-events', 'ai_usage_events', 42))->toBe('hist-usage-events-ai_usage_events-42')
        ->and(HistoryId::make('usage-events', 'ai_usage_events', '42'))->toBe('hist-usage-events-ai_usage_events-42');
});

it('is the same on every call', function () {
    expect(HistoryId::make('a', 'b', 7))->toBe(HistoryId::make('a', 'b', 7));
});

it('replaces characters the protocol does not allow', function () {
    expect(HistoryId::make('lume image', 'ai/content generations', 'ü 1'))->toBe('hist-lume_image-ai_content_generations-_1');
});

it('keeps a long id within 64 characters and unique', function () {
    $uuidA = '0b9f2c1e-7a3d-4e51-9c0a-1f2e3d4c5b6a';
    $uuidB = '0b9f2c1e-7a3d-4e51-9c0a-1f2e3d4c5b6b';

    $a = HistoryId::make('lume-image', 'a_i_image_generations', $uuidA);
    $b = HistoryId::make('lume-image', 'a_i_image_generations', $uuidB);

    expect(strlen($a))->toBe(64)
        ->and($a)->toMatch('/^[A-Za-z0-9_.:-]{1,64}$/')
        ->and($a)->toStartWith('hist-lume-image-a_i_image_generations')
        ->and($a)->not->toBe($b)
        ->and(HistoryId::make('lume-image', 'a_i_image_generations', $uuidA))->toBe($a);
});

it('does not confuse two sources whose parts only differ in what is cut off', function () {
    $long = str_repeat('x', 80);

    expect(HistoryId::make($long, 't', 1))->not->toBe(HistoryId::make($long.'y', 't', 1));
});
