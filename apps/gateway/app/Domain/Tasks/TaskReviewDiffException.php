<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use RuntimeException;

/** The review diff could not be read. The scheduler retries the request instead of sending an empty change. */
final class TaskReviewDiffException extends RuntimeException {}
