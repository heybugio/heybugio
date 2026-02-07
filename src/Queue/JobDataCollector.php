<?php

namespace HeyBug\Queue;

use HeyBug\Support\DataFilter;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

class JobDataCollector
{
    protected DataFilter $dataFilter;

    protected static array $timingCache = [];

    public function __construct()
    {
        $this->dataFilter = new DataFilter(config('heybug.blacklist', []));
    }

    public function collectFromProcessing(JobProcessing $event): array
    {
        $job = $event->job;
        $jobId = $this->getJobId($job);

        self::$timingCache[$jobId] = [
            'started_at' => microtime(true),
            'memory_start' => memory_get_usage(true),
        ];

        return $this->buildJobData($job, 'processing');
    }

    public function collectFromProcessed(JobProcessed $event): array
    {
        $job = $event->job;
        $data = $this->buildJobData($job, 'completed');
        $data = $this->addTiming($job, $data);

        $this->clearTimingCache($job);

        return $data;
    }

    public function collectFromFailed(JobFailed $event): array
    {
        $job = $event->job;
        $data = $this->buildJobData($job, 'failed');
        $data = $this->addTiming($job, $data);
        $data['error'] = $event->exception->getMessage();
        $data['exception_class'] = get_class($event->exception);

        $this->clearTimingCache($job);

        return $data;
    }

    protected function buildJobData(Job $job, string $event): array
    {
        $payload = $job->payload();

        return [
            'job_id' => $this->getJobId($job),
            'event' => $event,
            'job_name' => $payload['displayName'] ?? $job->resolveName(),
            'connection' => $job->getConnectionName(),
            'queue' => $job->getQueue(),
            'attempt' => $job->attempts(),
            'max_tries' => $payload['maxTries'] ?? null,
        ];
    }

    protected function addTiming(Job $job, array $data): array
    {
        $jobId = $this->getJobId($job);

        if (isset(self::$timingCache[$jobId])) {
            $timing = self::$timingCache[$jobId];
            $data['duration_ms'] = (int) round((microtime(true) - $timing['started_at']) * 1000);
            $data['memory_usage'] = memory_get_usage(true) - $timing['memory_start'];
        }

        return $data;
    }

    protected function getJobId(Job $job): string
    {
        $payload = $job->payload();

        return $payload['uuid'] ?? $job->getJobId() ?? spl_object_hash($job);
    }

    protected function clearTimingCache(Job $job): void
    {
        unset(self::$timingCache[$this->getJobId($job)]);
    }
}
