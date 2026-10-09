# Sidetower for Laravel

The Laravel SDK of [Sidetower](https://sidetower.com). It tells Sidetower how your server is doing, whether your scheduled
tasks ran, when you deployed, and what your AI calls cost. Two lines in `.env`, and it reports by itself.

- **Server health** once a minute: PHP and Laravel versions, load, memory, disk, database, cache, queue backlog, failed jobs.
- **Scheduler heartbeat** and **a check-in for every run of every scheduled task**, so a backup that did not run is noticed.
- **Deploys**, found by the release or the commit changing.
- **AI usage** (tokens, units, model, provider) read from the Http client. See [AI usage](#ai-usage).

Nothing is ever sent while a request is being served. Items wait in your cache and leave once a minute from the scheduler.

Requires PHP 8.3 or newer, Laravel 12 or 13, and the `zlib` extension. MIT licensed.

## Install

```bash
composer require sidetower/laravel
```

Until the package is on Packagist, add the repository to your `composer.json` first:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/sidetower/laravel" }
]
```

Make a secret key for the server component on the project's page in Sidetower, then put it in `.env` with the ingest address
that page shows next to it:

```dotenv
SIDETOWER_KEY=sk_...
SIDETOWER_ENDPOINT=https://your-sidetower.example.com/v1/ingest
```

Check the installation:

```bash
php artisan sidetower:test
```

```
   INFO  Sending a check-in to https://your-sidetower.example.com/v1/ingest as environment 'production'.
   INFO  Accepted: Sidetower received the check-in. Make sure `php artisan schedule:run` runs every minute, so the SDK keeps sending.
```

The SDK sends from a command that Laravel's scheduler runs every minute, so the usual cron entry must be there:

```cron
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Nothing else to register: the service provider is discovered, and it adds `sidetower:send` to the schedule itself
(`everyMinute()`, `withoutOverlapping()`, not `onOneServer()`; every server reports its own health).

## Configuration

`config/sidetower.php` works as it is. To change something:

```bash
php artisan vendor:publish --tag=sidetower-config
```

| Key | Env | Default | |
|---|---|---|---|
| `key` | `SIDETOWER_KEY` | | The secret (`sk_`) key of this server component. Never a publishable (`pk_`) key. |
| `endpoint` | `SIDETOWER_ENDPOINT` | | The ingest address. |
| `enabled` | `SIDETOWER_ENABLED` | | `false` switches the SDK off. Unset: on when key and endpoint are set, and always off while your application runs its tests. |
| `environment` | `SIDETOWER_ENVIRONMENT` | `APP_ENV` | Must be the environment the key was made for. Lower-cased. If it is not lower-case letters, digits and hyphens, starting with a letter, at most 20 characters, the SDK stays off and logs one warning. |
| `release` | `SIDETOWER_RELEASE` | | The name of the release. Unset: the 7-character commit. |
| `timeout` | `SIDETOWER_TIMEOUT` | `2.0` | Seconds, for connecting and for the whole request. Only `sidetower:send` waits on it. |
| `buffer.store` | `SIDETOWER_CACHE_STORE` | default store | The cache store that holds the waiting items. |
| `buffer.max_items` | | `5000` | When the buffer is full the oldest items are dropped and counted. |
| `health.enabled` | | `true` | A `health.snapshot` item with every run. |
| `health.queues` | | `null` | Queues whose pending jobs are counted. `null`: the queues of your Horizon supervisors, else `['default']`. |
| `checkins.scheduled_tasks` | | `true` | A check-in for every run of every scheduled task. |
| `checkins.ignore` | | `[]` | Tasks that are not reported (names, commands, `*` wildcards). |
| `deploys` | | `true` | A `deploy` item when the release changes. |
| `ai.http` | | `true` | Read AI usage from the responses of the Http client. |

Use a cache store that every server and queue worker shares (Redis, the database) when you run more than one. With a
per-server store (`file`) each server sends its own items, which works too.

## What is collected

| Item | When | Holds |
|---|---|---|
| `health.snapshot` | every run of `sidetower:send` | PHP, Laravel, environment and commit; hostname, CPU count, load, memory, disk of `base_path()`, uptime (from `/proc`, empty where it cannot be read); database reachable and latency; cache works; pending jobs per queue and failed jobs (last 24 hours, total); when `sidetower:send` last ran. A part that cannot be read becomes `null` and its error message is listed under `errors`. |
| `checkin` `scheduler` | every run | The heartbeat of the scheduler. Expected every 60 seconds. |
| `checkin` `schedule:<task>` | start and end of every scheduled task run | `started`, then `ok` or `error`, with the duration, the expected interval (from the cron expression, between 60 seconds and 31 days) and, for an error, the exception message or exit code. |
| `deploy` | the first run after the release changes | The release, and the commit and branch when known. One deploy per release and environment, however many servers notice it. |
| `ai.usage` | after every AI call | See [AI usage](#ai-usage). |
| `metric` `sidetower.dropped` | after items were dropped | A counter of the items that did not fit the buffer. |

**Task names.** `schedule:` and the description of the task if it has one, else its command without the PHP binary and
`artisan` (`'/usr/bin/php8.4' 'artisan' backup:run --only-db` becomes `schedule:backup:run --only-db`), else `schedule:callback`.
Give closures a name with `->name('prune-tokens')`. At most 100 characters. Tasks run in the background report when the
background process finishes. A run that is skipped because the previous one still runs (`withoutOverlapping()`) is not
reported as `ok`.

**Release and commit.** `SIDETOWER_RELEASE` if set. Otherwise the commit is read from `.git/HEAD` (loose refs and
`packed-refs`) or from the name of a `releases/<sha>` directory (Envoyer, Deployer, Capistrano). No `git` process is started.

## Sending, and when it fails

```
Sidetower::record()  →  memory  →  cache buffer  →  sidetower:send (every minute)  →  POST {endpoint}
   no I/O              end of request,   one key,          health, check-in, deploy,        gzip, bearer key,
                       command or job    locked            then the buffer                  `timeout` seconds
```

- `Sidetower::record()` only keeps the item in memory. The items are written to the cache when the request, command or
  Octane request ends, when a queue job finishes or fails, and as soon as 200 items have piled up.
- `sidetower:send` sends at most 500 items and 400 KB (before compression) per request, and at most 5 requests per run. What is
  left goes in the next minute.
- It never fails the scheduler: the command exits with success whatever the server answered.

| The server answers | What the SDK does |
|---|---|
| `202` | The items leave the buffer. Items it listed as `rejected` leave too (they would be refused again); the count and error codes are logged once at `debug` level. |
| `400` (`invalid_envelope`, `environment_mismatch`, `unsupported_protocol`), `401`, `403`, other `4xx` | Sending the same bytes again cannot work. The batch is dropped and one `warning` is logged that says what to check. |
| `413` | The batch is split in two and the halves are sent. A single item that is still too large is dropped. |
| `408`, `429`, `5xx`, a timeout, no connection | The items stay in the buffer and this run stops. The next run tries again. |
| Anything else (a redirect, a `200` that is not the server) | The items stay and one `warning` is logged. Redirects are never followed, so the key does not travel to another address. |

Nothing the SDK does can throw into your application: recording, persisting, the listeners and the commands catch what they
meet and log at most at `debug` level. If the cache itself is down, items wait in memory (bounded by `buffer.max_items`).

When `buffer.max_items` is exceeded the oldest items go first. They are counted, and the count is sent as the counter
`sidetower.dropped` with the next run.

## Privacy

The SDK sends numbers and names, never content:

- No prompts, no responses, no request or response bodies, no user ids, e-mail addresses or IP addresses.
- AI usage carries token counts, units, model, provider and the name of the operation (`job:SummariseArticle`).
- Scheduled task check-ins carry the task name and, on failure, the exception message (cut to 500 characters). If your
  exceptions put personal data into their messages, add the task to `checkins.ignore`.
- The health report carries the hostname, the path of the application directory and the messages of the parts that failed.
- The key travels only in the `Authorization` header, and is never logged.

## Recording your own items

```php
use Sidetower\Laravel\Facades\Sidetower;

Sidetower::record([
    'type' => 'metric',
    'name' => 'orders.imported',
    'kind' => 'counter',
    'value' => 120,
]);
```

`id` and `ts` are filled in. The shapes of the item types are in the protocol documentation
(`packages/protocol/README.md` in the Sidetower repository). An item that does not fit them is refused by the server and
logged at `debug` level; the rest of the batch is unaffected.

## AI usage

Calls made through Laravel's Http client are picked up without any code; so are those of a library such as Prism or
`laravel/ai` if it makes its requests through that client. Name the operation a call belongs to, record calls that do not go
through the Http client, and import old cost tables: see [docs/ai.md](docs/ai.md).

## Testing your application

While your application runs its tests the SDK is off: it records nothing and sends nothing, with no setup. To assert on what
your code reports, use the fake:

```php
use Sidetower\Laravel\Facades\Sidetower;

it('records a metric when orders are imported', function () {
    Sidetower::fake();

    (new ImportOrders)->handle();

    Sidetower::assertRecorded('metric', fn (array $item) => $item['name'] === 'orders.imported' && $item['value'] === 120);
    Sidetower::assertNotRecorded('deploy');
});
```

The fake keeps the items in memory, records whether or not a key is set, and also catches items that code recorded through a
`Sidetower` it had resolved earlier.

| Method | |
|---|---|
| `Sidetower::fake()` | Returns the fake. Call it again to start with an empty one. |
| `assertRecorded(string $type, ?Closure $filter = null)` | At least one item of the type (that the filter, given the item as an array, accepts). |
| `assertNotRecorded(string $type, ?Closure $filter = null)` | No such item. |
| `assertNothingRecorded()` | Nothing at all. |
| `recorded(?string $type = null): array` | The items, all or of one type. |

To test with the real pipeline instead, switch the SDK on with `SIDETOWER_ENABLED=true` and fake the Http client
(`Http::fake()`).

## Troubleshooting

`php artisan sidetower:test` sends one check-in, right now, and says what became of it:

| It says | Look at |
|---|---|
| `Sidetower is off: ...` | The reason is in the sentence: no key, no endpoint, switched off, a test run, or an environment name the protocol does not accept. |
| `Accepted` | All fine. If nothing shows up later, check that `schedule:run` runs every minute. |
| `Rejected: ... as <code>` | The server read the request but refused the item. |
| `Refused: ... HTTP 401 (invalid_key)` | `SIDETOWER_KEY`. `environment_mismatch` means the key belongs to another environment than `SIDETOWER_ENVIRONMENT` / `APP_ENV`. |
| `Could not reach Sidetower: ...` | `SIDETOWER_ENDPOINT`, the network, a firewall. |

`php artisan sidetower:send` runs the whole routine once and prints how many items were sent, rejected, dropped and are
still waiting. Warnings and refusals are in your application log.

## Development

```bash
composer install
vendor/bin/pest          # tests
vendor/bin/pint          # code style
```

`tests/Protocol` holds copies of the protocol's JSON Schema and conformance fixtures; every envelope the SDK produces is
validated against them. After the protocol changes in the Sidetower repository, refresh them:

```bash
composer protocol:sync -- /path/to/sidetower/packages/protocol
```

## License

MIT. See [LICENSE](LICENSE).
