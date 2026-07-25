<?php

namespace HeyBug\Tests;

use Exception;
use HeyBug\Facades\HeyBug;
use HeyBug\HeyBug as HeyBugManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FakeTest extends TestCase
{
    public function test_it_records_instead_of_delivering(): void
    {
        Http::fake();

        HeyBug::fake();

        HeyBug::handle(new RuntimeException('Payment failed'));

        HeyBug::assertReported(RuntimeException::class);
        Http::assertNothingSent();
    }

    public function test_the_log_channel_reports_to_the_fake(): void
    {
        Http::fake();

        config(['logging.channels.heybug' => ['driver' => 'heybug']]);

        HeyBug::fake();

        Log::channel('heybug')->error('Gateway down', ['exception' => new RuntimeException('Gateway down')]);

        HeyBug::assertReported(RuntimeException::class);
        Http::assertNothingSent();
    }

    public function test_it_asserts_on_the_real_payload(): void
    {
        HeyBug::fake();

        HeyBug::context(['order_id' => 42]);

        HeyBug::handle(new RuntimeException('Charge declined'));

        HeyBug::assertReported(
            RuntimeException::class,
            fn ($envelope) => $envelope->payload['exception']['exception'] === 'Charge declined'
                && $envelope->payload['exception']['custom_data']['order_id'] === 42
        );
    }

    public function test_a_failing_callback_means_not_reported(): void
    {
        HeyBug::fake();

        HeyBug::handle(new RuntimeException('Charge declined'));

        HeyBug::assertNotReported(
            RuntimeException::class,
            fn ($envelope) => $envelope->payload['exception']['exception'] === 'Something else'
        );
    }

    public function test_it_counts_reports_not_distinct_classes(): void
    {
        HeyBug::fake();

        HeyBug::handle(new RuntimeException('One'));
        HeyBug::handle(new RuntimeException('Two'));
        HeyBug::handle(new RuntimeException('Three'));

        HeyBug::assertReportedCount(3);
    }

    public function test_it_still_honours_the_except_list(): void
    {
        config(['heybug.except' => [RuntimeException::class]]);

        HeyBug::fake();

        $this->assertFalse(HeyBug::handle(new RuntimeException('Ignored')));

        HeyBug::assertNotReported(RuntimeException::class);
        HeyBug::assertNothingReported();
    }

    public function test_it_records_regardless_of_environment(): void
    {
        config(['heybug.environments' => ['production']]);

        HeyBug::fake();

        HeyBug::handle(new Exception('Reported in the test environment'));

        HeyBug::assertReported(Exception::class);
    }

    public function test_it_records_duplicates_rather_than_deduping_them(): void
    {
        config(['heybug.sleep' => 60]);

        HeyBug::fake();

        HeyBug::handle(new RuntimeException('Same'));
        HeyBug::handle(new RuntimeException('Same'));

        HeyBug::assertReportedCount(2);
    }

    public function test_it_scrubs_context_the_way_a_real_report_would(): void
    {
        HeyBug::fake();

        HeyBug::context(['stripe_token' => 'tok_live_1', 'order_id' => 42]);

        HeyBug::handle(new RuntimeException('Charge declined'));

        HeyBug::assertReported(
            RuntimeException::class,
            fn ($envelope) => $envelope->payload['exception']['custom_data']['stripe_token'] === '[FILTERED]'
                && $envelope->payload['exception']['custom_data']['order_id'] === 42
        );
    }

    public function test_it_replaces_the_container_binding_too(): void
    {
        $fake = HeyBug::fake();

        $this->assertSame($fake, app('heybug'));
        $this->assertSame($fake, app(HeyBugManager::class));
    }
}
