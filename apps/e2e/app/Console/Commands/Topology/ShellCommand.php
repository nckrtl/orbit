<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\IncusHost;
use App\E2E\TopologyAcquirer;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\MountPath;
use App\E2E\Value\TopologyProfile;
use Throwable;

/**
 * An interactive login shell as `orbit` on one role, with the environment
 * `exec` uses. The terminal is passed through to `incus exec` untouched.
 */
final class ShellCommand extends E2ECommand
{
    #[\Override]
    protected $signature =
        'topology:shell {issue} {role} '
            .self::WORKTREE_OPTION
            .' {--proof : Open the retained failed proof topology} {--json}';
    #[\Override]
    protected $description = 'Open an interactive shell as orbit on one discovery or failed-proof role';

    public function handle(TopologyAcquirer $acquirer, IncusHost $host): int
    {
        try {
            $request = $this->request();
            $role = (string) $this->argument('role');
            $purpose = $this->option('proof') ? AttemptPurpose::Proof : AttemptPurpose::Discovery;
            $instance = $acquirer->instance($request, $role, $purpose);
            $this->log($request, "role={$role} instance={$instance}");
            $directory = in_array($role, TopologyProfile::CHECKOUT_ROLES, true)
                ? MountPath::GUEST_SOURCE
                : '/home/orbit';
            $command = [
                'incus',
                '--project',
                $host->scope()['project'],
                'exec',
                $host->scope()['remote'].':'.$instance,
                '--',
                ...self::shellArgv($directory),
            ];

            return $this->passthrough($command);
        } catch (Throwable $exception) {
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }

    /**
     * The exec prefix with the working directory swapped, then a login shell.
     *
     * @return list<string>
     */
    public static function shellArgv(string $directory): array
    {
        $prefix = GuestCommand::ORBIT_USER_PREFIX;
        $cwd = array_search('-C', $prefix, true);
        if ($cwd !== false) {
            $prefix[$cwd + 1] = $directory;
        }

        return [...$prefix, 'bash', '-l'];
    }

    /** @param list<string> $command */
    private function passthrough(array $command): int
    {
        $pipes = [];
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);
        if (! is_resource($process)) {
            throw new \RuntimeException('Unable to start the interactive shell.');
        }

        return proc_close($process);
    }
}
