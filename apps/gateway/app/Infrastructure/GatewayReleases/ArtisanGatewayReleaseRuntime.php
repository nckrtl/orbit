<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

/**
 * Runs `gateway:release:handoff` with the code of the release being handed over to, not the deployer's own code.
 * The deployer runs from the previous release, so a release that changes the Caddy, FPM, or unit rendering would
 * otherwise apply only one release later.
 */
final readonly class ArtisanGatewayReleaseRuntime implements GatewayReleaseRuntime
{
    public function __construct(
        private GatewayReleaseLayout $layout,
        private ProcessRunner $processes,
        private string $php = '/usr/bin/php8.5',
        private float $timeout = 1_230.0,
        private ?string $stepLock = null,
    ) {}

    public function handoff(string $id): array
    {
        $result = $this->processes->run(new ProcessInvocation(
            arguments: ReleaseArtisan::command($this->php, $this->layout->releaseApplicationPath($id).'/artisan', ['gateway:release:handoff', '--no-interaction'], stepLock: $this->stepLock),
            timeout: $this->timeout,
        ));
        $decoded = json_decode(trim($result->stdout), true);
        $data = is_array($decoded) ? $decoded : [];

        if (! $result->succeeded() || ! is_string($data['caddy'] ?? null)) {
            $errorCode = is_string($data['error_code'] ?? null) ? $data['error_code'] : 'gateway.release_handoff_failed';
            $message = is_string($data['message'] ?? null) ? $data['message'] : "The runtime handoff to release [{$id}] failed.";

            throw new GatewayReleaseException(
                step: 'handoff',
                errorCode: $errorCode,
                message: $message,
                status: 500,
                result: $result,
            );
        }

        return [
            'caddy' => (string) $data['caddy'],
            'fpm' => is_string($data['fpm'] ?? null) ? $data['fpm'] : 'unknown',
            'opcache' => is_string($data['opcache'] ?? null) ? $data['opcache'] : 'unknown',
            'scheduler' => is_string($data['scheduler'] ?? null) ? $data['scheduler'] : 'unknown',
            'scheduler_unit' => is_string($data['scheduler_unit'] ?? null) ? $data['scheduler_unit'] : null,
            'scheduler_drain' => $this->drain($data['scheduler_drain'] ?? null),
            'processes_restarted' => array_values(array_filter(is_array($data['processes_restarted'] ?? null) ? $data['processes_restarted'] : [], is_string(...))),
            'cleanup' => is_string($data['cleanup'] ?? null) ? $data['cleanup'] : 'unknown',
            'cleanup_error_code' => is_string($data['cleanup_error_code'] ?? null) ? $data['cleanup_error_code'] : null,
            'agent_view' => is_string($data['agent_view'] ?? null) ? $data['agent_view'] : 'unknown',
            'cleanup_paused' => ($data['cleanup_paused'] ?? true) === true,
        ];
    }

    /**
     * The scheduler drain the handoff reported, kept as it is for the release record.
     *
     * @return array<string, mixed>|null
     */
    private function drain(mixed $drain): ?array
    {
        if (! is_array($drain)) {
            return null;
        }

        $keyed = [];

        foreach ($drain as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }
}
