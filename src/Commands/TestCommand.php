<?php

namespace HeyBug\Commands;

use Exception;
use HeyBug\HeyBug;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;

class TestCommand extends Command
{
    protected $signature = 'heybug:test {exception? : Custom exception message}';

    protected $description = 'Test HeyBug integration by sending a test exception';

    public function handle(HeyBug $heybug): int
    {
        $this->info('HeyBug Configuration Test');
        $this->newLine();

        // Check DSN configuration
        if (! $this->checkConfiguration()) {
            return self::FAILURE;
        }

        // Check environment
        if (! $this->checkEnvironment()) {
            return self::FAILURE;
        }

        $this->info('Sending test exception...');

        try {
            $message = $this->argument('exception')
                ?? 'HeyBug test exception from console - ' . now()->toDateTimeString();

            $result = $heybug->handle(new Exception($message));

            if ($result) {
                $this->newLine();
                $this->info('✓ Test exception sent successfully!');

                if ($id = $heybug->getLastExceptionId()) {
                    $this->line("  Exception ID: {$id}");
                }

                $this->line('  Check your dashboard at https://heybug.io');

                return self::SUCCESS;
            }

            $this->newLine();
            $this->error('✗ Failed to send test exception.');
            $this->line('  The server did not accept the request.');

            return self::FAILURE;
        } catch (Exception $e) {
            $this->error('✗ Error: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    protected function checkConfiguration(): bool
    {
        $dsn = config('heybug.dsn');
        $apiKey = config('heybug.api_key');
        $projectId = config('heybug.project_id');

        if ($dsn) {
            $this->line('✓ DSN configured');

            return true;
        }

        if ($apiKey && $projectId) {
            $this->line('✓ API credentials configured');

            return true;
        }

        $this->error('✗ No API credentials configured.');
        $this->line('  Set HEYBUG_API_KEY and HEYBUG_PROJECT_ID in your .env file.');

        return false;
    }

    protected function checkEnvironment(): bool
    {
        $current = App::environment();
        $allowed = config('heybug.environments', []);

        if (in_array($current, $allowed)) {
            $this->line("✓ Environment '{$current}' is enabled");

            return true;
        }

        $this->warn("⚠ Environment '{$current}' is not in allowed list.");
        $this->line('  Allowed: ' . implode(', ', $allowed));
        $this->newLine();

        if (! $this->confirm('Send test exception anyway?', true)) {
            return false;
        }

        // Temporarily allow current environment
        config(['heybug.environments' => array_merge($allowed, [$current])]);

        return true;
    }
}
