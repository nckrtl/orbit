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
        /** Runs the handoff in this process for a release whose code has no handoff command or no phases. */
        private ?GatewayReleaseRuntime $fallback = null,
    ) {}

    public function handoff(string $id): array
    {
        return $this->phase($id, 'serve', 'caddy') ?? $this->fallback()->handoff($id);
    }

    public function schedule(string $id): array
    {
        $result = $this->phase($id, 'schedule', 'scheduler') ?? $this->fallback()->schedule($id);
        $result['cleanup_paused'] = ($result['cleanup_paused'] ?? true) === true;

        return $result;
    }

    private function fallback(): GatewayReleaseRuntime
    {
        return $this->fallback ?? throw new GatewayReleaseException(step: 'handoff', errorCode: 'gateway.release_handoff_failed', message: 'No handoff fallback is configured.', status: 500);
    }

    /**
     * Runs one phase of `gateway:release:handoff` and returns its JSON object as it is, for the release record.
     *
     * @return array<string, mixed>|null null when the release's code cannot run this phase and a fallback can
     *
     * @throws GatewayReleaseException
     */
    private function phase(string $id, string $phase, string $required): ?array
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

        $output = $result->stdout.$result->stderr;

        if (
            $data === []
            && $this->fallback instanceof GatewayReleaseRuntime
            // Symfony Console's words for a missing command, a missing namespace, and a missing option.
            && preg_match('/is not defined|no commands defined in the|option does not exist/', $output) === 1
        ) {
            // An older release, such as the first release of adoption, built from a commit before the handoff phases.
            return null;
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
