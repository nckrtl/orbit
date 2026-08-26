<?php

declare(strict_types=1);

namespace App\Services\Trust;

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayRootCaClient;
use Orbit\Sdk\Requests\Gateway\FetchRootCaCertificateRequest;
use Orbit\Sdk\Responses\Gateway\RootCaCertificateResponse;
use Saloon\Exceptions\Request\FatalRequestException;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity The trust workflow fails closed at each transport and trust boundary.
 * @mago-expect lint:too-many-methods Private phase methods keep each trust boundary small and auditable.
 */
final readonly class GatewayRootCaTrustService
{
    public function __construct(
        private GatewayRootCaClient $bootstrapClient,
        private GatewayConnectorFactory $connectors,
        private GatewayRootCaStore $store,
        private TrustStoreInstallerResolver $installers,
        private GatewayConfigRepository $repository,
    ) {}

    public function trust(GatewayProfile $profile): GatewayRootCaTrustResult
    {
        $response = $this->fetchForBootstrap($profile->url);
        $certificate = $this->certificateFrom($response);
        $this->assertReplacementUnchanged($profile, $certificate, $response->requestId);

        return $this->finish($profile, $certificate, $response->requestId);
    }

    public function trustChangedCertificate(GatewayProfile $profile): GatewayRootCaTrustResult
    {
        $response = $this->fetchForBootstrap($profile->url);

        return $this->finish($profile, $this->certificateFrom($response), $response->requestId);
    }

    private function finish(
        GatewayProfile $profile,
        RootCertificate $certificate,
        string $requestId,
    ): GatewayRootCaTrustResult {
        $path = $this->store($profile, $certificate, $requestId);
        $verificationProfile = new GatewayProfile($profile->name, $profile->url, $path);
        $verifiedResponse = $this->fetchWithPinnedCertificate($verificationProfile, $requestId);
        $verifiedRequestId = $this->requestIdOrFallback($verifiedResponse->requestId, $requestId);
        $verifiedCertificate = $this->certificateFrom($verifiedResponse, $requestId);

        if (! hash_equals($certificate->fingerprint, $verifiedCertificate->fingerprint)) {
            throw new GatewayRootCaTrustException(
                errorCode: 'gateway.ca_verification_failed',
                message: 'Gateway root CA changed during pinned HTTPS verification.',
                requestId: $verifiedRequestId,
            );
        }

        $alreadyTrusted = $this->installLocally(
            $profile,
            $certificate,
            $path,
            $verifiedRequestId,
        );
        $this->updateProfile($verificationProfile, $verifiedRequestId);

        return new GatewayRootCaTrustResult(
            profile: $verificationProfile,
            certificate: $certificate,
            status: $alreadyTrusted ? 'already_trusted' : 'trusted',
            requestId: $verifiedRequestId,
        );
    }

    private function fetchForBootstrap(string $gatewayUrl): RootCaCertificateResponse
    {
        try {
            return $this->bootstrapClient->fetch($gatewayUrl);
        } catch (GatewayApiException $exception) {
            throw new GatewayRootCaTrustException(
                errorCode: $exception->errorCode() ?? 'gateway.ca_fetch_failed',
                message: 'Gateway root CA request failed.',
                requestId: $exception->requestId(),
                previous: $exception,
            );
        } catch (FatalRequestException $exception) {
            throw new GatewayRootCaTrustException(
                errorCode: 'gateway.ca_unavailable',
                message: 'Could not reach the gateway root CA endpoint.',
                previous: $exception,
            );
        } catch (Throwable $exception) {
            throw new GatewayRootCaTrustException(
                errorCode: 'gateway.ca_fetch_failed',
                message: 'Could not fetch the gateway root CA safely.',
                previous: $exception,
            );
        }
    }

    private function fetchWithPinnedCertificate(
        GatewayProfile $profile,
        string $requestId,
    ): RootCaCertificateResponse {
        try {
            /** @mago-expect analysis:mixed-assignment Saloon returns DTOs through a mixed boundary. */
            $response = $this->connectors
                ->make($profile)
                ->send(new FetchRootCaCertificateRequest)
                ->dto();
        } catch (GatewayApiException $exception) {
            throw new GatewayRootCaTrustException(
                errorCode: $exception->errorCode() ?? 'gateway.ca_verification_failed',
                message: 'Pinned gateway root CA verification request failed.',
                requestId: $exception->requestId() ?? $requestId,
                previous: $exception,
            );
        } catch (FatalRequestException $exception) {
            throw new GatewayRootCaTrustException(
                errorCode: 'gateway.ca_verification_failed',
                message: 'Could not verify the gateway through the fetched root CA.',
                requestId: $requestId,
                previous: $exception,
            );
        } catch (Throwable $exception) {
            throw new GatewayRootCaTrustException(
                errorCode: 'gateway.ca_verification_failed',
                message: 'Pinned gateway root CA verification failed.',
                requestId: $requestId,
                previous: $exception,
            );
        }

        if (! $response instanceof RootCaCertificateResponse) {
            throw new GatewayRootCaTrustException(
                errorCode: 'gateway.ca_response_invalid',
                message: 'Gateway root CA response is invalid.',
                requestId: $requestId,
            );
        }

        return $response;
    }

    private function certificateFrom(
        RootCaCertificateResponse $response,
        string $fallbackRequestId = '',
    ): RootCertificate {
        $requestId = $this->requestIdOrFallback($response->requestId, $fallbackRequestId);

        try {
            $certificate = RootCertificate::fromPem($response->certificate);
        } catch (Throwable $exception) {
            throw new GatewayRootCaTrustException(
                errorCode: 'gateway.ca_invalid',
                message: 'Gateway returned invalid root CA material.',
                requestId: $requestId,
                previous: $exception,
            );
        }

        $gatewayFingerprint = strtolower(str_replace(search: ':', replace: '', subject: $response->sha256));

        if (! hash_equals($certificate->fingerprint, $gatewayFingerprint)) {
            throw new GatewayRootCaTrustException(
                errorCode: 'gateway.ca_invalid',
                message: 'The gateway root CA fingerprint does not match.',
                requestId: $requestId,
            );
        }

        return $certificate;
    }

    private function requestIdOrFallback(string $requestId, string $fallbackRequestId): string
    {
        return $requestId !== '' ? $requestId : $fallbackRequestId;
    }

    private function assertReplacementUnchanged(
        GatewayProfile $profile,
        RootCertificate $certificate,
        string $requestId,
    ): void {
        if ($profile->caPath === null) {
            return;
        }

        try {
            $pinnedCertificate = RootCertificate::fromPath($profile->caPath);
        } catch (Throwable $exception) {
            throw new GatewayRootCaTrustException(
                errorCode: 'gateway.ca_pin_invalid',
                message: 'The pinned gateway root CA is not readable or valid.',
                requestId: $requestId,
                previous: $exception,
            );
        }

        if (! hash_equals($pinnedCertificate->fingerprint, $certificate->fingerprint)) {
            throw new GatewayRootCaTrustException(
                errorCode: 'gateway.ca_changed',
                message: 'The gateway root CA differs from the pinned certificate. '
                .'Re-run with --accept-ca-change only after you verify the new fingerprint.',
                requestId: $requestId,
            );
        }
    }

    private function store(
        GatewayProfile $profile,
        RootCertificate $certificate,
        string $requestId,
    ): string {
        try {
            return $this->store->store($profile->name, $certificate);
        } catch (Throwable $exception) {
            throw new GatewayRootCaTrustException(
                errorCode: 'gateway.ca_store_failed',
                message: 'Could not store the gateway root CA locally.',
                requestId: $requestId,
                previous: $exception,
            );
        }
    }

    private function installLocally(
        GatewayProfile $profile,
        RootCertificate $certificate,
        string $path,
        string $requestId,
    ): bool {
        try {
            $installer = $this->installers->resolve();
            $alreadyTrusted = $installer->isTrusted($certificate, $profile->name);

            if ($alreadyTrusted) {
                return true;
            }

            $installer->install($path, $profile->name);

            if (! $installer->isTrusted($certificate, $profile->name)) {
                throw new TrustStoreInstallException(
                    'The local trust store did not contain the installed gateway root CA.',
                );
            }

            return false;
        } catch (TrustStoreInstallException $exception) {
            throw new GatewayRootCaTrustException(
                errorCode: 'gateway.ca_install_failed',
                message: $exception->getMessage(),
                requestId: $requestId,
                previous: $exception,
            );
        } catch (Throwable $exception) {
            throw new GatewayRootCaTrustException(
                errorCode: 'gateway.ca_install_failed',
                message: 'Could not inspect or update the local certificate trust store.',
                requestId: $requestId,
                previous: $exception,
            );
        }
    }

    private function updateProfile(GatewayProfile $profile, string $requestId): void
    {
        try {
            $this->repository->add($profile);
        } catch (Throwable $exception) {
            throw new GatewayRootCaTrustException(
                errorCode: 'gateway.ca_profile_update_failed',
                message: 'The root CA was trusted, but the gateway profile could not be updated.',
                requestId: $requestId,
                previous: $exception,
            );
        }
    }
}
