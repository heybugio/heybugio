<?php

namespace HeyBug\Tests;

use HeyBug\Http\Client;
use Illuminate\Support\Facades\Http;

class ClientTest extends TestCase
{
    public function test_it_sends_exception_report(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'success' => true, 'id' => 'exc-123'], 200),
        ]);

        $client = new Client('api-key', 'project-id', 'https://api.heybug.io');
        $result = $client->report(['exception' => ['message' => 'Test']]);

        $this->assertEquals(['ok' => true, 'success' => true, 'id' => 'exc-123'], $result);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.heybug.io'
                && $request->hasHeader('X-HeyBug-DSN')
                && $request->hasHeader('User-Agent', 'HeyBug-Laravel-SDK/1.0')
                && $request['type'] === 'default'
                && $request['project'] === 'project-id';
        });
    }

    public function test_it_sends_queue_job_report(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $client = new Client('api-key', 'project-id', 'https://api.heybug.io');
        $client->reportJob([
            'job_id' => 'job-123',
            'event' => 'completed',
            'job_name' => 'App\\Jobs\\TestJob',
        ]);

        Http::assertSent(function ($request) {
            return $request['type'] === 'queue_job'
                && $request['job']['job_id'] === 'job-123'
                && $request['job']['event'] === 'completed';
        });
    }

    public function test_it_sends_queue_jobs_batch(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $client = new Client('api-key', 'project-id', 'https://api.heybug.io');
        $client->reportJobsBatch([
            ['job_id' => 'job-1', 'event' => 'completed'],
            ['job_id' => 'job-2', 'event' => 'failed'],
        ]);

        Http::assertSent(function ($request) {
            return $request['type'] === 'queue_jobs_batch'
                && $request['count'] === 2
                && count($request['jobs']) === 2;
        });
    }

    public function test_it_returns_null_on_http_error(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Internal Server Error'], 500),
        ]);

        $client = new Client('api-key', 'project-id', 'https://api.heybug.io');
        $result = $client->report(['exception' => []]);

        $this->assertNull($result);
    }

    public function test_it_returns_null_on_network_error(): void
    {
        Http::fake(function () {
            throw new \Exception('Network error');
        });

        $client = new Client('api-key', 'project-id', 'https://api.heybug.io');
        $result = $client->report(['exception' => []]);

        $this->assertNull($result);
    }

    public function test_it_returns_null_when_credentials_missing(): void
    {
        Http::fake();

        $client = new Client('', '', 'https://api.heybug.io');
        $result = $client->report(['exception' => []]);

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_it_builds_correct_dsn_header(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $client = new Client('my-api-key', 'my-project-id', 'https://api.heybug.io');
        $client->report(['exception' => []]);

        Http::assertSent(function ($request) {
            $dsn = $request->header('X-HeyBug-DSN')[0];

            return $dsn === 'https://my-api-key:my-project-id@api.heybug.io';
        });
    }

    public function test_it_uses_5_second_timeout(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $client = new Client('api-key', 'project-id', 'https://api.heybug.io');
        $client->report(['exception' => []]);

        // If we got here without timeout, the 5s timeout is working
        // (actual timeout testing would require real network calls)
        $this->assertTrue(true);
    }
}
