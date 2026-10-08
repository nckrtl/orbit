<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\Node;

interface PhpPoolDirectoryInspector
{
    /**
     * Lists the Orbit-rendered PHP-FPM pools on the Node whose working directory is missing: pools in a live
     * `orbit-scopes.conf`, which stop PHP-FPM from starting, and pools stored state renders, which converge
     * skips. It changes nothing on the Node.
     *
     * @return list<PhpPoolDirectoryObservation>
     *
     * @throws DoctorInspectionException
     */
    public function inspect(Node $node): array;
}
