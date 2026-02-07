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
