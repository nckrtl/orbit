<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseVerifier;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Closure;

/**
 * `/up` plus Gateway status, the same checks as `bin/deploy-verify`. The version matches only the
 * full commit or its 12-digit release id. A busy pool can queue `/up` behind long requests, so a
 * failed check is tried again with backoff before the release fails.
 */
final readonly class HttpGatewayReleaseVerifier implements GatewayReleaseVerifier
{
    /** @var Closure(int): void */
    private Closure $sleep;

    /**
     * @param  list<int>  $backoff  seconds to wait before each retry; about a minute in all by default
     * @param  (Closure(int): void)|null  $sleep  seconds
     */
    public function __construct(
        private ProcessRunner $processes,
        private string $origin = 'https://gateway.orbit',
        private string $caFile = '/etc/caddy/orbit-cert-current/root-ca.pem',
        private array $backoff = [2, 4, 8, 16, 30],
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function verify(string $sha): array
    {
        return $this->retrying($sha);
    }

    public function serving(): array
    {
        return $this->retrying(null);
    }

    /** @return array{status: string, version: string} */
    private function retrying(?string $sha): array
    {
        $delays = $this->backoff;

        while (true) {
            try {
                return $this->check($sha);
            } catch (GatewayReleaseException $exception) {
                $delay = array_shift($delays);

                if ($delay === null) {
                    throw $exception;
                }

                ($this->sleep)($delay);
            }
        }
    }

    /** @return array{status: string, version: string} */
    private function check(?string $sha): array
    {
        $up = $this->get($this->origin.'/up');

        if (! $up->succeeded()) {
            throw $this->failed('Gateway /up did not succeed.', $up->stderr);
        }

        $status = $this->get($this->origin.'/api/v1/gateway/status');

        if (! $status->succeeded()) {
            throw $this->failed('Gateway status did not succeed.', $status->stderr);
        }

        $decoded = json_decode($status->stdout, true);
        $data = is_array($decoded) ? ($decoded['data'] ?? $decoded) : null;
        $reportedStatus = is_array($data) && is_string($data['status'] ?? null) ? $data['status'] : '';
        $version = is_array($data) && is_string($data['version'] ?? null) ? $data['version'] : '';

        if ($reportedStatus !== 'ok' || ($sha !== null && ! $this->versionMatches($sha, $version))) {
            throw new GatewayReleaseException(
                step: 'verify',
                errorCode: 'gateway.release_verify_failed',
                message: "Gateway status is [{$reportedStatus}] at version [{$version}]".($sha === null ? '.' : ", not commit [{$sha}]."),
            );
        }

        return ['status' => $reportedStatus, 'version' => $version];
    }

    private function versionMatches(string $expected, string $observed): bool
    {
        $expected = strtolower($expected);
        $observed = strtolower(trim($observed));

        return GatewayReleaseCommit::isSha($expected)
            && ($observed === $expected || $observed === GatewayReleaseCommit::id($expected));
    }

    private function get(string $url): CommandResult
    {
        $arguments = ['curl', '--silent', '--show-error', '--fail-with-body', '--max-time', '15', '--output', '-'];

        if (is_file($this->caFile)) {
            $arguments[] = '--cacert';
            $arguments[] = $this->caFile;
        }

        $arguments[] = '--';
        $arguments[] = $url;

        return $this->processes->run(new ProcessInvocation($arguments, timeout: 20.0));
    }

    private function failed(string $message, string $stderr): GatewayReleaseException
    {
        $detail = trim($stderr);

        return new GatewayReleaseException(
            step: 'verify',
            errorCode: 'gateway.release_verify_failed',
            message: $detail === '' ? $message : $message.' '.$detail,
        );
    }
}
