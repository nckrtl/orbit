<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments;

use RuntimeException;

/** Stops input discovery after the shared Gateway boundary has already rendered its failure. */
final class DocumentRequestFailed extends RuntimeException {}
