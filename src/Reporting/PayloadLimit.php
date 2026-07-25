<?php

namespace HeyBug\Reporting;

/**
 * Keeps a single report within a byte ceiling.
 *
 * Nothing else bounds a report's size. A fat session bag, a large form post
 * or a minified file caught by the source window can each produce a payload
 * of any size, and deferred delivery holds buffer_limit of them in memory
 * before any of it is sent.
 *
 * Parts are shed in a fixed order rather than by whichever is largest, so
 * the outcome is predictable: the identifying fields — class, file, line,
 * message — are what a report is for, cost almost nothing, and are never
 * shed. Everything above them in usefulness goes first.
 */
class PayloadLimit
{
    /**
     * Sheddable parts, least useful first.
     *
     * @var list<list<string>>
     */
    protected const SHEDDABLE = [
        ['custom_data'],
        ['storage', 'SESSION'],
        ['storage', 'PARAMETERS'],
        ['storage', 'COOKIE'],
        ['storage', 'HEADERS'],
        ['executor'],
    ];

    /**
     * Strings truncated only once every sheddable part is already gone.
     *
     * @var list<string>
     */
    protected const TRUNCATABLE = ['error', 'exception'];

    public static function apply(array $data, int $limit): array
    {
        if ($limit <= 0 || static::size($data) <= $limit) {
            return $data;
        }

        foreach (static::SHEDDABLE as $path) {
            if (! static::shed($data, $path)) {
                continue;
            }

            if (static::size($data) <= $limit) {
                return $data;
            }
        }

        foreach (static::TRUNCATABLE as $key) {
            if (! is_string($data[$key] ?? null)) {
                continue;
            }

            $data[$key] = static::clip($data[$key], intdiv($limit, 4));

            if (static::size($data) <= $limit) {
                return $data;
            }
        }

        return $data;
    }

    /**
     * Replace one part with a marker, reporting whether there was one to replace.
     */
    protected static function shed(array &$data, array $path): bool
    {
        $key = array_shift($path);

        if (! isset($data[$key]) || ! is_array($data[$key])) {
            return false;
        }

        if ($path !== []) {
            return static::shed($data[$key], $path);
        }

        if ($data[$key] === [] || isset($data[$key]['_truncated'])) {
            return false;
        }

        $data[$key] = [
            '_truncated' => true,
            '_original_size' => static::size($data[$key]),
        ];

        return true;
    }

    protected static function clip(string $value, int $keep): string
    {
        if (strlen($value) <= $keep) {
            return $value;
        }

        return substr($value, 0, max($keep, 0)).' ... [truncated]';
    }

    protected static function size(array $data): int
    {
        return strlen((string) json_encode($data));
    }
}
