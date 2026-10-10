<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use LogicException;

/**
 * Refuses a privileged local command that names a real host path Orbit manages.
 *
 * Production code reaches the host through the container's ProcessRunner. A test that leaves it real and keeps
 * the default `/etc/systemd`, `/etc/dnsmasq.d`, or `/var/lib/orbit` paths would change the machine that runs the
 * suite. A temporary root such as `/tmp/orbit-x/etc/systemd` does not match.
 */
final readonly class HostPathGuardedProcessRunner implements ProcessRunner
{
    private const string HostPath = '#(?<![\w.~/-])/(?:etc/systemd|etc/dnsmasq(?:\.d|\.conf)?|var/lib/orbit)(?![\w.-])#';

    public function __construct(private ProcessRunner $runner) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        self::assertSafe($invocation->arguments, $invocation->input);

        return $this->runner->run($invocation);
    }

    /** @param list<string> $arguments */
    public static function assertSafe(array $arguments, ?string $input): void
    {
        if (! in_array('sudo', $arguments, true) && posix_geteuid() !== 0) {
            return;
        }

        if (preg_match(self::HostPath, implode("\n", $arguments)."\n".($input ?? ''), $match) === 1) {
            throw new LogicException(
                "A test ran a privileged command against the host path {$match[0]}. "
                .'Give the code under test a temporary root or a fake ProcessRunner.',
            );
        }
    }
}
