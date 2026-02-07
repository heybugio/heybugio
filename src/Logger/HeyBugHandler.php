<?php

namespace HeyBug\Logger;

use HeyBug\HeyBug;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

class HeyBugHandler extends AbstractProcessingHandler
{
    public function __construct(
        protected HeyBug $heybug,
        int|string|Level $level = Level::Error,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (isset($record->context['exception']) && $record->context['exception'] instanceof Throwable) {
            $this->heybug->handle($record->context['exception']);
        }
    }
}
