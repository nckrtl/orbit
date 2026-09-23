<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\TaskSessionClassificationException;
use Laravel\Ai\PendingResponses\PendingClassification;
use Laravel\Ai\Responses\ClassificationResponse;
use Throwable;

/**
 * Sends one TypeSafe Jev classification and reports a failure without the provider's response.
 */
final readonly class Jev
{
    /** @throws TaskSessionClassificationException */
    public static function classify(PendingClassification $classification): ClassificationResponse
    {
        try {
            return $classification->classify();
        } catch (Throwable $exception) {
            $key = config('ai.providers.typesafe.key');
            $reason = ! is_string($key) || trim($key) === ''
                ? 'TypeSafe Jev is not configured. Set TYPESAFE_API_KEY.'
                : 'TypeSafe Jev request failed ('.class_basename($exception).').';

            throw new TaskSessionClassificationException($reason, previous: $exception);
        }
    }
}
