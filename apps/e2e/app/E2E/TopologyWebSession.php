<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestProcess;
use Closure;
use RuntimeException;

/** A foreground owner of the operator's transient unit and loopback publication. */
final readonly class TopologyWebSession
{
    public function __construct(private IncusHost $host) {}

    /**
     * @param  Closure(): bool  $keepRunning
     * @param  Closure(string): void  $output
     */
    public function run(string $instance, Closure $keepRunning, Closure $output): void
    {
        $port = $this->availablePort();
        $token = bin2hex(random_bytes(16));
        try {
            // Persist the token in the atomic reservation before any unit can start.
            $this->host->publishWeb($instance, $port, $token);
            $process = new GuestProcess('web');
            $listeners = $this->host->exec($instance, GuestCommand::asOrbitUser(['ss', '-H', '-ltn', 'sport = :5173']));
            if (! $listeners->successful() || trim($listeners->stdout) !== '') {
                throw new RuntimeException('Guest port 5173 is occupied or could not be checked; web will not choose another port.');
            }
            $started = $this->host->exec($instance, GuestCommand::asOrbitUser([
                'sudo', 'bash', '/home/orbit/orbit/apps/e2e/resources/web-unit.sh', 'start', $token,
            ]));
            if (! $started->successful()) {
                throw new RuntimeException('The topology web unit could not start: '.$started->stderr);
            }
            $deadline = microtime(true) + 180;
            $ready = false;
            $cursor = null;
            while ($keepRunning()) {
                $logs = $this->host->exec($instance, GuestCommand::asOrbitUser([
                    ...$process->logsArgv(), '--output=cat', '--show-cursor',
                    ...($cursor === null ? ['--since=-1min'] : ['--after-cursor='.$cursor]),
                ]));
                if (! $logs->successful()) {
                    throw new RuntimeException('The topology web journal is unavailable.');
                }
                $text = $logs->stdout;
                if (preg_match('/^-- cursor: (.+)$/m', $text, $match) === 1) {
                    $cursor = $match[1];
                    $text = str_replace($match[0], '', $text);
                }
                if (trim($text) !== '') {
                    $output(rtrim($text));
                }
                $active = $this->host->exec($instance, GuestCommand::asOrbitUser([
                    'systemctl', 'is-active', '--quiet', $process->unit,
                ]));
                if (! $active->successful()) {
                    throw new RuntimeException('The topology web unit exited.');
                }
                if (! $ready) {
                    $probe = $this->host->exec($instance, GuestCommand::asOrbitUser([
                        'curl', '--fail', '--silent', '--output', '/dev/null', '--max-time', '2', 'http://127.0.0.1:5173/',
                    ]));
                    if ($probe->successful()) {
                        $ready = true;
                        $output("Topology web: http://127.0.0.1:{$port} (beast loopback; guest port 5173)");
                        $output("Mac: ssh -N -o ExitOnForwardFailure=yes -L 127.0.0.1:5173:127.0.0.1:{$port} beast");
                    } elseif (microtime(true) >= $deadline) {
                        throw new RuntimeException('The topology web server did not become ready in 180 seconds.');
                    }
                }
                usleep(500_000);
            }
        } finally {
            // A failed response proves neither that the write failed nor that it succeeded.
            // Re-read the reservation and clean only this token, including unacknowledged starts.
            $this->host->stopWeb($instance, expectedToken: $token);
        }
    }

    private function availablePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('Could not assign a host loopback port.');
        }
        try {
            $address = stream_socket_get_name($socket, false);
            if ($address === false) {
                throw new RuntimeException('Could not read the assigned host loopback port.');
            }

            return (int) substr($address, strrpos($address, ':') + 1);
        } finally {
            fclose($socket);
        }
    }
}
