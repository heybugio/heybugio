<?php

namespace HeyBug\Tests;

use Exception;
use HeyBug\Http\Client;
use HeyBug\Queue\JobEventSubscriber;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class JobBatchingTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('heybug.queue.enabled', true);
        $app['config']->set('heybug.queue.batch_size', 3);
    }

    public function test_it_holds_job_records_until_the_batch_is_full(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $this->processJobs(2);

        Http::assertNothingSent();

        $this->processJobs(1);

        Http::assertSentCount(1);
    }

    public function test_it_sends_the_whole_batch_in_one_request(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $this->processJobs(3);

        Http::assertSent(function ($request) {
            return $request['type'] === 'queue_jobs_batch'
                && $request['count'] === 3
                && count($request['jobs']) === 3;
        });
    }

    public function test_it_starts_a_fresh_batch_after_sending(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $this->processJobs(6);

        Http::assertSentCount(2);
    }

    public function test_a_stopping_worker_delivers_its_partial_batch(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $this->processJobs(2);

        Http::assertNothingSent();

        event(new WorkerStopping(0, new WorkerOptions));

        Http::assertSent(function ($request) {
            return $request['count'] === 2;
        });
    }

    public function test_a_finishing_command_delivers_its_partial_batch(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $this->processJobs(1);

        event(new CommandFinished('queue:work', new ArrayInput([]), new NullOutput, 0));

        Http::assertSentCount(1);
    }

    public function test_flushing_an_empty_batch_sends_nothing(): void
    {
        Http::fake();

        event(new WorkerStopping(0, new WorkerOptions));
        event(new CommandFinished('queue:work', new ArrayInput([]), new NullOutput, 0));

        Http::assertNothingSent();
    }

    public function test_a_failing_batch_does_not_break_the_queue(): void
    {
        Http::fake(function () {
            throw new Exception('Connection refused');
        });

        $this->processJobs(3);

        // Reaching here at all is the assertion: the throw was contained.
        $this->assertTrue(true);
    }

    public function test_the_batch_size_cannot_exceed_the_buffer_limit(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        // A threshold above the cap could never be reached, so every record
        // past the cap would be dropped and nothing would ever be sent.
        config(['heybug.buffer_limit' => 2, 'heybug.queue.batch_size' => 50]);

        $subscriber = new JobEventSubscriber(app(Client::class));

        foreach (range(1, 2) as $i) {
            $subscriber->handleJobProcessed(new JobProcessed('sync', $this->createStub(Job::class)));
        }

        Http::assertSent(function ($request) {
            return $request['count'] === 2;
        });
    }

    public function test_each_event_shares_one_subscriber(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        // Registering handlers by method name would have the dispatcher build
        // a new subscriber per event, giving each record its own empty buffer.
        $listeners = $this->app['events']->getListeners(JobProcessed::class);

        $this->assertCount(1, $listeners);

        $this->processJobs(2);
        $this->processJobs(1);

        Http::assertSentCount(1);
    }

    protected function processJobs(int $count): void
    {
        foreach (range(1, $count) as $i) {
            event(new JobProcessed('sync', $this->createStub(Job::class)));
        }
    }
}
