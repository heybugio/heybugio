<p align="center">
    <a href="https://heybug.io" target="_blank"><img width="130" src="https://heybug.io/logo.png"></a>
</p>

# HeyBug

Laravel 12.x package for logging errors to [heybug.io](https://heybug.io)

[![Software License](https://poser.pugx.org/heybugio/heybugio/license.svg)](LICENSE.md)
[![Latest Version on Packagist](https://poser.pugx.org/heybugio/heybugio/v/stable.svg)](https://packagist.org/packages/heybugio/heybugio)
[![Total Downloads](https://poser.pugx.org/heybugio/heybugio/d/total.svg)](https://packagist.org/packages/heybugio/heybugio)

## Version Support

| PHP   | Laravel |
|-------|---------|
| 8.2+  | 12.x    |

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

## Adding Context

You can add custom context data to your error reports:

```php
use HeyBug\Facades\HeyBug;

HeyBug::context([
    'order_id' => $order->id,
    'user_plan' => 'premium',
]);
```

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
