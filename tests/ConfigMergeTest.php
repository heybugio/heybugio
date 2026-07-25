<?php

namespace HeyBug\Tests;

use HeyBug\HeyBugServiceProvider;
use HeyBug\Support\DataFilter;

class ConfigMergeTest extends TestCase
{
    /**
     * Reproduce a published config file being loaded before the package
     * registers, which is the order a real application uses. Testbench
     * applies defineEnvironment *after* provider registration, so setting
     * config there would overwrite the merge instead of exercising it.
     */
    protected function publishConfig(array $heybug): void
    {
        config(['heybug' => array_merge(config('heybug'), $heybug)]);

        (new HeyBugServiceProvider($this->app))->register();
    }

    /**
     * A queue block published against 1.1, before batch_size existed.
     *
     * @return array<string, mixed>
     */
    protected function legacyQueueBlock(): array
    {
        return [
            'enabled' => true,
            'track_processing' => false,
            'track_completed' => true,
            'track_failed' => true,
            'only_queues' => [],
            'ignore_queues' => [],
            'ignore_jobs' => [],
        ];
    }

    public function test_it_back_fills_nested_keys_a_published_config_predates(): void
    {
        $this->publishConfig(['queue' => $this->legacyQueueBlock()]);

        $this->assertSame(20, config('heybug.queue.batch_size'));
    }

    public function test_it_keeps_what_the_published_config_does_set(): void
    {
        $this->publishConfig(['queue' => $this->legacyQueueBlock()]);

        $this->assertTrue(config('heybug.queue.enabled'));
        $this->assertFalse(config('heybug.queue.track_processing'));
    }

    public function test_it_does_not_merge_lists(): void
    {
        // Lists are replaced outright by design. The scrubbing baseline is
        // in DataFilter::defaults() so that replacing this cannot drop it.
        $this->publishConfig(['blacklist' => ['*legacy*']]);

        $this->assertSame(['*legacy*'], config('heybug.blacklist'));
    }

    public function test_the_scrubbing_baseline_survives_a_replaced_list(): void
    {
        $this->publishConfig(['blacklist' => ['*legacy*']]);

        $filter = new DataFilter(array_merge(DataFilter::defaults(), config('heybug.blacklist')));

        $filtered = $filter->filter(['password' => 'hunter2', 'legacy' => 'x']);

        $this->assertSame('[FILTERED]', $filtered['password']);
        $this->assertSame('[FILTERED]', $filtered['legacy']);
    }

    public function test_every_nested_default_is_reachable(): void
    {
        // Guards the convention structurally: a nested read written without
        // an inline default must still find a value for an app that
        // published its config before that key existed.
        $defaults = require __DIR__.'/../config/heybug.php';

        $published = [];

        foreach ($defaults as $key => $value) {
            if (is_array($value) && ! array_is_list($value)) {
                $published[$key] = ['enabled' => true];
            }
        }

        $this->publishConfig($published);

        foreach ($defaults as $key => $value) {
            if (! is_array($value) || array_is_list($value)) {
                continue;
            }

            foreach (array_keys($value) as $nested) {
                $this->assertNotNull(
                    config("heybug.{$key}.{$nested}"),
                    "heybug.{$key}.{$nested} is null for a config published before it existed."
                );
            }
        }
    }
}
