<?php

declare(strict_types=1);

namespace App\Actions\Herdr;

use App\Data\Herdr\IssueObservationGrantData;
use App\Domain\Herdr\HerdrObserveContract;
use App\Domain\Herdr\HerdrSessionInspector;
use App\Domain\Herdr\ObservationGrant;
use App\Domain\Herdr\ObservationGrantClaims;
use App\Domain\Herdr\ObservationGrantSigner;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\HerdrObservationNonce;
use App\Models\HerdrSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

final readonly class IssueObservationGrantAction
{
    public function __construct(
        private RequireHerdrToolAction $requireTool,
        private ObservationGrantSigner $signer,
        private HerdrObserveContract $contract,
        private HerdrSessionInspector $inspector,
    ) {}

    public function execute(HerdrSession $session, IssueObservationGrantData $data): ObservationGrant
    {
        $session->loadMissing('node');
        $this->requireTool->execute($session->node);

        if ($session->observer_url === null || $session->observer_status !== 'published') {
            throw new ResourceOperationException(
                errorCode: 'herdr.observer_failed',
                message: "Herdr session [{$session->session}] has no published observer.",
                status: 422,
            );
        }

        try {
            $inspection = $this->inspector->inspect($session, $session->node);
        } catch (ResourceOperationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ResourceOperationException(
                errorCode: 'herdr.inspection_failed',
                message: 'Herdr session inspection failed.',
                status: 422,
                previous: $exception,
            );
        }

        try {
            $this->contract->assertCompatible($inspection);
        } catch (ResourceOperationException $exception) {
            $session->update([
                'herdr_version' => $inspection->version,
                'protocol' => $inspection->protocol,
                'observer_status' => 'failed',
                'observer_error' => 'observer capability verification failed',
                'status' => LifecycleStatus::Failed,
                'error_code' => $exception->errorCode,
                'failed_step' => 'observer',
            ]);

            throw $exception;
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
            origin: $data->origin,
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
