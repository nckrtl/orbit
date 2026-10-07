<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use RuntimeException;

/** A rollout step failed, and the Node no longer answers SSH. The Node is `unreachable`, not failed. */
final class NodeUnreachable extends RuntimeException {}
