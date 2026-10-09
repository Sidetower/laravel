# AI usage

Sidetower prices the AI calls your application makes. The SDK sends what each call used (tokens or units, and the cost the provider reported, if it reported one); the server turns that into dollars with its price catalog. Prompts and responses never leave your application.

Most of it needs no code: calls made through Laravel's Http client are read from the client's events. That covers [Prism](https://prismphp.com), [`laravel/ai`](https://github.com/laravel/ai) and your own `Http::post(...)` calls to a provider. You add code for two things only: to name what a call was for (operation scopes), and to report calls that do not go through the Http client (manual records).

- [What is captured](#what-is-captured)
- [Token meaning](#token-meaning)
- [Operations and attributes](#operations-and-attributes)
- [Manual records](#manual-records)
- [Privacy](#privacy)
- [Streams](#streams)
- [Other hosts](#other-hosts)
- [History import](#history-import)
- [Configuration](#configuration)

## What is captured

The SDK listens to the Http client's `ResponseReceived` and `ConnectionFailed` events. For a response from a known provider host it records one `ai.usage` item: the provider, the model, whether it worked, and what it used.

| Host | `provider` | Captured calls | Read from the response |
|---|---|---|---|
| `api.openai.com` | `openai` | Chat Completions, Responses, Embeddings | `usage` |
| `openrouter.ai` | `openrouter` | the same | `usage`, and `usage.cost` as the reported cost |
| `api.anthropic.com` | `anthropic` | Messages | `usage`, including cache reads and writes |
| `generativelanguage.googleapis.com` | `google` | `generateContent`, `streamGenerateContent` | `usageMetadata` |
| `api.mistral.ai` | `mistral` | Chat, Embeddings | `usage` (OpenAI format) |
| `api.groq.com` | `groq` | Chat, Responses | `usage` (OpenAI format) |
| `api.deepseek.com` | `deepseek` | Chat | `usage` (OpenAI format, `prompt_cache_hit_tokens`) |
| `api.x.ai` | `xai` | Chat, Responses | `usage`, and `usage.cost_in_usd_ticks` as the reported cost |
| `fal.run`, `queue.fal.run` | `fal` | the result of a generation | the number of `images`, else one request |
| `api.elevenlabs.io` | `elevenlabs` | text to speech, text to dialogue | the characters of the request's `text` |

Other endpoints of these hosts (model lists, files, token counting, batches, Gemini embeddings, which report no usage) are ignored, and so is every other host: the listener looks the host up first and returns at once when it is not in the table.

**Status.** A 2xx answer with the expected usage is `ok`. A 2xx answer that is not what the provider sends (not JSON, no usage, an HTML page from a proxy) is `invalid_response`. Any other status, an error object inside a 200 (OpenRouter does this), and a failed connection are `error`, without tokens. Every attempt of a retried call is one item.

**Model.** The model the response names (`gpt-4o-mini-2024-07-18`), else the one in the request, else the one in the path (`models/gemini-2.5-flash:generateContent`), else `unknown`.

**Duration.** `durationMs` comes from Guzzle's transfer statistics when the client has them.

**fal.** One generation takes several requests on the queue: submit, status polls, result. Only the answer that carries the result is counted: the synchronous `fal.run/<endpoint>` answer or the queue's `.../requests/<id>` result. A submit is ignored, unless it failed, which is recorded as an error because no result will follow. `units` is the number of images (`unitType: image`) or `1` (`unitType: request`).

**ElevenLabs.** The answer is audio, which is never read. `units` is the number of characters of the request's `text` (of every `inputs[].text` for a dialogue), or the `character-cost` header of the response when it has one; `unitType` is `character`. The model is the request's `model_id` (`eleven_multilingual_v2`, ElevenLabs' default, when absent).

## Token meaning

The same for every provider:

- `inputTokens` **excludes** tokens read from or written to a cache.
- `outputTokens` **includes** reasoning (thinking) tokens.
- `cacheReadTokens` and `cacheWriteTokens` are the cached tokens, counted once, here.

Providers count differently, so the SDK converts: OpenAI, OpenRouter, Mistral, Groq, DeepSeek and xAI count cached tokens inside the prompt, and the SDK subtracts them (`prompt_tokens - cached_tokens`). Google counts cached content inside `promptTokenCount`, and reports thinking tokens apart from `candidatesTokenCount`: the SDK subtracts the first and adds the second. Anthropic already reports `input_tokens` without the cache tokens. xAI reports reasoning tokens beside `completion_tokens`; the SDK adds them when `total_tokens` shows they are separate.

If you report usage yourself (manual records, history import), use the same meaning, or the server prices the cache tokens twice.

## Operations and attributes

An *operation* is what a call was for: `story.generate`, `cover.generate`. The panel breaks cost down by operation and model, so name the important ones:

```php
use Sidetower\Laravel\Facades\Sidetower;

$story = Sidetower::ai()->operation('story.generate', function () use ($prompt) {
    return Prism::text()->using('openrouter', 'google/gemini-2.5-flash')->withPrompt($prompt)->asText();
}, ['variant' => 'b', 'attempt' => 2]);
```

Every AI item recorded inside the closure, and nothing else, gets that name and those attributes. `operation()` returns what the closure returns, and the scope ends when the closure throws too.

- **Nesting.** The innermost name wins; attributes of all open scopes are merged, inner ones overriding.
- **Attributes** are flat: at most 20 entries, keys up to 40 characters, values text up to 200 characters, numbers or booleans. Anything else (nested arrays, objects, null) is dropped. Use them for things like `variant`, `attempt`, `correlationId`: never for prompts, responses or personal data.
- **Without a scope** the name is `job:<ShortClass>` inside a queue job (`job:GenerateStory`), `route:<name>` in a request to a named route, `command:<name>` in an Artisan command, else `unknown`. A job wins over a route, a route over a command. Unnamed routes (Laravel's `generated::...` names) do not count.

## Manual records

For a call that does not go through the Http client (an SDK with its own transport, a Python service, a cost you only learn later):

```php
Sidetower::ai()->record([
    'provider' => 'openrouter',
    'model' => 'google/gemini-2.5-flash',
    'inputTokens' => 300,        // without the cache tokens
    'outputTokens' => 640,       // with reasoning
    'cacheReadTokens' => 800,
    'reportedCostUsd' => 0.00184,
]);
```

| Field | |
|---|---|
| `provider`, `model` | Required in practice; `unknown` when missing. Up to 40 and 200 characters. |
| `operation` | Defaults to the current scope's name, else the default above. Up to 100 characters. |
| `status` | `ok` (default), `error` or `invalid_response`. |
| `inputTokens`, `outputTokens`, `cacheReadTokens`, `cacheWriteTokens` | Whole numbers, 0 or more. |
| `units`, `unitType` | For things not billed in tokens: `1.5` `second`, `3` `image`, `120` `character`. `unitType` up to 20 characters. |
| `reportedCostUsd` | What the provider says the call cost. When present the server uses it instead of its own estimate. |
| `durationMs` | Whole number. |
| `attributes` | Merged over the attributes of the open scopes. |

Values the protocol would refuse (negative numbers, text for a number, an unknown status) are dropped instead of failing the whole item. `record()` never throws. Do not record a call twice: a call the Http client carried is already captured.

## Privacy

The parsers pick numbers and the model name out of a response and never copy text. Prompts, responses, audio, images, headers (API keys), user ids and URLs do not enter an item. The listener also stays out of your way:

- It returns at once for every host that is not an AI provider.
- It never reads a body that is not seekable: a streamed response (the `stream` option) is a live connection, and reading it would consume it.
- It never reads a body of more than 4 MB, or one that is not JSON or an event stream (audio, images).
- A body it reads is put back where it was, so the application reads it as if nobody had.
- It never throws. What it catches is logged at `debug` level.

`ai.http` switches the listener off.

## Streams

A streamed response cannot be read without consuming it, so a call made with the `stream` option is recorded **without tokens** (`status: ok`, the model from the request). Prism's `asStream()` and `laravel/ai`'s `stream()` work this way. A response that was fully buffered before your code saw it (a faked response, a server that ignores `stream`) is read, and the last `usage` the event stream carried is used.

If you need tokens for streamed calls, record them yourself from the library's final event, and switch the token-less items off so a call is not counted twice:

```php
// config/sidetower.php
'ai' => ['streams' => false],
```

Prism ends a stream with a `StreamEndEvent` whose `usage` has `promptTokens`, `completionTokens`, `cacheReadInputTokens`, `cacheWriteInputTokens` and `thoughtTokens`. `laravel/ai` dispatches `Laravel\Ai\Events\AgentStreamed`; `$event->response->usage` has `inputTokens` (which includes cache reads and writes), `outputTokens`, `cacheReadInputTokens`, `cacheWriteInputTokens` and `reasoningTokens`, and `$event->response->meta` has the provider and the model. Convert them to the token meaning above (for `laravel/ai`: `inputTokens - cacheReadInputTokens - cacheWriteInputTokens`) and pass them to `Sidetower::ai()->record()`.

## Other hosts

`sidetower.ai.hosts` adds to the table above or overrides it, as `host => provider`:

```php
'ai' => [
    'hosts' => [
        'my-resource.openai.azure.com' => 'azure',   // a host not in the table
        '*.openai.azure.com' => 'azure',             // every subdomain
        'llm.internal.example' => 'ollama',          // a self-hosted, OpenAI-compatible server
        'api.openai.com' => 'openai-eu',             // a different provider name for a default host
        'api.groq.com' => null,                      // stop capturing a default host
    ],
],
```

A provider without a parser of its own is read as OpenAI-compatible (Chat Completions, Responses and Embeddings paths). `anthropic`, `google` (or `gemini`), `fal` and `elevenlabs` use their own parsers, so a proxy in front of one of those can be mapped to its name.

## History import

Before the SDK, an application may have recorded its AI calls in its own table. `sidetower:import-history` sends that history to Sidetower once, so the panel shows the cost from before the install.

It needs a secret (`sk_`) key. The server takes `ai.usage` items up to 400 days old; older ones are refused and counted as rejected.

### 1. Write a source

A source is a class that implements `Sidetower\Laravel\Import\HistorySource`, or, for a table, extends `TableHistorySource`: give it a query and a mapping from a row to an item.

```php
namespace App\Sidetower;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Sidetower\Laravel\Import\TableHistorySource;

class UsageEventsHistory extends TableHistorySource
{
    public function name(): string
    {
        return 'usage-events';
    }

    public function table(): string
    {
        return 'ai_usage_events';
    }

    protected function query(CarbonInterface $since): \Illuminate\Database\Query\Builder
    {
        return DB::table('ai_usage_events')->where('created_at', '>=', $since);
    }

    protected function item(object $row): ?array
    {
        return [
            'ts' => $row->created_at,
            'operation' => $row->operation,
            'provider' => $row->provider,
            'model' => $row->model,
            'status' => $row->failed ? 'error' : 'ok',
            // This table counts cached tokens inside the prompt, as OpenAI does; the protocol does not.
            'inputTokens' => max(0, $row->prompt_tokens - $row->cached_tokens),
            'outputTokens' => $row->completion_tokens,
            'cacheReadTokens' => $row->cached_tokens,
            'reportedCostUsd' => $row->cost_usd,
            'durationMs' => $row->duration_ms,
            'attributes' => ['variant' => $row->variant],
        ];
    }
}
```

An Eloquent query works too (`return AiUsageEvent::query()->where(...)`). Rows are read in chunks of 500 by `id` (override `idColumn()` for another key, `chunkSize()` for another size), so a table of millions of rows is fine. Return `null` from `item()` to skip a row.

What the base class does for you:

- **Stable ids.** Every item gets `hist-<name>-<table>-<row id>`, cut to the protocol's 64 characters of `[A-Za-z0-9_.:-]` (a hash ends an id that would be longer). The server counts an id once, so running the command twice, or again after a failure halfway, never counts a call twice. A source that implements `HistorySource` itself must make ids like that (`HistoryId::make($name, $table, $rowId)`).
- **`attributes.imported = true`** on every item.
- Whatever the table stores as cost goes in `reportedCostUsd`; the server uses it instead of its own estimate.

Keep the protocol's token meaning (above) and leave prompts, responses and user ids out of the mapping.

### 2. List it

```php
// config/sidetower.php
'ai' => [
    'history_sources' => [
        App\Sidetower\UsageEventsHistory::class,
    ],
],
```

### 3. Run it

```
php artisan sidetower:import-history --dry-run
php artisan sidetower:import-history
```

`--dry-run` sends nothing: it counts the items of every source and shows the first one, so you can check the mapping. `--since=2026-01-01` (any date Carbon reads, such as `"90 days ago"`) limits the import; the default is 400 days back.

```
Importing usage since 2025-09-04 18:00:00.
Sending to https://ingest.example.com/v1/import for environment production.
usage-events: sent 12408 in 25 batches, accepted 12401, rejected 7 (stale_item: 7).
```

Items go in batches of 500 (or less, to stay under the server's size limit), gzipped, to the import address: your `endpoint` with the trailing `/ingest` replaced by `/import`. A busy server (429, 5xx) or a failed connection is tried three times; any other refusal, such as a wrong key or environment, stops the import at once with the reason. What was sent before is stored, and the stable ids make a second run safe.

The environment is `sidetower.environment`, or the application environment in lower case, and must be the one the key was made for.

## Configuration

All keys are under `ai` in `config/sidetower.php`, and all have defaults in code, so a config file published before a key existed keeps working.

| Key | Default | |
|---|---|---|
| `http` | `true` | Read AI usage from the Http client's events. |
| `hosts` | `[]` | Hosts to add, rename or remove: `host => provider`. |
| `streams` | `true` | Record calls whose streamed answer cannot be read, without tokens. Turn off if you record streamed usage yourself. |
| `history_sources` | `[]` | Classes for `sidetower:import-history`. |
