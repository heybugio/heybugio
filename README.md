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

Keys the package added in this release — `send_user`, `user_attributes`, `blacklist_defaults` — back-fill automatically, since a published file predating them has nothing to override.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
