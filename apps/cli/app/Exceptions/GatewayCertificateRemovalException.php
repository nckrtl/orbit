<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class GatewayCertificateRemovalException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Gateway profile was removed, but its pinned certificate could not be deleted.');
    }
}
