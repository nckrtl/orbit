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
        return $this->phase($id, 'serve', 'caddy');
    }

    public function schedule(string $id): array
    {
        $result = $this->phase($id, 'schedule', 'scheduler');
        $result['cleanup_paused'] = ($result['cleanup_paused'] ?? true) === true;

        return $result;
    }

    /**
     * Runs one phase of `gateway:release:handoff` and returns its JSON object as it is, for the release record.
     *
     * @return array<string, mixed>
     *
     * @throws GatewayReleaseException
     */
    private function phase(string $id, string $phase, string $required): array
    {
        $result = $this->processes->run(new ProcessInvocation(
            arguments: ReleaseArtisan::command($this->php, $this->layout->releaseApplicationPath($id).'/artisan', ['gateway:release:handoff', '--phase='.$phase, '--no-interaction'], stepLock: $this->stepLock),
            timeout: $this->timeout,
        ));
        $decoded = json_decode(trim($result->stdout), true);
        $data = [];

        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            $data[(string) $key] = $value;
        }

        if (! $result->succeeded() || ! is_string($data[$required] ?? null)) {
            $errorCode = is_string($data['error_code'] ?? null) ? $data['error_code'] : 'gateway.release_handoff_failed';
            $message = is_string($data['message'] ?? null) ? $data['message'] : "The runtime handoff [{$phase}] to release [{$id}] failed.";

            throw new GatewayReleaseException(
                step: 'handoff',
                errorCode: $errorCode,
                message: $message,
                status: 500,
                result: $result,
            );
        }

        return $data;
    }
}
