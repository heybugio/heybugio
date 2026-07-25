<?php

namespace HeyBug\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class DataFilter
{
    protected array $blacklist;

    public function __construct(array $blacklist = [])
    {
        $this->blacklist = array_map('strtolower', $blacklist);
    }

    /**
     * The patterns the package always scrubs.
     *
     * These ship in code rather than in the published config so that a
     * config file published against an older release still picks up
     * patterns added in later ones. Users add to this list via
     * heybug.blacklist; they cannot remove from it except by turning
     * heybug.blacklist_defaults off wholesale.
     *
     * Patterns are matched against the lowercased key, so they must be
     * narrow enough to avoid eating ordinary fields: "*key*" would also
     * redact "monkey" and "keyword", "*auth*" would redact "author".
     *
     * @return list<string>
     */
    public static function defaults(): array
    {
        return [
            '*password*',
            '*token*',
            '*secret*',
            '*_key*',
            '*-key*',
            '*apikey*',
            'auth',
            'authorization',
            '*credit*',
            '*card_number*',
            '*cardnumber*',
            '*cvv*',
            '*cvc*',
        ];
    }

    public function filter(array $data): array
    {
        if (empty($data)) {
            return [];
        }

        $filtered = [];

        foreach ($data as $key => $value) {
            if ($value instanceof UploadedFile) {
                $filtered[$key] = '[FILE]';

                continue;
            }

            if (is_string($key) && $this->shouldFilter($key)) {
                $filtered[$key] = '[FILTERED]';

                continue;
            }

            $filtered[$key] = is_array($value)
                ? $this->filter($value)
                : $value;
        }

        return $filtered;
    }

    protected function shouldFilter(string $key): bool
    {
        $lowerKey = strtolower($key);

        foreach ($this->blacklist as $pattern) {
            if (Str::is($pattern, $lowerKey)) {
                return true;
            }
        }

        return false;
    }
}
