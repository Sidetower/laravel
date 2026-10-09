<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Key and endpoint
    |--------------------------------------------------------------------------
    |
    | The secret key (sk_...) of this server component, made on the project's
    | settings page, and the ingest address the page shows next to it. Without
    | both the SDK records nothing and sends nothing.
    |
    */

    'key' => env('SIDETOWER_KEY'),

    'endpoint' => env('SIDETOWER_ENDPOINT'),

    /*
    |--------------------------------------------------------------------------
    | Switches
    |--------------------------------------------------------------------------
    |
    | `enabled` turns the whole SDK off. It is also off while the application
    | runs its own tests, unless `SIDETOWER_ENABLED=true` is set explicitly.
    |
    */

    'enabled' => env('SIDETOWER_ENABLED'),

    /*
    | Must match the environment the key was made for. Null = the application
    | environment (APP_ENV), lower-cased. A name that is not lower-case letters,
    | digits and hyphens, starting with a letter, at most 20 characters
    | (production, staging, eu-west), switches the SDK off with one warning in
    | the log.
    */
    'environment' => env('SIDETOWER_ENVIRONMENT'),

    /*
    | Null = the commit read from .git or from a releases/<sha> directory.
    */
    'release' => env('SIDETOWER_RELEASE'),

    /*
    | Seconds. Sending happens in a scheduled command, never in a request,
    | so a slow or unreachable endpoint only delays that command this long.
    */
    'timeout' => (float) env('SIDETOWER_TIMEOUT', 2.0),

    /*
    |--------------------------------------------------------------------------
    | Buffer
    |--------------------------------------------------------------------------
    |
    | Items wait in the cache until the scheduled `sidetower:send` command
    | sends them, once a minute. When the buffer is full the oldest items are
    | dropped. Null store = the default cache store.
    |
    */

    'buffer' => [
        'store' => env('SIDETOWER_CACHE_STORE'),
        'max_items' => 5000,
    ],

    /*
    |--------------------------------------------------------------------------
    | What is collected
    |--------------------------------------------------------------------------
    */

    'health' => [
        'enabled' => true,
        /*
        | Queues whose pending jobs are counted. Null = the queues of the
        | Horizon supervisors when Horizon is installed, else ['default'].
        */
        'queues' => null,
    ],

    'checkins' => [
        /* One check-in per run of every scheduled task. */
        'scheduled_tasks' => true,
        /*
        | Tasks that are not reported: their name (the description, else the
        | command without php and artisan), `schedule:` and the name, or the
        | whole command. `*` matches any text: 'telescope:*'. The SDK's own
        | sidetower:send is never reported.
        */
        'ignore' => [],
    ],

    /* A deploy item when the release changes. */
    'deploys' => true,

    'ai' => [
        /*
        | Reads token and unit usage from the responses of AI providers that
        | the Http client receives (Prism and laravel/ai use it). Prompts and
        | responses themselves are never sent.
        */
        'http' => true,

        /*
        | Extra hosts read as AI providers, host => provider name. A provider
        | without a parser of its own is read as OpenAI-compatible.
        | '*.example.com' matches every subdomain; null removes a default host.
        | Example: ['llm.internal.example.com' => 'openai']
        */
        'hosts' => [],

        /*
        | Streamed calls are recorded without tokens. Switch off when the
        | application records streamed usage itself (docs/ai.md).
        */
        'streams' => true,

        /*
        | Classes implementing Sidetower\Laravel\Import\HistorySource, read once
        | by `php artisan sidetower:import-history`.
        */
        'history_sources' => [],
    ],

];
