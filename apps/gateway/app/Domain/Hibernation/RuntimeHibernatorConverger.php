<?php

declare(strict_types=1);

namespace App\Domain\Hibernation;

interface RuntimeHibernatorConverger
{
    public function converge(): void;
}
