<?php

namespace HeyBug\Tests\Reporting;

use HeyBug\Reporting\PayloadLimit;
use HeyBug\Tests\TestCase;

class PayloadLimitTest extends TestCase
{
    protected function report(array $overrides = []): array
    {
        return array_merge([
            'exception' => 'Something failed',
            'error' => '#0 /app/foo.php(1)',
            'line' => 12,
            'file' => '/app/foo.php',
            'class' => 'RuntimeException',
            'storage' => [
                'SERVER' => ['PHP_VERSION' => '8.4.0'],
                'COOKIE' => ['a' => 'b'],
                'SESSION' => ['a' => 'b'],
                'HEADERS' => ['a' => 'b'],
                'PARAMETERS' => ['a' => 'b'],
            ],
            'executor' => [['line_number' => 12, 'line' => 'throw $e;']],
        ], $overrides);
    }

    public function test_it_leaves_a_report_inside_the_ceiling_untouched(): void
    {
        $report = $this->report();

        $this->assertSame($report, PayloadLimit::apply($report, 65536));
    }

    public function test_a_zero_ceiling_means_no_limit(): void
    {
        $report = $this->report(['storage' => ['SESSION' => ['big' => str_repeat('x', 100_000)]]]);

        $this->assertSame($report, PayloadLimit::apply($report, 0));
    }

    public function test_it_sheds_the_oversized_section_and_keeps_the_rest(): void
    {
        $report = $this->report();
        $report['storage']['SESSION'] = ['cart' => str_repeat('x', 5000)];

        $limited = PayloadLimit::apply($report, 2000);

        $this->assertTrue($limited['storage']['SESSION']['_truncated']);
        $this->assertGreaterThan(5000, $limited['storage']['SESSION']['_original_size']);

        // Sheds in order and stops as soon as it fits, so the sections below
        // session in usefulness survive.
        $this->assertSame(['a' => 'b'], $limited['storage']['PARAMETERS']);
        $this->assertSame(['a' => 'b'], $limited['storage']['HEADERS']);
        $this->assertSame(['PHP_VERSION' => '8.4.0'], $limited['storage']['SERVER']);
    }

    public function test_it_never_sheds_the_identifying_fields(): void
    {
        $report = $this->report();
        $report['storage']['SESSION'] = ['a' => str_repeat('x', 50_000)];
        $report['storage']['PARAMETERS'] = ['b' => str_repeat('y', 50_000)];
        $report['custom_data'] = ['c' => str_repeat('z', 50_000)];

        $limited = PayloadLimit::apply($report, 500);

        $this->assertSame('RuntimeException', $limited['class']);
        $this->assertSame('/app/foo.php', $limited['file']);
        $this->assertSame(12, $limited['line']);
        $this->assertSame('Something failed', $limited['exception']);
    }

    public function test_it_sheds_custom_context_before_anything_else(): void
    {
        $report = $this->report();
        $report['custom_data'] = ['blob' => str_repeat('x', 5000)];

        $limited = PayloadLimit::apply($report, 2000);

        $this->assertTrue($limited['custom_data']['_truncated']);
        $this->assertSame(['a' => 'b'], $limited['storage']['SESSION']);
    }

    public function test_it_clips_a_runaway_message_once_everything_else_is_gone(): void
    {
        $report = $this->report([
            'exception' => str_repeat('x', 50_000),
            'error' => str_repeat('y', 50_000),
        ]);

        $limited = PayloadLimit::apply($report, 1000);

        $this->assertStringEndsWith('[truncated]', $limited['error']);
        $this->assertStringEndsWith('[truncated]', $limited['exception']);
        $this->assertLessThanOrEqual(1000, strlen((string) json_encode($limited)));
    }

    public function test_an_already_shed_section_is_not_shed_twice(): void
    {
        $report = $this->report();
        $report['storage']['SESSION'] = ['_truncated' => true, '_original_size' => 9000];
        $report['storage']['PARAMETERS'] = ['big' => str_repeat('x', 5000)];

        $limited = PayloadLimit::apply($report, 2000);

        $this->assertSame(9000, $limited['storage']['SESSION']['_original_size']);
        $this->assertTrue($limited['storage']['PARAMETERS']['_truncated']);
    }
}
