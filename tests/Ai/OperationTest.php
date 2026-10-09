<?php

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Routing\Route;
use Sidetower\Laravel\Facades\Sidetower;
use Sidetower\Laravel\Tests\Ai\AiTestHelpers;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

function recordCall(): void
{
    Sidetower::ai()->record(['provider' => 'openai', 'model' => 'gpt-4o-mini', 'inputTokens' => 10]);
}

function queuedJob(string $displayName): SyncJob
{
    return new SyncJob(app(), json_encode(['displayName' => $displayName, 'job' => 'Illuminate\Queue\CallQueuedHandler@call', 'data' => []]), 'sync', 'default');
}

it('gives the items recorded in a scope its name and attributes', function () {
    $result = Sidetower::ai()->operation('story.generate', function () {
        recordCall();

        return 'done';
    }, ['variant' => 'b', 'attempt' => 2]);

    expect($result)->toBe('done')
        ->and(AiTestHelpers::only())->toMatchArray([
            'operation' => 'story.generate',
            'attributes' => ['variant' => 'b', 'attempt' => 2],
        ]);
});

it('uses the innermost name and merges attributes of nested scopes', function () {
    Sidetower::ai()->operation('outer', function () {
        recordCall();

        Sidetower::ai()->operation('inner', function () {
            recordCall();
        }, ['variant' => 'inner', 'extra' => true]);

        recordCall();
    }, ['variant' => 'outer', 'attempt' => 1]);

    $items = AiTestHelpers::items();

    expect($items)->toHaveCount(3)
        ->and($items[0]['operation'])->toBe('outer')
        ->and($items[0]['attributes'])->toBe(['variant' => 'outer', 'attempt' => 1])
        ->and($items[1]['operation'])->toBe('inner')
        ->and($items[1]['attributes'])->toBe(['variant' => 'inner', 'attempt' => 1, 'extra' => true])
        ->and($items[2]['operation'])->toBe('outer')
        ->and($items[2]['attributes'])->toBe(['variant' => 'outer', 'attempt' => 1]);
});

it('ends a scope when the callback throws', function () {
    try {
        Sidetower::ai()->operation('outer', function () {
            Sidetower::ai()->operation('inner', fn () => throw new RuntimeException('boom'), ['x' => 1]);
        }, ['y' => 2]);
    } catch (RuntimeException) {
    }

    expect(Sidetower::ai()->currentOperation())->toBe('unknown')
        ->and(Sidetower::ai()->currentAttributes())->toBe([]);

    recordCall();

    expect(AiTestHelpers::only())->not->toHaveKey('attributes')
        ->and(AiTestHelpers::only()['operation'])->toBe('unknown');
});

it('lets the exception of the callback through', function () {
    expect(fn () => Sidetower::ai()->operation('x', fn () => throw new LogicException('nope')))
        ->toThrow(LogicException::class, 'nope');
});

it('lets a manual record name its own operation and attributes over the scope', function () {
    Sidetower::ai()->operation('story.generate', function () {
        Sidetower::ai()->record([
            'operation' => 'story.cover',
            'provider' => 'fal',
            'model' => 'fal-ai/flux/schnell',
            'units' => 2,
            'unitType' => 'image',
            'attributes' => ['variant' => 'manual'],
        ]);
    }, ['variant' => 'scope', 'attempt' => 3]);

    expect(AiTestHelpers::only())->toBe([
        'type' => 'ai.usage',
        'operation' => 'story.cover',
        'provider' => 'fal',
        'model' => 'fal-ai/flux/schnell',
        'status' => 'ok',
        'units' => 2,
        'unitType' => 'image',
        'attributes' => ['variant' => 'manual', 'attempt' => 3],
    ]);
});

it('records every protocol field of a manual item', function () {
    Sidetower::ai()->record([
        'operation' => 'story.generate',
        'provider' => 'openrouter',
        'model' => 'google/gemini-2.5-flash',
        'status' => 'error',
        'inputTokens' => 1200,
        'outputTokens' => 640,
        'cacheReadTokens' => 800,
        'cacheWriteTokens' => 0,
        'reportedCostUsd' => '0.00184',
        'durationMs' => 2310.4,
    ]);

    expect(AiTestHelpers::only())->toBe([
        'type' => 'ai.usage',
        'operation' => 'story.generate',
        'provider' => 'openrouter',
        'model' => 'google/gemini-2.5-flash',
        'status' => 'error',
        'inputTokens' => 1200,
        'outputTokens' => 640,
        'cacheReadTokens' => 800,
        'cacheWriteTokens' => 0,
        'durationMs' => 2310,
        'reportedCostUsd' => 0.00184,
    ]);
});

