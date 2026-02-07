<?php

namespace HeyBug;

use HeyBug\Http\Client;
use HeyBug\Support\DataFilter;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;
use Throwable;

class HeyBug
{
    protected Client $client;
    protected DataFilter $dataFilter;
    protected ?string $lastExceptionId = null;
    protected static array $customContext = [];

    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->dataFilter = new DataFilter(config('heybug.blacklist', []));
    }

    public static function context(array $context): void
    {
        self::$customContext = array_merge(self::$customContext, $context);
    }

    public static function clearContext(): void
    {
        self::$customContext = [];
    }

    public function handle(Throwable $exception): bool
    {
        if ($this->shouldSkip($exception)) {
            return false;
        }

        $data = $this->buildExceptionData($exception);

        if ($this->isSleeping($data)) {
            return false;
        }

        $response = $this->client->report([
            'exception' => $data,
            'user' => $this->getUser(),
        ]);

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
            self::$customContext = [];
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

    protected function buildExecutor(Throwable $exception): array
    {
        $lines = @file($exception->getFile());

        if ($lines === false) {
            return [];
        }

        $count = min(config('heybug.lines_count', 12), 50);
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
        try {
            if (function_exists('auth') && auth()->check()) {
                $user = auth()->user();

                if ($user instanceof \Illuminate\Database\Eloquent\Model) {
                    return $user->only(['id', 'name', 'email']);
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

        $except = config('heybug.except', []);

        return in_array(get_class($exception), $except);
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
}
