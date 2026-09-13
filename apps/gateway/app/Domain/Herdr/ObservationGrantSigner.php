<?php

declare(strict_types=1);

namespace App\Domain\Herdr;

interface ObservationGrantSigner
{
    public function sign(ObservationGrantClaims $claims): string;

    public function verify(string $token): ObservationGrantClaims;

    /**
     * @return array{keys: list<array<string, string>>}
     */
    public function jwks(): array;
}
