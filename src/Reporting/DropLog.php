<?php

namespace HeyBug\Reporting;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Makes undelivered reports visible.
 *
 * A drop count nothing reads is the same as no drop count. Losses are logged
 * rather than reported to HeyBug so that a full buffer cannot generate more
 * traffic through the buffer that is already full.
 *
 * The log channel is safe from recursion because HeyBugHandler only reports
 * records carrying a Throwable, and these do not.
 */
class DropLog
{
    public static function record(int $dropped, int $limit, string $what): void
    {
        if ($dropped === 0) {
            return;
        }

        static::warn(
            "HeyBug dropped {$dropped} {$what}: the buffer limit of {$limit} "
            .'was reached before the next flush. Raise heybug.buffer_limit if this recurs.'
        );
    }

    /**
     * Report envelopes a flush ran out of time to deliver.
     *
     * Kept separate from an overflow drop because the cause and the remedy
     * differ: nothing exceeded capacity here, the endpoint was too slow to
     * clear the batch within the budget.
     */
    public static function abandoned(int $abandoned, float $seconds, string $what): void
    {
        if ($abandoned <= 0) {
            return;
        }

        static::warn(
            "HeyBug abandoned {$abandoned} {$what}: the flush budget of {$seconds}s "
            .'was exhausted before the batch was delivered. Raise heybug.flush_timeout if this recurs.'
        );
    }

    protected static function warn(string $message): void
    {
        try {
            Log::channel(config('heybug.log_channel', 'single'))->warning($message);
        } catch (Throwable) {
            // A missing or misconfigured channel must not break the flush.
        }
    }
}
