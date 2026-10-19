<?php

declare(strict_types=1);

namespace App\Support;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/** Adds the current X-Request-Id to every structured log line. */
final class RequestIdLogProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $id = null;
        try {
            if (app()->bound('request')) {
                $id = request()->attributes->get('request_id');
            }
        } catch (\Throwable) {
            $id = null;
        }

        if ($id !== null) {
            $record->extra['request_id'] = $id;
        }

        return $record;
    }
}