it('drops what the protocol would refuse instead of sending it', function () {
    Sidetower::ai()->record([
        'provider' => str_repeat('p', 60),
        'model' => 'm',
        'status' => 'weird',
        'inputTokens' => -5,
        'outputTokens' => 'many',
        'units' => -1,
        'reportedCostUsd' => NAN,
        'unknown' => 'field',
    ]);

    $item = AiTestHelpers::only();

    expect($item)->toBe([
        'type' => 'ai.usage',
        'operation' => 'unknown',
        'provider' => str_repeat('p', 40),
        'model' => 'm',
        'status' => 'ok',
    ]);
});

it('keeps attributes within the protocol limits', function () {
    $attributes = ['nested' => ['a' => 1], 'null' => null, 'long' => str_repeat('x', 300), 'ok' => true, str_repeat('k', 50) => 'long key'];

    foreach (range(1, 30) as $i) {
        $attributes["key{$i}"] = $i;
    }

    Sidetower::ai()->record(['provider' => 'openai', 'model' => 'gpt-4o', 'attributes' => $attributes]);

    $stored = AiTestHelpers::only()['attributes'];

    expect($stored)->toHaveCount(20)
        ->and($stored)->not->toHaveKeys(['nested', 'null'])
        ->and(mb_strlen($stored['long']))->toBe(200)
        ->and($stored[str_repeat('k', 40)])->toBe('long key')
        ->and($stored['ok'])->toBeTrue();
});

it('records nothing while the SDK is off', function () {
    config()->set('sidetower.key', null);

    recordCall();

    expect(AiTestHelpers::items())->toBe([]);
});

it('names the operation after the queue job that is running', function () {
    $job = queuedJob('App\Jobs\GenerateStory');

    event(new JobProcessing('sync', $job));
    recordCall();
    event(new JobProcessed('sync', $job));
    recordCall();

    $items = AiTestHelpers::items();

    expect($items[0]['operation'])->toBe('job:GenerateStory')
        ->and($items[1]['operation'])->toBe('unknown');
});

it('names the operation of a queued listener after its class', function () {
    $job = queuedJob('App\Listeners\SendWelcome@handle');

    event(new JobProcessing('sync', $job));
    recordCall();

    expect(AiTestHelpers::only()['operation'])->toBe('job:SendWelcome');
});

it('ends a job on any end event and a job inside a job returns to the outer one', function () {
    $outer = queuedJob('App\Jobs\Outer');
    $inner = queuedJob('App\Jobs\Inner');

    event(new JobProcessing('sync', $outer));
    event(new JobProcessing('sync', $inner));
    event(new JobAttempted('sync', $inner, null));
    event(new JobProcessed('sync', $inner));
    recordCall();
    event(new JobAttempted('sync', $outer, null));
    recordCall();

    $items = AiTestHelpers::items();

    expect($items[0]['operation'])->toBe('job:Outer')
        ->and($items[1]['operation'])->toBe('unknown');
});

it('names the operation after the named route of the request', function () {
    request()->setRouteResolver(fn () => (new Route('GET', '/stories/{story}', fn () => null))->name('stories.show'));

    recordCall();

    expect(AiTestHelpers::only()['operation'])->toBe('route:stories.show');
});

it('does not use the generated name of an unnamed route', function () {
    request()->setRouteResolver(fn () => (new Route('GET', '/stories', fn () => null))->name('generated::AbC123'));

    recordCall();

    expect(AiTestHelpers::only()['operation'])->toBe('unknown');
});

it('names the operation after the console command that is running', function () {
    $input = new ArrayInput([]);
    $output = new NullOutput;

    event(new CommandStarting('stories:backfill', $input, $output));
    recordCall();
    event(new CommandFinished('stories:backfill', $input, $output, 0));
    recordCall();

    $items = AiTestHelpers::items();

    expect($items[0]['operation'])->toBe('command:stories:backfill')
        ->and($items[1]['operation'])->toBe('unknown');
});

it('prefers a job over a route over a command, and a scope over all of them', function () {
    $input = new ArrayInput([]);
    event(new CommandStarting('queue:work', $input, new NullOutput));
    request()->setRouteResolver(fn () => (new Route('GET', '/x', fn () => null))->name('x.show'));
    $job = queuedJob('App\Jobs\Render');

    recordCall();
    event(new JobProcessing('sync', $job));
    recordCall();
    Sidetower::ai()->operation('explicit', fn () => recordCall());

    $operations = array_column(AiTestHelpers::items(), 'operation');

    expect($operations)->toBe(['route:x.show', 'job:Render', 'explicit']);
});

it('limits an operation name to 100 characters', function () {
    Sidetower::ai()->operation(str_repeat('a', 150), fn () => recordCall());

    expect(AiTestHelpers::only()['operation'])->toHaveLength(100);
});
