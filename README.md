<p align="center">
    <a href="https://heybug.io" target="_blank"><img width="130" src="https://heybug.io/logo.png"></a>
</p>

# HeyBug

Laravel 12.x &amp; 13.x package for logging errors to [heybug.io](https://heybug.io)

[![Software License](https://poser.pugx.org/heybugio/heybugio/license.svg)](LICENSE.md)
[![Latest Version on Packagist](https://poser.pugx.org/heybugio/heybugio/v/stable.svg)](https://packagist.org/packages/heybugio/heybugio)
[![Total Downloads](https://poser.pugx.org/heybugio/heybugio/d/total.svg)](https://packagist.org/packages/heybugio/heybugio)

## Version Support

| PHP   | Laravel    |
|-------|------------|
| 8.2+  | 12.x, 13.x |

## Installation

Install the package via composer:

```bash
composer require heybugio/heybugio
```

Publish the config file:

```bash
php artisan vendor:publish --provider="HeyBug\HeyBugServiceProvider"
```

## Configuration

Add the following environment variables to your `.env`:

```env
HEYBUG_API_KEY=your-api-key
HEYBUG_PROJECT_ID=your-project-id
```

Get your API key and project ID from [heybug.io](https://heybug.io) after creating a project.

## Testing Your Configuration

Verify your configuration is working:

```bash
php artisan heybug:test
```

## Reporting Unhandled Exceptions

Add HeyBug as a log channel in `config/logging.php`:

```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single', 'heybug'],
    ],

    'heybug' => [
        'driver' => 'heybug',
        'level' => 'error',
    ],
],
```

That's it! All unhandled exceptions will now be reported to HeyBug.

**Note:** By default, only production environments will report errors. You can adjust this in the `config/heybug.php` file.

**Note:** This channel reports *exceptions* only. A log call with no exception attached — `Log::error('Gateway returned 500', ['order_id' => 1])` — is written by your other channels but is not sent to HeyBug. Attach the exception to report it:

```php
Log::error('Gateway returned 500', ['exception' => $e]);
```

## Deferred Delivery

By default a report is POSTed inline, so the request or job waits for it. Turn that off to hold reports in memory and deliver them at the end of the current unit of work instead:

```php
'async' => env('HEYBUG_ASYNC', false),
```

Each context ends differently, so there is no single flush point. HTTP requests flush at `terminate()`, after the response has been sent. Queue workers flush on `JobAttempted`, which is dispatched after *every* attempt, including one that threw and will retry, plus `Looping` between jobs and `WorkerStopping` on graceful shutdown. Console commands flush at `CommandFinished`. A shutdown-function backstop catches fatals, though nothing can catch an OOM kill or a `SIGKILL`.

This matters most for queue workers: they never call `terminate()` at all, and a worker is a single long-lived console command, so a report held for `CommandFinished` alone would sit in memory until the process exits and be lost when the worker is restarted on deploy.

**This changes what `handle()` returns.** Deferred, it reports whether the exception was accepted for delivery, since no response exists yet. Inline, it still reports whether the server accepted it. `getLastExceptionId()` is likewise only populated once the flush has happened.

Two ceilings keep a buffer from growing or blocking without bound:

```php
'buffer_limit' => 100,   // most reports held between flushes
'flush_timeout' => 15,   // most seconds one flush may spend delivering
```

Reports beyond `buffer_limit`, and any a flush runs out of time to send, are dropped and the count is logged. Deferring moves delivery cost after the response, but it does not take it off the worker, which is what `flush_timeout` bounds. The first report in a batch is always attempted, so a short budget degrades to one report per flush rather than none. Set either to `0` for no ceiling.

Diagnostics like those drop counts are written to a normal log channel, which must not be the `heybug` channel:

```php
'log_channel' => env('HEYBUG_LOG_CHANNEL', 'single'),
```

`heybug:test` always sends inline, whatever this is set to, since a diagnostic that reported "buffered" would tell you nothing about whether your credentials work.

## Adding Context

You can add custom context data to your error reports:

```php
use HeyBug\Facades\HeyBug;

HeyBug::context([
    'order_id' => $order->id,
    'user_plan' => 'premium',
]);
```

Context is scoped to the current request or job. It is discarded once an exception is handled, and again at the start of every queued job and every Octane request, so it can never attach itself to an unrelated report. Call `HeyBug::clearContext()` to drop it early.

## Reporting the Authenticated User

By default reports include the authenticated user's `id`, `name`, and `email`. Attributes listed in your user model's `$hidden` are never sent. To send less — or nothing at all — adjust `config/heybug.php`:

```php
'send_user' => env('HEYBUG_SEND_USER', true),

'user_attributes' => ['id'],
```

## Filtering Sensitive Data

The package always scrubs a baseline set of keys — passwords, tokens, secrets, API keys, card numbers — from cookies, session, headers, and request parameters. That baseline lives in code (`DataFilter::defaults()`), not in the published config, so patterns added in future releases apply without you republishing anything.

`heybug.blacklist` adds to the baseline:

```php
'blacklist' => [
    '*ssn*',
    '*passport*',
],
```

Leave `blacklist` empty unless you have patterns of your own. That is the recommended steady state, not just a migration step — a hand-copied baseline sitting in a published config is exactly the drift this design exists to prevent.

> **An empty `blacklist` requires 1.2.1 or later.** Before 1.2.1 there is no baseline: `DataFilter` is built straight from `config('heybug.blacklist')`, so an empty list scrubs *nothing* and credentials are sent in plaintext. Downgrading below 1.2.1 with an emptied config — a stale `composer.lock`, a branch pinned at `^1.1`, a deploy that skipped `composer install` — fails silently, with no error and no visible symptom. Pin `^1.2.1` or higher before emptying the key. If you cannot guarantee that floor across every environment, keep an explicit list instead.

To scrub only your own patterns, set `'blacklist_defaults' => false`. This is all-or-nothing: there is no way to remove a single baseline pattern while keeping the rest.

## Self-Hosted and Proxied Endpoints

If you report to an endpoint whose certificate your PHP installation does not trust, turn off TLS verification:

```php
'verify_ssl' => env('HEYBUG_VERIFY_SSL', true),
```

Leave this on when reporting to `api.heybug.io`.

## Code Context

`lines_count` is the number of lines included on *each side* of the failing line, so the default of `12` sends 25 lines: 12 before, the failing line, and 12 after. The payload is capped at 50 lines however the option is set, so values above `24` have no further effect.

## Upgrading from 1.1.x

**If you published `config/heybug.php`, edit your `blacklist` by hand.**

`mergeConfigFrom` is a shallow merge, so a `blacklist` key in your published file replaces the package's list outright rather than merging with it. Upgrading alone therefore does not narrow it. The 1.1.x defaults were:

```php
'*password*', '*token*', '*secret*', '*key*', '*auth*', '*credit*', '*card*',
```

`*key*` also redacts `monkey`, `keyword`, and `sort_key`; `*auth*` also redacts `author` and `authored_at`; `*card*` also redacts `discard` and `wildcard`. Those are over-redactions, not leaks, so this is not urgent — but until you act, those fields keep arriving as `[FILTERED]`.

Either delete the `blacklist` key from your published config (recommended — you then inherit the baseline and every future addition to it), or replace its contents with only the patterns you want to add on top of the baseline.

**Pin `^1.2.1` before you delete it.** The baseline that makes an empty `blacklist` safe does not exist in 1.2.0 or earlier — there, an empty list means nothing is scrubbed at all, silently. If any environment could resolve an older release, keep an explicit list rather than deleting the key.

Keys the package added in this release — `send_user`, `user_attributes`, `blacklist_defaults` — back-fill automatically, since a published file predating them has nothing to override.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
