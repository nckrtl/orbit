<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use Cron\CronExpression;

final class TaskDefinitionCron
{
    public static function canonical(string $expression): ?string
    {
        $fields = preg_split('/\s+/', trim($expression));

        if ($fields === false || count($fields) !== 5) {
            return null;
        }

        $normalized = implode(' ', $fields);

        if (! CronExpression::isValidExpression($normalized)) {
            return null;
        }

        return $normalized;
    }
}
