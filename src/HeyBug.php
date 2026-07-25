<?php

namespace HeyBug;

use HeyBug\Http\Client;
use HeyBug\Reporting\Buffer;
use HeyBug\Reporting\Envelope;
use HeyBug\Support\DataFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Throwable;

class HeyBug
{
    /**
     * The most source lines a single report may carry.
     */
    protected const MAX_EXECUTOR_LINES = 50;

    protected Client $client;
    protected Buffer $buffer;
    protected DataFilter $dataFilter;
    protected ?string $lastExceptionId = null;
    protected static array $customContext = [];

    public function __construct(Client $client, ?Buffer $buffer = null)
    {
        $this->client = $client;
        $this->buffer = $buffer ?? new Buffer((int) config('heybug.buffer_limit', 100));
        $this->dataFilter = new DataFilter($this->blacklist());
    }

    /**
     * Whether reports are held for the next boundary rather than sent inline.
     */
    public function isDeferred(): bool
    {
        return (bool) config('heybug.async', false);
    }

    /**
     * Deliver everything buffered since the last boundary.
     *
     * Never throws and never returns a value. It runs from event listeners
     * and shutdown functions, including Looping, which the queue worker
     * dispatches with `until()` — a non-null return there would halt the
     * worker's own check and stop it picking up jobs.
     */
    public function flush(): void
    {
        try {
            if ($this->buffer->isEmpty() && $this->buffer->dropped() === 0) {
                return;
            }

            $batch = $this->buffer->take();

            foreach ($batch->envelopes as $envelope) {
                $response = $this->client->report($envelope->toArray(), $envelope->type);

                if ($response && $envelope->type === 'default') {
                    $this->lastExceptionId = $response['id'] ?? null;
                }
            }

            $this->reportDroppedReports($batch->dropped);
        } catch (Throwable) {
            // Flushing must never escalate into the context that triggered it.
        }
    }

    /**
     * Make buffer overflow visible.
     *
     * A drop count nothing reads is the same as no drop count. This is
     * logged rather than reported to HeyBug so that a full buffer cannot
     * generate more traffic through the buffer that is already full. The
     * log channel is safe from recursion because HeyBugHandler only reports
     * records carrying a Throwable, and this one does not.
     */
    protected function reportDroppedReports(int $dropped): void
    {
        if ($dropped === 0) {
            return;
        }

        try {
            Log::channel(config('heybug.log_channel', 'single'))->warning(
                "HeyBug dropped {$dropped} report(s): the buffer limit of "
                .$this->buffer->limit().' was reached before the next flush. '
                .'Raise heybug.buffer_limit if this recurs.'
            );
        } catch (Throwable) {
            // A missing or misconfigured channel must not break the flush.
        }
    }

    /**
     * The package baseline plus any patterns the application adds.
     *
     * @return list<string>
     */
    protected function blacklist(): array
    {
        $patterns = config('heybug.blacklist', []);

        if (config('heybug.blacklist_defaults', true)) {
            $patterns = array_merge(DataFilter::defaults(), $patterns);
        }

        return array_values(array_unique($patterns));
    }

    public static function context(array $context): void
    {
        self::$customContext = array_merge(self::$customContext, $context);
    }

    public static function clearContext(): void
    {
        self::$customContext = [];
    }

    /**
     * Report an exception.
     *
     * Custom context is always discarded once this method returns, on every
     * path, so it can never carry over into an unrelated report.
     */
    public function handle(Throwable $exception): bool
    {
        try {
            return $this->report($exception);
        } catch (Throwable) {
            // Reporting must never escalate the exception it is reporting.
            return false;
        } finally {
            self::$customContext = [];
        }
    }

    protected function report(Throwable $exception): bool
    {
        if ($this->shouldSkip($exception)) {
            return false;
        }

        $data = $this->buildExceptionData($exception);

        if ($this->isSleeping($data)) {
            return false;
        }

        $envelope = new Envelope('default', [
            'exception' => $data,
            'user' => $this->getUser(),
        ]);

        return $this->isDeferred()
            ? $this->bufferReport($envelope, $data)
            : $this->sendReport($envelope, $data);
    }

    /**
     * Hold a report for the next flush boundary.
     *
     * The dedup marker is written here, when the report is buffered, rather
     * than at flush time. Dedup is the behaviour people configure, and a
     * marker that waited for delivery would let every duplicate through
     * until the boundary came around. The cost is that a flush which fails
     * still suppresses the next identical exception for the sleep window;
     * that becomes fixable once envelopes carry a client-minted ID and
     * failed batches can be retried instead of dropped.
     */
    protected function bufferReport(Envelope $envelope, array $data): bool
    {
        if (! $this->buffer->add($envelope)) {
            return false;
        }

        if (config('heybug.sleep', 60) > 0) {
            $this->sleep($data);
        }

        return true;
    }

