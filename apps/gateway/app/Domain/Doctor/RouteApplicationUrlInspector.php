<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\Instance;

interface RouteApplicationUrlInspector
{
    /**
     * Compares `APP_URL` in the `.env` of each application directory of the Instance's checkout with the
     * expected URL. A directory without `artisan` matches, because Orbit writes no `APP_URL` there. It
     * changes nothing on the Node.
     *
     * @param  array<string, string>  $expected  Expected URL keyed by directory relative to the checkout.
     * @return array<string, bool> Keyed like `$expected`. False for a missing `.env`, or an `APP_URL` that
     *                             is missing, repeated, or different.
     *
     * @throws DoctorInspectionException
     */
    public function inspect(Instance $instance, array $expected): array;
}
