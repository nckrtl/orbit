<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseVerifier;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

/**
 * `/up` plus Gateway status, the same checks as `bin/deploy-verify`. The version matches when it
 * equals the commit or one is a prefix of the other.
 */
final readonly class HttpGatewayReleaseVerifier implements GatewayReleaseVerifier
{
    public function __construct(
        private ProcessRunner $processes,
        private string $origin = 'https://gateway.orbit',
        private string $caFile = '/etc/caddy/orbit-cert-current/root-ca.pem',
    ) {}

    public function verify(string $sha): array
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

        if ($reportedStatus !== 'ok' || ! $this->versionMatches($sha, $version)) {
            throw new GatewayReleaseException(
                step: 'verify',
                errorCode: 'gateway.release_verify_failed',
                message: "Gateway status is [{$reportedStatus}] at version [{$version}], not commit [{$sha}].",
            );
        }

        return ['status' => $reportedStatus, 'version' => $version];
    }

    private function versionMatches(string $expected, string $observed): bool
    {
        $expected = strtolower($expected);
        $observed = strtolower($observed);

        if ($expected === '' || $observed === '' || $observed === 'dev') {
            return false;
        }

        return $expected === $observed
            || str_starts_with($expected, $observed)
            || str_starts_with($observed, $expected);
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
