<?php

namespace HeyBug\Queue;

use HeyBug\Http\Client;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Throwable;

class JobEventSubscriber
{
    protected Client $client;
    protected JobDataCollector $collector;

    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->collector = new JobDataCollector;
    }

    public function subscribe($events): array
    {
        return [
            JobProcessing::class => 'handleJobProcessing',
            JobProcessed::class => 'handleJobProcessed',
            JobFailed::class => 'handleJobFailed',
        ];
    }

    public function handleJobProcessing(JobProcessing $event): void
    {
        if (! $this->shouldTrack($event->job, 'track_processing')) {
            return;
        }

        $this->send($this->collector->collectFromProcessing($event));
    }

    public function handleJobProcessed(JobProcessed $event): void
    {
        if (! $this->shouldTrack($event->job, 'track_completed')) {
            return;
        }

        $this->send($this->collector->collectFromProcessed($event));
    }

    public function handleJobFailed(JobFailed $event): void
    {
        if (! $this->shouldTrack($event->job, 'track_failed')) {
            return;
        }

        $this->send($this->collector->collectFromFailed($event));
    }

    protected function shouldTrack(Job $job, string $configKey): bool
    {
        if (! config("heybug.queue.{$configKey}", true)) {
            return false;
        }

        $queue = $job->getQueue();

        $onlyQueues = config('heybug.queue.only_queues', []);
        if (! empty($onlyQueues) && ! in_array($queue, $onlyQueues)) {
            return false;
        }

        $ignoreQueues = config('heybug.queue.ignore_queues', []);
        if (in_array($queue, $ignoreQueues)) {
            return false;
        }

        $jobClass = $job->resolveName();
        $ignoreJobs = config('heybug.queue.ignore_jobs', []);

        foreach ($ignoreJobs as $ignoredClass) {
            if ($jobClass === $ignoredClass || is_subclass_of($jobClass, $ignoredClass)) {
                return false;
            }
        }

        return true;
    }

    protected function send(array $jobData): void
    {
        try {
            $this->client->reportJob($jobData);
        } catch (Throwable) {
            // Fail silently
        }
    }
}
