<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockResponse;

/*
 * Stand-ins for `orbit self-update` tests. The release bytes under tests/Fixtures/SelfUpdate are the ones whose
 * checksums the recorded Gateway fixtures name; see apps/gateway/tests/Fixtures/GitHub/CliRelease/SOURCE.md.
 */

const SELF_UPDATE_OLD_BINARY = "#!/bin/sh\necho 'Orbit 0.4600.0'\n";

const SELF_UPDATE_AGENT_BYTES = "orbit-agent 0.3.0 fixture binary\n";

function self_update_release_bytes(string $name): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/SelfUpdate/'.$name));
}

/**
 * The recorded desired state with the agent pin rewritten to checksums of local stand-in bytes, so a test can
 * download an agent that matches the pin.
 */
function self_update_state_with_agent(string $fixture = 'gateway/self-update/available'): MockResponse
{
    $body = json_decode(gateway_fixture($fixture)['body'], true, flags: JSON_THROW_ON_ERROR);

    foreach ($body['data']['agent']['assets'] as $index => $asset) {
        $body['data']['agent']['assets'][$index]['sha256'] = hash('sha256', SELF_UPDATE_AGENT_BYTES);
    }

    return MockResponse::make($body);
}

/**
 * Fakes the processes self-update starts: `curl` writes the named release asset, the candidate reports its
 * version, and `systemctl` succeeds unless told otherwise. Every command is recorded in `$this->processes`.
 *
 * @param  array<string, string>  $downloads  Bytes to serve by asset name instead of the fixture files.
 */
function fake_self_update_processes(object $test, array $downloads = [], string $reported = 'Orbit 0.4681.0', int $restartExit = 0, ?Closure $onDownload = null): void
{
    $test->processes = [];

    Process::fake(static function (PendingProcess $process) use ($test, $downloads, $reported, $restartExit, $onDownload) {
        $command = (array) $process->command;
        $test->processes[] = $command;

        if ($command[0] === 'curl') {
            $url = (string) end($command);
            $output = $command[array_search('--output', $command, true) + 1];
            $name = basename($url);

            if ($onDownload instanceof Closure && ($result = $onDownload($name, $output)) !== null) {
                return $result;
            }

            file_put_contents($output, $downloads[$name] ?? ($name === 'orbit-agent-0.3.0-linux-x86_64' ? SELF_UPDATE_AGENT_BYTES : self_update_release_bytes($name)));

            return Process::result();
        }

        if (($command[1] ?? null) === '--version') {
            return Process::result(output: $reported."\n");
        }

        if ($command[0] === 'systemctl') {
            return Process::result(exitCode: $restartExit, errorOutput: $restartExit === 0 ? '' : 'Failed to restart orbit-agent.service.');
        }

        return Process::result(exitCode: 127);
    });
}

/** @return list<string> The command names the fake recorded, such as `curl SHA256SUMS`. */
function self_update_commands(object $test): array
{
    return array_map(static fn (array $command): string => match (true) {
        $command[0] === 'curl' => 'curl '.basename((string) end($command)),
        ($command[1] ?? null) === '--version' => 'version',
        default => implode(' ', $command),
    }, $test->processes);
}

/**
 * @param  array<string, mixed>  $result
 * @return array<string, mixed>
 */
function self_update_step(array $result, string $step): array
{
    foreach ($result['steps'] as $candidate) {
        if ($candidate['step'] === $step) {
            return $candidate;
        }
    }

    throw new RuntimeException("The result has no {$step} step.");
}
