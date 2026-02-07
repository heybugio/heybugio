<?php

namespace HeyBug\Http;

use Illuminate\Support\Facades\Http;
use Throwable;

class Client
{
    protected string $apiKey;
    protected string $projectId;
    protected string $server;

    public function __construct(string $apiKey, string $projectId, string $server)
    {
        $this->apiKey = $apiKey;
        $this->projectId = $projectId;
        $this->server = $server;
    }

    public function report(array $data, string $type = 'default'): ?array
    {
        if (empty($this->apiKey) || empty($this->projectId)) {
            return null;
        }

        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'X-HeyBug-DSN' => $this->buildDsn(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'HeyBug-Laravel-SDK/1.0',
                ])
                ->post($this->server, array_merge([
                    'project' => $this->projectId,
                    'type' => $type,
                ], $data));

            if ($response->successful()) {
                return $response->json();
            }
        } catch (Throwable) {
            // Fail silently - never break user's application
        }

        return null;
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
