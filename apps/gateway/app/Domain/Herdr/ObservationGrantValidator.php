<?php

declare(strict_types=1);

namespace App\Domain\Herdr;

use App\Domain\Shared\ResourceOperationException;
use App\Models\HerdrObservationNonce;
use App\Models\HerdrSession;
use Illuminate\Support\Carbon;

final readonly class ObservationGrantValidator
{
    public function __construct(
        private ObservationGrantSigner $signer,
    ) {}

    public function validate(
        string $token,
        HerdrSession $session,
        string $pane,
        string $terminal,
        bool $consumeNonce = true,
    ): ObservationGrantClaims {
        try {
            $claims = $this->signer->verify($token);
        } catch (ResourceOperationException $exception) {
            throw $exception;
        }

        if ($claims->node !== $session->node->name || $claims->session !== $session->session) {
            throw new ResourceOperationException(
                errorCode: 'herdr.grant_invalid',
                message: 'The observation grant does not match this Node and Herdr session.',
                status: 403,
            );
        }

        if ($claims->pane !== $pane || $claims->terminal !== $terminal) {
            throw new ResourceOperationException(
                errorCode: 'herdr.grant_invalid',
                message: 'The observation grant does not match the recorded pane and terminal.',
                status: 403,
            );
        }

        if ($claims->expiresAt <= Carbon::now()->timestamp) {
            throw new ResourceOperationException(
                errorCode: 'herdr.grant_invalid',
                message: 'The observation grant has expired.',
                status: 403,
            );
        }

        $nonce = HerdrObservationNonce::query()->find($claims->nonce);

        if (! $nonce instanceof HerdrObservationNonce) {
            throw new ResourceOperationException(
                errorCode: 'herdr.grant_invalid',
                message: 'The observation grant nonce is unknown.',
                status: 403,
            );
        }

        if ($nonce->consumed_at !== null) {
            throw new ResourceOperationException(
                errorCode: 'herdr.grant_invalid',
                message: 'The observation grant nonce has already been used.',
                status: 403,
            );
        }

        if ($consumeNonce) {
            $nonce->update(['consumed_at' => Carbon::now()]);
        }

        return $claims;
    }
}
