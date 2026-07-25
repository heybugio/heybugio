<?php

namespace HeyBug\Fakes;

use HeyBug\HeyBug;
use HeyBug\Reporting\Envelope;
use PHPUnit\Framework\Assert as PHPUnit;
use Throwable;

/**
 * Records reports instead of delivering them.
 *
 * The payload is built exactly as it would be for a real report, so
 * scrubbing, the payload ceiling, the authenticated user and custom context
 * are all present and assertable. Only the transport is replaced.
 *
 * Two gates are deliberately not applied. The environment gate would mean
 * nothing records at all unless the app added `testing` to heybug.environments,
 * and the dedup window would make a second assertion in the same test depend
 * on cache state left by the first. Both describe when a *deployed* app should
 * report, which is not what a test is asking about. `except` is still honoured,
 * since ignoring a class is a policy worth asserting.
 */
class HeyBugFake extends HeyBug
{
    /**
     * @var list<Envelope>
     */
    protected array $reported = [];

    public function handle(Throwable $exception): bool
    {
        try {
            foreach (config('heybug.except', []) as $class) {
                if ($exception instanceof $class) {
                    return false;
                }
            }

            $this->reported[] = new Envelope('default', [
                'exception' => $this->buildExceptionData($exception),
                'user' => $this->getUser(),
            ]);

            return true;
        } finally {
            static::clearContext();
        }
    }

    /**
     * Nothing is held back, so there is never anything to deliver.
     */
    public function flush(): void
    {
        //
    }

    /**
     * The reports recorded so far, optionally of one exception class.
     *
     * @return list<Envelope>
     */
    public function reported(?string $class = null): array
    {
        if ($class === null) {
            return $this->reported;
        }

        return array_values(array_filter(
            $this->reported,
            fn (Envelope $envelope): bool => $envelope->payload['exception']['class'] === $class
        ));
    }

    public function assertReported(string $class, ?callable $callback = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->matching($class, $callback),
            "Expected [{$class}] to be reported to HeyBug, but it was not."
        );
    }

    public function assertNotReported(string $class, ?callable $callback = null): void
    {
        PHPUnit::assertEmpty(
            $this->matching($class, $callback),
            "Expected [{$class}] not to be reported to HeyBug, but it was."
        );
    }

    public function assertReportedCount(int $expected): void
    {
        PHPUnit::assertCount(
            $expected,
            $this->reported,
            "Expected {$expected} report(s) to HeyBug, got ".count($this->reported).'.'
        );
    }

    public function assertNothingReported(): void
    {
        $this->assertReportedCount(0);
    }

    /**
     * @return list<Envelope>
     */
    protected function matching(string $class, ?callable $callback): array
    {
        $envelopes = $this->reported($class);

        if ($callback === null) {
            return $envelopes;
        }

        return array_values(array_filter($envelopes, $callback));
    }
}
