<?php

declare(strict_types=1);

namespace App\Domain\Herdr;

final readonly class ObservationGrantClaims
{
    public function __construct(
        public string $node,
        public string $session,
        public string $pane,
        public string $terminal,
        public int $cols,
        public int $rows,
        public string $nonce,
        public int $expiresAt,
        public int $issuedAt,
        public string $origin,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function payload(): array
    {
        return [
            'iss' => HerdrObserveContract::GrantIssuer,
            'aud' => HerdrObserveContract::GrantAudience,
            'sub' => HerdrObserveContract::GrantScope,
            'node' => $this->node,
            'session' => $this->session,
            'pane' => $this->pane,
            'terminal' => $this->terminal,
            'cols' => $this->cols,
            'rows' => $this->rows,
            'origin' => $this->origin,
            'jti' => $this->nonce,
            'iat' => $this->issuedAt,
            'exp' => $this->expiresAt,
        ];
    }
}
