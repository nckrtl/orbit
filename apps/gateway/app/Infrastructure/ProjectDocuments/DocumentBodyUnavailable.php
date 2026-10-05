<?php

declare(strict_types=1);

namespace App\Infrastructure\ProjectDocuments;

use RuntimeException;

/** A typed corruption signal preserved through the AWS/Guzzle exception chain. Never contains bytes or keys. */
final class DocumentBodyUnavailable extends RuntimeException {}
