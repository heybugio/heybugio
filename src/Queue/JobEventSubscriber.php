<?php

namespace HeyBug\Queue;

use HeyBug\Http\Client;
use HeyBug\Reporting\Buffer;
use HeyBug\Reporting\DropLog;
use HeyBug\Reporting\Envelope;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Throwable;

class JobEventSubscriber
{
    protected Client $client;
    protected JobDataCollector $collector;
    protected Buffer $buffer;

    public function __construct(Client $client, ?Buffer $buffer = null)
    {
        $this->client = $client;
        $this->collector = new JobDataCollector;
        $this->buffer = $buffer ?? new Buffer((int) config('heybug.buffer_limit', 100));
    }

    /**
     * Bind the handlers to *this* instance.
     *
     * Returning a map of method-name strings would have the dispatcher
     * register [static::class, 'method'] and resolve a new subscriber out
     * of the container for every event. That was invisible while each event
     * was POSTed on its own, but it gives every record its own empty buffer,
     * so nothing ever accumulates and nothing is ever batched.
     */
    public function subscribe($events): void
    {
        $events->listen(JobProcessing::class, [$this, 'handleJobProcessing']);
        $events->listen(JobProcessed::class, [$this, 'handleJobProcessed']);
        $events->listen(JobFailed::class, [$this, 'handleJobFailed']);
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

    /**
     * Hold a job record, delivering once enough have accumulated.
     *
     * Job telemetry is high volume and low value per record, so it batches
     * on a size threshold rather than at a boundary. The per-job boundaries
     * are no use here: JobProcessed and JobAttempted are both dispatched
     * inside a single Worker::process() call and Looping fires between
     * every job, so flushing at any of them would send batches of one and
     * batch nothing at all.
     */
    protected function send(array $jobData): void
    {
        $this->buffer->add(new Envelope('queue_job', $jobData));

        if ($this->buffer->count() >= $this->batchSize()) {
            $this->flush();
        }
    }

    /**
     * Deliver whatever job records have accumulated.
     *
     * Called on the threshold above, and from the coarse boundaries — a
     * worker stopping, a console command finishing, process shutdown — so
     * that a partial batch is not left behind. A worker killed outright
     * loses its partial batch; that is job telemetry rather than an error
     * report, and re-delivering it is not worth the duplicate risk.
     */
    public function flush(): void
    {
        try {
            if ($this->buffer->isEmpty() && $this->buffer->dropped() === 0) {
                return;
            }

            $batch = $this->buffer->take();

            if ($batch->envelopes !== []) {
                $this->client->reportJobsBatch(
                    array_map(fn (Envelope $envelope): array => $envelope->payload, $batch->envelopes)
                );
            }

            DropLog::record($batch->dropped, $this->buffer->limit(), 'job record(s)');
        } catch (Throwable) {
            // Job monitoring must never break the queue it is monitoring.
        }
    }

    /**
     * How many records to accumulate before delivering.
     *
     * Capped by the buffer limit, since a threshold above the cap could
     * never be reached and every record past the cap would be dropped.
     */
    protected function batchSize(): int
    {
        return max(1, min(
            (int) config('heybug.queue.batch_size', 20),
            (int) config('heybug.buffer_limit', 100),
        ));
    }
}
