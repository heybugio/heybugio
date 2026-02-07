<?php

namespace HeyBug\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void context(array $context)
 * @method static void clearContext()
 * @method static bool handle(\Throwable $exception)
 * @method static string|null getLastExceptionId()
 *
 * @see \HeyBug\HeyBug
 */
class HeyBug extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'heybug';
    }
}
