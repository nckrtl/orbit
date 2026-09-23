<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\ProxyCli\ProxyCliAccountControlClient;
use App\Domain\ProxyCli\ProxyCliHostname;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

final readonly class HttpProxyCliAccountControlClient implements ProxyCliAccountControlClient
{
    public function __construct(private LeafCertificateSigner $certificates) {}

    public function setDisabled(
        string $wireguardIp,
        int $port,
        #[SensitiveParameter] string $controlToken,
        string $account,
        bool $disabled,
    ): void {
        $caFile = null;

        try {
            $caFile = tempnam(sys_get_temp_dir(), 'orbit-proxycli-ca-');

            if (! is_string($caFile) || file_put_contents($caFile, $this->certificates->rootCertificate()) === false) {
                throw new ResourceOperationException('proxycli.upstream_failed', 'The proxycli collector could not be reached.', 502);
            }

            Http::timeout(10)
                ->acceptJson()
                ->withToken($controlToken)
                ->withHeaders(['Host' => ProxyCliHostname::Value])
                ->withOptions(['verify' => $caFile])
                ->patch("https://{$wireguardIp}:{$port}/v1/accounts/".rawurlencode($account), ['disabled' => $disabled])
                ->throw();
        } catch (Throwable $exception) {
            if ($exception instanceof ResourceOperationException) {
                throw $exception;
            }

            throw new ResourceOperationException(
                'proxycli.upstream_failed',
                'The proxycli collector refused the account update.',
                502,
                previous: $exception,
            );
        } finally {
            if (is_string($caFile) && is_file($caFile)) {
                unlink($caFile);
            }
        }
    }
}
