<?php

namespace HeyBug\Facades;

use HeyBug\Fakes\HeyBugFake;
use HeyBug\Http\Client;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void context(array $context)
 * @method static void clearContext()
 * @method static bool handle(\Throwable $exception)
 * @method static void flush()
 * @method static bool isDeferred()
 * @method static string|null getLastExceptionId()
 * @method static list<\HeyBug\Reporting\Envelope> reported(string|null $class = null)
 * @method static void assertReported(string $class, callable|null $callback = null)
 * @method static void assertNotReported(string $class, callable|null $callback = null)
 * @method static void assertReportedCount(int $expected)
 * @method static void assertNothingReported()
 *
 * @see \HeyBug\HeyBug
 * @see \HeyBug\Fakes\HeyBugFake
 */
class HeyBug extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'heybug';
    }

    /**
     * Record reports instead of delivering them.
     *
     * swap() rebinds the container as well as the facade, so the log channel
     * driver — which resolves heybug out of the container rather than through
     * this facade — reports to the fake too.
     */
    public static function fake(): HeyBugFake
    {
        $fake = new HeyBugFake(app(Client::class));

        static::swap($fake);

        return $fake;
    }
}
