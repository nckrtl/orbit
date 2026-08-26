<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\Trust\GatewayRootCaTrustException;
use App\Services\Trust\GatewayRootCaTrustService;

final class GatewayTrustCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'gateway:trust
        {--accept-ca-change : Explicitly accept replacement of a pinned gateway root CA}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Trust the gateway root CA in the local operating-system trust store.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayRootCaTrustService $trust,
    ): int {
        $profile = $this->activeGatewayProfile($repository);

        if ($profile === null) {
            return self::FAILURE;
        }

        try {
            $result = $this->option('accept-ca-change') === true
                ? $trust->trustChangedCertificate($profile)
                : $trust->trust($profile);
        } catch (GatewayRootCaTrustException $exception) {
            return $this->failure($exception);
        }

        if ($this->option('json') === true) {
            $this->writeJson([
                'gateway' => $result->profile->name,
                'status' => $result->status,
                'sha256' => $result->certificate->fingerprint,
                'ca_path' => $result->profile->caPath,
                'request_id' => $result->requestId,
            ]);

            return self::SUCCESS;
        }

        $message = $result->status === 'already_trusted'
            ? 'Gateway root CA is already trusted.'
            : 'Gateway root CA trusted.';
        $this->info($message);
        $this->line("Request ID: {$result->requestId}");

        return self::SUCCESS;
    }

    private function failure(GatewayRootCaTrustException $exception): int
    {
        return $this->renderGatewayFailure(
            $exception->errorCode,
            $exception->getMessage(),
            $exception->requestId,
            "{$exception->errorCode}: {$exception->getMessage()}",
        );
    }
}
