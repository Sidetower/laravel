# Changelog

All notable changes to `sidetower/laravel` are written here. The format follows [Keep a Changelog](https://keepachangelog.com),
and the package follows [semantic versioning](https://semver.org). It speaks protocol 1.

## [0.1.0] - 2026-10-09

First release.

### Added

- Items are recorded in memory with `Sidetower::record()` and written to a cache buffer when the request, command or Octane
  request ends, when a queue job finishes or fails, and at 200 items. The buffer is one locked cache key, bounded by
  `buffer.max_items`; dropped items are counted.
- `sidetower:send`, scheduled every minute without overlapping and also in maintenance mode, sends the buffer: gzip, bearer key, batches of at most 500
  items and 400 KB, at most 5 requests per run, with the retry rules of the protocol (drop on 400/401/403, split on 413, keep
  on 429/5xx/timeouts).
- A `health.snapshot` item with every run, ported from the dashboard health report of the earlier Penguess pull requests, plus
  Horizon queue detection.
- A `scheduler` check-in with every run, and check-ins for every run of every scheduled task (`schedule:<name>`), with the
  duration and the expected interval taken from the cron expression.
- A `deploy` item when the release (the `release` setting, else the commit from `.git` or a `releases/<sha>` directory)
  changes, with an id that servers share.
- A `sidetower.dropped` counter metric for items that did not fit the buffer.
- `Sidetower::fake()` with `assertRecorded()`, `assertNotRecorded()`, `assertNothingRecorded()` and `recorded()`.
- `php artisan sidetower:test`, which sends one check-in and says whether it was accepted, rejected, refused or not reached.
- Environment names are lower-cased and checked against the protocol; an invalid one switches the SDK off with one warning.
- Conformance tests against copies of the protocol's JSON Schema and fixtures (`tests/Protocol`), refreshed with
  `composer protocol:sync -- <path to packages/protocol>`.
- AI usage capture (OpenAI-compatible providers, Anthropic, Gemini incl. the Interactions API, fal, ElevenLabs) and the history import, `sidetower:import-history` with `--since`, `--until` and `--dry-run` (see `docs/ai.md`).