    protected function sendReport(Envelope $envelope, array $data): bool
    {
        $response = $this->client->report($envelope->toArray(), $envelope->type);

        if ($response) {
            $this->lastExceptionId = $response['id'] ?? null;

            if (config('heybug.sleep', 60) > 0) {
                $this->sleep($data);
            }
        }

        return $response !== null;
    }

    protected function buildExceptionData(Throwable $exception): array
    {
        $data = [
            'environment' => App::environment(),
            'host' => Request::server('SERVER_NAME') ?? gethostname(),
            'method' => Request::method(),
            'fullUrl' => Request::fullUrl(),
            'exception' => $exception->getMessage() ?: '-',
            'error' => $exception->getTraceAsString(),
            'line' => $exception->getLine(),
            'file' => $exception->getFile(),
            'class' => get_class($exception),
            'file_type' => 'php',
            'storage' => $this->buildStorage(),
            'executor' => $this->buildExecutor($exception),
        ];

        if (! empty(self::$customContext)) {
            $data['custom_data'] = self::$customContext;
        }

        return $data;
    }

    protected function buildStorage(): array
    {
        return array_filter([
            'SERVER' => [
                'USER' => Request::server('USER'),
                'HTTP_USER_AGENT' => Request::server('HTTP_USER_AGENT'),
                'SERVER_PROTOCOL' => Request::server('SERVER_PROTOCOL'),
                'SERVER_SOFTWARE' => Request::server('SERVER_SOFTWARE'),
                'PHP_VERSION' => PHP_VERSION,
            ],
            'COOKIE' => $this->dataFilter->filter(Request::cookie() ?? []),
            'SESSION' => $this->dataFilter->filter($this->getSession()),
            'HEADERS' => $this->dataFilter->filter(Request::header() ?? []),
            'PARAMETERS' => $this->dataFilter->filter(Request::all()),
        ]);
    }

    protected function getSession(): array
    {
        try {
            if (Request::hasSession()) {
                return Request::session()->all();
            }
        } catch (Throwable) {
            // Session not available
        }

        return [];
    }

    /**
     * How many lines of source to include on each side of the failing line.
     *
     * heybug.lines_count is a half-window, so 12 yields 25 lines in total.
     * The cap applies to that total, not to the half-window, so the payload
     * never exceeds MAX_EXECUTOR_LINES however the option is set.
     */
    protected function executorHalfWindow(): int
    {
        $count = max((int) config('heybug.lines_count', 12), 0);

        return min($count, intdiv(self::MAX_EXECUTOR_LINES - 1, 2));
    }

    protected function buildExecutor(Throwable $exception): array
    {
        $lines = @file($exception->getFile());

        if ($lines === false) {
            return [];
        }

        $count = $this->executorHalfWindow();
        $errorLine = $exception->getLine();
        $executor = [];

        for ($i = -$count; $i <= $count; $i++) {
            $lineNum = $errorLine + $i;
            $index = $lineNum - 1;

            if (isset($lines[$index])) {
                $executor[] = [
                    'line_number' => $lineNum,
                    'line' => $lines[$index],
                ];
            }
        }

        return $executor;
    }

    protected function getUser(): ?array
    {
        if (! config('heybug.send_user', true)) {
            return null;
        }

        try {
            if (function_exists('auth') && auth()->check()) {
                $user = auth()->user();

                if ($user instanceof Model) {
                    $attributes = array_diff(
                        config('heybug.user_attributes', ['id', 'name', 'email']),
                        $user->getHidden()
                    );

                    return $this->dataFilter->filter($user->only($attributes));
                }
            }
        } catch (Throwable) {
            // Auth not available
        }

        return null;
    }

    protected function shouldSkip(Throwable $exception): bool
    {
        $envs = config('heybug.environments', []);

        if (empty($envs) || ! in_array(App::environment(), $envs)) {
            return true;
        }

        foreach (config('heybug.except', []) as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        return false;
    }

    protected function isSleeping(array $data): bool
    {
        if (config('heybug.sleep', 60) === 0) {
            return false;
        }

        return Cache::has($this->fingerprint($data));
    }

    protected function sleep(array $data): void
    {
        $key = $this->fingerprint($data);
        Cache::put($key, true, config('heybug.sleep', 60));
    }

    protected function fingerprint(array $data): string
    {
        return 'heybug:'.md5(implode('|', [
            $data['class'] ?? '',
            $data['file'] ?? '',
            $data['line'] ?? '',
            $data['exception'] ?? '',
        ]));
    }

    public function getLastExceptionId(): ?string
    {
        return $this->lastExceptionId;
    }

    public function getLastError(): ?string
    {
        return $this->client->getLastError();
    }
}
