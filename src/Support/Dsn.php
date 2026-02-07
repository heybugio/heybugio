<?php

namespace HeyBug\Support;

use InvalidArgumentException;

class Dsn
{
    protected string $apiKey;
    protected string $projectId;
    protected string $server;

    public function __construct(string $dsn)
    {
        $this->parse($dsn);
    }

    protected function parse(string $dsn): void
    {
        $parsed = parse_url($dsn);

        if (! $parsed || ! isset($parsed['user'], $parsed['pass'], $parsed['host'])) {
            throw new InvalidArgumentException(
                'Invalid DSN. Format: https://{api_key}:{project_id}@api.heybug.io'
            );
        }

        $this->apiKey = $parsed['user'];
        $this->projectId = $parsed['pass'];

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'];
        $port = isset($parsed['port']) ? ":{$parsed['port']}" : '';

        $this->server = "{$scheme}://{$host}{$port}";
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getProjectId(): string
    {
        return $this->projectId;
    }

    public function getServer(): string
    {
        return $this->server;
    }

    public static function make(string $dsn): self
    {
        return new static($dsn);
    }

    public static function isValid(string $dsn): bool
    {
        try {
            new static($dsn);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
