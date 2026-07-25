<?php

namespace HeyBug\Reporting;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Makes buffer overflow visible.
 *
 * A drop count nothing reads is the same as no drop count. Drops are logged
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

        try {
            Log::channel(config('heybug.log_channel', 'single'))->warning(
                "HeyBug dropped {$dropped} {$what}: the buffer limit of {$limit} "
                .'was reached before the next flush. Raise heybug.buffer_limit if this recurs.'
            );
        } catch (Throwable) {
            // A missing or misconfigured channel must not break the flush.
        }
    }
}
