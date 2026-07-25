<?php

namespace HeyBug\Http;

use Illuminate\Support\Facades\Http;
use Throwable;

class Client
{
    /**
     * The SDK version reported in the User-Agent header.
     *
     * Keep this in step with the released tag. It is server-side telemetry,
     * so bumping it ahead of a release misattributes every install until
     * that release actually ships.
     */
    public const SDK_VERSION = '1.2';

    protected string $apiKey;
    protected string $projectId;
    protected string $server;
    protected bool $verifySsl;
    protected ?string $lastError = null;

    public function __construct(string $apiKey, string $projectId, string $server, bool $verifySsl = true)
    {
        $this->apiKey = $apiKey;
        $this->projectId = $projectId;
        $this->server = $server;
        $this->verifySsl = $verifySsl;
    }

    public function report(array $data, string $type = 'default'): ?array
    {
        $this->lastError = null;

        if (empty($this->apiKey) || empty($this->projectId)) {
            $this->lastError = 'Missing API key or project ID.';

            return null;
        }

        try {
            $request = Http::timeout(5)
                ->withHeaders([
                    'X-HeyBug-DSN' => $this->buildDsn(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'HeyBug-Laravel-SDK/'.self::SDK_VERSION,
                ]);

            if (! $this->verifySsl) {
                $request = $request->withoutVerifying();
            }

            $response = $request
                ->post($this->server, array_merge([
                    'project' => $this->projectId,
                    'type' => $type,
                ], $data));

            if ($response->successful()) {
                return $response->json();
            }

            $this->lastError = "HTTP {$response->status()} from {$this->server}";

            if ($allow = $response->header('Allow')) {
                $this->lastError .= " (Allow: {$allow})";
            }
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
        }

        return null;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function reportJob(array $jobData): ?array
    {
        return $this->report(['job' => $jobData], 'queue_job');
    }

    public function reportJobsBatch(array $jobs): ?array
    {
        return $this->report(['jobs' => $jobs, 'count' => count($jobs)], 'queue_jobs_batch');
    }

    protected function buildDsn(): string
    {
        $host = parse_url($this->server, PHP_URL_HOST);

        return "https://{$this->apiKey}:{$this->projectId}@{$host}";
    }
}
