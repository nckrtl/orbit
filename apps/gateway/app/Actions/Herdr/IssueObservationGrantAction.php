<?php

declare(strict_types=1);

namespace App\Actions\Herdr;

use App\Data\Herdr\IssueObservationGrantData;
use App\Domain\Herdr\HerdrObserveContract;
use App\Domain\Herdr\ObservationGrant;
use App\Domain\Herdr\ObservationGrantClaims;
use App\Domain\Herdr\ObservationGrantSigner;
use App\Domain\Shared\ResourceOperationException;
use App\Models\HerdrObservationNonce;
use App\Models\HerdrSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final readonly class IssueObservationGrantAction
{
    public function __construct(
        private ObservationGrantSigner $signer,
        private HerdrObserveContract $contract,
    ) {}

    public function execute(HerdrSession $session, IssueObservationGrantData $data): ObservationGrant
    {
        $session->loadMissing('node');

        if ($session->observer_url === null || $session->observer_status !== 'published') {
            throw new ResourceOperationException(
                errorCode: 'herdr.observer_failed',
                message: "Herdr session [{$session->session}] has no published observer.",
                status: 422,
            );
        }

        $issuedAt = Carbon::now()->timestamp;
        $nonce = Str::lower(bin2hex(random_bytes(16)));
        $claims = new ObservationGrantClaims(
            node: $session->node->name,
            session: $session->session,
            pane: $data->pane,
            terminal: $data->terminal,
            cols: $data->cols,
            rows: $data->rows,
            nonce: $nonce,
            expiresAt: $issuedAt + HerdrObserveContract::GrantTtlSeconds,
            issuedAt: $issuedAt,
        );

        HerdrObservationNonce::query()->create([
            'jti' => $nonce,
            'expires_at' => Carbon::createFromTimestamp($claims->expiresAt),
        ]);

        $token = $this->signer->sign($claims);

        return new ObservationGrant(
            observerUrl: $this->contract->observerUrl($session->observer_hostname).'?access_token='.$token,
            token: $token,
            claims: $claims,
        );
    }
}
