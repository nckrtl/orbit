<?php

declare(strict_types=1);

namespace App\Support;

interface GatedExtensionCommand
{
    public function extensionSlug(): ?string;
}
