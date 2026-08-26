<?php

declare(strict_types=1);

namespace App\Services\Trust;

interface TrustStoreInstaller
{
    public function isTrusted(RootCertificate $certificate, string $label): bool;

    public function install(string $certificatePath, string $label): void;
}
