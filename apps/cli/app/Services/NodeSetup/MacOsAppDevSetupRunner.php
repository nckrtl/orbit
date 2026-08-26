<?php

declare(strict_types=1);

namespace App\Services\NodeSetup;

use App\Data\NodeSetupExecutionResult;
use App\Exceptions\LocalNodeSetupException;
use App\Support\LocalDiagnosticRedactor;
use Closure;
use Throwable;

/**
 * @mago-expect lint:excessive-parameter-list Native boundaries are injectable for deterministic process-security tests.
 * @mago-expect lint:cyclomatic-complexity Each branch closes or verifies one local process boundary.
 * @mago-expect lint:kan-defect The bounded state machine handles explicit secure process transitions.
 * @mago-expect lint:too-many-methods Process, filesystem, TTY, and signal boundaries stay private to this runner.
 * @mago-expect lint:too-many-properties Native operations are injectable for deterministic security regression tests.
 */
class MacOsAppDevSetupRunner
{
    private const int POLL_MICROSECONDS = 10_000;

    private const float PHASE_SECONDS = 2.0;

    private const int NATIVE_OUTPUT_BYTES = 65_536;

    /** @var Closure(list<string>, array{0: resource, 1: resource, 2: resource}): mixed */
    private readonly Closure $processStarter;

    /** @var Closure(mixed): (array<string, mixed>|false) */
    private readonly Closure $processStatus;

    /** @var Closure(mixed): int */
    private readonly Closure $processCloser;

    /** @var Closure(int): list<int|string> */
    private readonly Closure $childLookup;

    /** @var Closure(int): list<int|string> */
    private readonly Closure $processGroupLookup;

    /** @var Closure(int): (array{pid: int, ppid: int, uid: int, pgid: int}|null) */
    private readonly Closure $processInspector;

    /** @var Closure(int, int): bool */
    private readonly Closure $signalSender;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /** @var Closure(int): null */
    private readonly Closure $sleeper;

    /** @var Closure(): string */
    private readonly Closure $tempDirectory;

    /** @var Closure(): int */
    private readonly Closure $effectiveUid;

    /** @var Closure(): int */
    private readonly Closure $currentPid;

    private bool $interruptRequested = false;

    /**
     * @param null|(Closure(list<string>, array{0: resource, 1: resource, 2: resource}): mixed) $processStarter
     * @param null|(Closure(mixed): (array<string, mixed>|false)) $processStatus
     * @param null|(Closure(mixed): int) $processCloser
     * @param null|(Closure(int): list<int|string>) $childLookup
     * @param null|(Closure(int): list<int|string>) $processGroupLookup
     * @param null|(Closure(int): (array{pid: int, ppid: int, uid: int, pgid: int}|null)) $processInspector
     * @param null|(Closure(int, int): bool) $signalSender
     * @param null|(Closure(): float) $clock
     * @param null|(Closure(int): null) $sleeper
     * @param null|(Closure(): string) $tempDirectory
     * @param null|(Closure(): int) $effectiveUid
     * @param null|(Closure(): int) $currentPid
     */
    public function __construct(
        private readonly LocalDiagnosticRedactor $redactor,
        ?Closure $processStarter = null,
        ?Closure $processStatus = null,
        ?Closure $processCloser = null,
        ?Closure $childLookup = null,
        ?Closure $processGroupLookup = null,
        ?Closure $processInspector = null,
        ?Closure $signalSender = null,
        ?Closure $clock = null,
        ?Closure $sleeper = null,
        ?Closure $tempDirectory = null,
        ?Closure $effectiveUid = null,
        ?Closure $currentPid = null,
    ) {
        $this->processStarter = $processStarter ?? $this->startNativeProcess(...);
        $this->processStatus = $processStatus ?? $this->nativeProcessStatus(...);
        $this->processCloser = $processCloser ?? $this->closeNativeProcess(...);
        $this->childLookup = $childLookup ?? $this->lookupChildren(...);
        $this->processGroupLookup = $processGroupLookup ?? $this->lookupProcessGroupMembers(...);
        $this->processInspector = $processInspector ?? $this->inspectProcess(...);
        $this->signalSender = $signalSender ?? posix_kill(...);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
        $this->sleeper = $sleeper ?? static function (int $microseconds): null {
            if ($microseconds >= 0) {
                usleep($microseconds);
            }

            return null;
        };
        $this->tempDirectory = $tempDirectory ?? sys_get_temp_dir(...);
        $this->effectiveUid = $effectiveUid ?? posix_geteuid(...);
        $this->currentPid = $currentPid ?? posix_getpid(...);
    }

    public function requestInterrupt(): void
    {
        $this->interruptRequested = true;
    }

    /** @mago-expect lint:halstead The method owns one staged process lifecycle and its cleanup proof. */
    public function run(string $script, ControllingTerminal $terminal): NodeSetupExecutionResult
    {
        $temporaryDirectory = $this->verifiedTemporaryDirectory();
        $expectedUid = ($this->effectiveUid)();

        if (! is_int($expectedUid) || $expectedUid < 0) {
            throw new LocalNodeSetupException('Local setup could not be started.');
        }

        $scriptPath = null;
        $transcriptPath = null;
        $transcript = null;
        $process = null;
        $processStarted = false;
        $ownedTreeGone = false;

        try {
            $scriptPath = $this->createScript($temporaryDirectory, $expectedUid, $script);
            $transcriptPath = $this->createTranscriptFifo($temporaryDirectory, $expectedUid);
            $transcript = $this->openTranscript($transcriptPath);
            $descriptors = $this->terminalDescriptors($terminal);
            $argv = [
                '/usr/bin/script',
                '-q',
                '-e',
                '-F',
                $transcriptPath,
                '/bin/bash',
                $scriptPath,
            ];

            try {
                $process = ($this->processStarter)($argv, $descriptors);
            } catch (Throwable) {
                throw new LocalNodeSetupException('Local setup could not be started.');
            } finally {
                foreach ($descriptors as $descriptor) {
                    if (is_resource($descriptor)) {
                        fclose($descriptor);
                    }
                }
            }

            if ($process === false || $process === null) {
                throw new LocalNodeSetupException('Local setup could not be started.');
            }

            $processStarted = true;
            $initialStatus = $this->status($process);
            $parentPid = $this->statusPid($initialStatus);
            $parentPpid = ($this->currentPid)();

            if (! is_int($parentPpid) || $parentPpid < 1) {
                throw new LocalNodeSetupException('Local setup could not be completed.');
            }

            $diagnostics = '';
            $handledInterrupt = false;
            $exitCode = -1;

            while (true) {
                $diagnostics = $this->drainTranscript($transcript, $diagnostics);

                if ($this->interruptRequested) {
                    $exitCode = $this->terminateOwnedTree(
                        $process,
                        $parentPid,
                        $parentPpid,
                        $expectedUid,
                        $transcript,
                        $diagnostics,
                    );
                    $handledInterrupt = true;
                    $ownedTreeGone = true;

                    break;
                }

                $status = $this->status($process);

                if (! $this->statusRunning($status)) {
                    $exitCode = $this->reap($process, $status);
                    $this->assertNoOwnedChildren($parentPid, $expectedUid);
                    $ownedTreeGone = true;

                    break;
                }

                ($this->sleeper)(self::POLL_MICROSECONDS);
            }

            $diagnostics = $this->drainTranscript($transcript, $diagnostics);

            return new NodeSetupExecutionResult(
                exitCode: $handledInterrupt ? 130 : $exitCode,
                diagnostics: $diagnostics,
                interrupted: $handledInterrupt,
            );
        } finally {
            if (is_resource($transcript)) {
                fclose($transcript);
            }

            if (! $processStarted || $ownedTreeGone) {
                $this->removeArtifact($transcriptPath, 0o010_000, $temporaryDirectory, $expectedUid);
                $this->removeArtifact($scriptPath, 0o100_000, $temporaryDirectory, $expectedUid);
            }
        }
    }

    /**
     * @param resource $transcript
     */
    private function terminateOwnedTree(
        mixed $process,
        int $parentPid,
        int $parentPpid,
        int $expectedUid,
        mixed $transcript,
        string &$diagnostics,
    ): int {
        $children = $this->waitForChildrenOrExit(
            $process,
            $parentPid,
            $parentPpid,
            $expectedUid,
            $transcript,
            $diagnostics,
        );

        if ($children === null) {
            $status = $this->status($process);

            return $this->reap($process, $status);
        }

        $ownedProcessGroup = $this->ownedProcessGroup($children, $expectedUid);

        foreach ([SIGINT, SIGTERM, SIGKILL] as $signal) {
            $targets = $this->signalTargets(
                $process,
                $parentPid,
                $parentPpid,
                $expectedUid,
                $children,
                $ownedProcessGroup,
            );

            if ($targets === []) {
                $status = $this->status($process);
                $exitCode = $this->reap($process, $status);
                $this->assertNoOwnedChildren($parentPid, $expectedUid);
                $this->assertKnownChildrenGone($children, $parentPid, $expectedUid);
                $this->assertOwnedProcessGroupGone($ownedProcessGroup, $expectedUid);

                return $exitCode;
            }

            foreach ($targets as $target) {
                if (($this->signalSender)($target, $signal) !== true) {
                    throw new LocalNodeSetupException('Local setup could not be completed.');
                }
            }

            if ($this->waitForOwnedTreeExit(
                $process,
                $parentPid,
                $parentPpid,
                $expectedUid,
                $children,
                $ownedProcessGroup,
                $transcript,
                $diagnostics,
            )) {
                $status = $this->status($process);
                $exitCode = $this->reap($process, $status);
                $this->assertNoOwnedChildren($parentPid, $expectedUid);
                $this->assertKnownChildrenGone($children, $parentPid, $expectedUid);
                $this->assertOwnedProcessGroupGone($ownedProcessGroup, $expectedUid);

                return $exitCode;
            }

            $children = $this->mergeChildren(
                $children,
                $this->validatedChildren($parentPid, $expectedUid),
            );
        }

        throw new LocalNodeSetupException('Local setup could not be completed.');
    }

    /**
     * @param resource $transcript
     * @return list<array{pid: int, ppid: int, uid: int, pgid: int}>|null
     */
    private function waitForChildrenOrExit(
        mixed $process,
        int $parentPid,
        int $parentPpid,
        int $expectedUid,
        mixed $transcript,
        string &$diagnostics,
    ): ?array {
        $deadline = ($this->clock)() + self::PHASE_SECONDS;

        do {
            $status = $this->status($process);

            if (! $this->statusRunning($status)) {
                $this->assertNoOwnedChildren($parentPid, $expectedUid);

                return null;
            }

            $this->validatedParent($parentPid, $parentPpid, $expectedUid);
            $children = $this->validatedChildren($parentPid, $expectedUid);

            if ($children !== []) {
                return $children;
            }

            $diagnostics = $this->drainTranscript($transcript, $diagnostics);

            if (($this->clock)() >= $deadline) {
                return [];
            }

            ($this->sleeper)(self::POLL_MICROSECONDS);
        } while (true);
    }

    /**
     * @param resource $transcript
     * @param list<array{pid: int, ppid: int, uid: int, pgid: int}> $knownChildren
     * @param null|array{pgid: int, members: list<array{pid: int, ppid: int, uid: int, pgid: int}>} $ownedProcessGroup
     */
    private function waitForOwnedTreeExit(
        mixed $process,
        int $parentPid,
        int $parentPpid,
        int $expectedUid,
        array $knownChildren,
        ?array &$ownedProcessGroup,
        mixed $transcript,
        string &$diagnostics,
    ): bool {
        $deadline = ($this->clock)() + self::PHASE_SECONDS;

        do {
            $status = $this->status($process);
            $children = $this->validatedChildren($parentPid, $expectedUid);
            $knownChildren = $this->mergeChildren($knownChildren, $children);
            $knownChildrenStillRunning = $ownedProcessGroup === null
                ? $this->validatedKnownChildren($knownChildren, $parentPid, $expectedUid)
                : [];
            $groupMembersStillRunning = $ownedProcessGroup === null
                ? []
                : $this->liveOwnedProcessGroupMembers($ownedProcessGroup, $expectedUid);

            if (
                ! $this->statusRunning($status)
                && $children === []
                && $knownChildrenStillRunning === []
                && $groupMembersStillRunning === []
            ) {
                return true;
            }

            if ($this->statusRunning($status)) {
                $this->validatedParent($parentPid, $parentPpid, $expectedUid);
            }

            $diagnostics = $this->drainTranscript($transcript, $diagnostics);

            if (($this->clock)() >= $deadline) {
                return false;
            }

            ($this->sleeper)(self::POLL_MICROSECONDS);
        } while (true);
    }

    /**
     * @param list<array{pid: int, ppid: int, uid: int, pgid: int}> $children
     * @param null|array{pgid: int, members: list<array{pid: int, ppid: int, uid: int, pgid: int}>} $ownedProcessGroup
     * @return list<int>
     */
    private function signalTargets(
        mixed $process,
        int $parentPid,
        int $parentPpid,
        int $expectedUid,
        array $children,
        ?array &$ownedProcessGroup,
    ): array {
        $status = $this->status($process);
        $currentChildren = $this->validatedChildren($parentPid, $expectedUid);

        if ($ownedProcessGroup !== null) {
            if ($this->liveOwnedProcessGroupMembers($ownedProcessGroup, $expectedUid) !== []) {
                return [-$ownedProcessGroup['pgid']];
            }

            if (! $this->statusRunning($status)) {
                return array_column($currentChildren, 'pid');
            }

            $this->validatedParent($parentPid, $parentPpid, $expectedUid);

            return [
                ...array_column($currentChildren, 'pid'),
                $parentPid,
            ];
        }

        $knownChildren = $this->validatedKnownChildren($children, $parentPid, $expectedUid);
        $verifiedChildren = $this->mergeChildren($knownChildren, $currentChildren);

        if (! $this->statusRunning($status)) {
            return array_column($verifiedChildren, 'pid');
        }

        $this->validatedParent($parentPid, $parentPpid, $expectedUid);

        if (count($verifiedChildren) === 1 && $verifiedChildren[0]['pid'] === $verifiedChildren[0]['pgid']) {
            return [-$verifiedChildren[0]['pgid']];
        }

        return [
            ...array_column($verifiedChildren, 'pid'),
            $parentPid,
        ];
    }

    /**
     * @param list<array{pid: int, ppid: int, uid: int, pgid: int}> $knownChildren
     * @return list<array{pid: int, ppid: int, uid: int, pgid: int}>
     */
    private function validatedKnownChildren(array $knownChildren, int $parentPid, int $expectedUid): array
    {
        $stillRunning = [];

        foreach ($knownChildren as $knownChild) {
            $details = ($this->processInspector)($knownChild['pid']);

            if ($details === null) {
                continue;
            }

            if (
                ! $this->validProcessDetails(
                    $details,
                    $knownChild['pid'],
                    $parentPid,
                    $expectedUid,
                )
                || ($details['pgid'] ?? null) !== $knownChild['pgid']
            ) {
                throw new LocalNodeSetupException('Local setup could not be completed.');
            }

            $stillRunning[] = $knownChild;
        }

        return $stillRunning;
    }

    /**
     * @param list<array{pid: int, ppid: int, uid: int, pgid: int}> $knownChildren
     * @param list<array{pid: int, ppid: int, uid: int, pgid: int}> $currentChildren
     * @return list<array{pid: int, ppid: int, uid: int, pgid: int}>
     */
    private function mergeChildren(array $knownChildren, array $currentChildren): array
    {
        $byPid = [];

        foreach ([...$knownChildren, ...$currentChildren] as $child) {
            $byPid[$child['pid']] = $child;
        }

        return array_values($byPid);
    }

    /** @return list<array{pid: int, ppid: int, uid: int, pgid: int}> */
    private function validatedChildren(int $parentPid, int $expectedUid): array
    {
        $rawChildren = ($this->childLookup)($parentPid);

        if (! is_array($rawChildren)) {
            throw new LocalNodeSetupException('Local setup could not be completed.');
        }

        $children = [];

        foreach ($rawChildren as $rawPid) {
            if (! is_string($rawPid) && ! is_int($rawPid)) {
                throw new LocalNodeSetupException('Local setup could not be completed.');
            }

            $value = (string) $rawPid;

            if (preg_match('/\A[1-9]\d*\z/D', $value) !== 1) {
                throw new LocalNodeSetupException('Local setup could not be completed.');
            }

            $pid = (int) $value;
            $details = ($this->processInspector)($pid);

            if ($details === null) {
                continue;
            }

            if (! $this->validProcessDetails($details, $pid, $parentPid, $expectedUid)) {
                throw new LocalNodeSetupException('Local setup could not be completed.');
            }

            $pgid = $details['pgid'] ?? null;

            if (! is_int($pgid)) {
                throw new LocalNodeSetupException('Local setup could not be completed.');
            }

            $children[] = [
                'pid' => $pid,
                'ppid' => $parentPid,
                'uid' => $expectedUid,
                'pgid' => $pgid,
            ];
        }

        return $children;
    }

    /**
     * @param list<array{pid: int, ppid: int, uid: int, pgid: int}> $children
     * @return null|array{pgid: int, members: list<array{pid: int, ppid: int, uid: int, pgid: int}>}
     */
    private function ownedProcessGroup(array $children, int $expectedUid): ?array
    {
        if (count($children) !== 1 || $children[0]['pid'] !== $children[0]['pgid']) {
            return null;
        }

        $pgid = $children[0]['pgid'];

        return [
            'pgid' => $pgid,
            'members' => $this->mergeChildren(
                $children,
                $this->validatedProcessGroupMembers($pgid, $expectedUid),
            ),
        ];
    }

    /**
     * @param array{pgid: int, members: list<array{pid: int, ppid: int, uid: int, pgid: int}>} $ownedProcessGroup
     * @return list<array{pid: int, ppid: int, uid: int, pgid: int}>
     */
    private function liveOwnedProcessGroupMembers(array &$ownedProcessGroup, int $expectedUid): array
    {
        $currentMembers = $this->validatedProcessGroupMembers($ownedProcessGroup['pgid'], $expectedUid);
        $ownedProcessGroup['members'] = $this->mergeChildren($ownedProcessGroup['members'], $currentMembers);

        return $this->validatedKnownProcessGroupMembers(
            $ownedProcessGroup['members'],
            $ownedProcessGroup['pgid'],
            $expectedUid,
        );
    }

    /** @return list<array{pid: int, ppid: int, uid: int, pgid: int}> */
    private function validatedProcessGroupMembers(int $pgid, int $expectedUid): array
    {
        $rawMembers = ($this->processGroupLookup)($pgid);

        if (! is_array($rawMembers)) {
            throw new LocalNodeSetupException('Local setup could not be completed.');
        }

        $members = [];

        foreach ($rawMembers as $rawPid) {
            if (! is_string($rawPid) && ! is_int($rawPid)) {
                throw new LocalNodeSetupException('Local setup could not be completed.');
            }

            $value = (string) $rawPid;

            if (preg_match('/\A[1-9]\d*\z/D', $value) !== 1) {
                throw new LocalNodeSetupException('Local setup could not be completed.');
            }

            $pid = (int) $value;
            $details = ($this->processInspector)($pid);

            if ($details === null) {
                continue;
            }

            if (! $this->validProcessGroupMember($details, $pid, $expectedUid, $pgid)) {
                throw new LocalNodeSetupException('Local setup could not be completed.');
            }

            $members[] = $details;
        }

        return $this->mergeChildren([], $members);
    }

    /**
     * @param list<array{pid: int, ppid: int, uid: int, pgid: int}> $knownMembers
     * @return list<array{pid: int, ppid: int, uid: int, pgid: int}>
     */
    private function validatedKnownProcessGroupMembers(
        array $knownMembers,
        int $pgid,
        int $expectedUid,
    ): array {
        $stillRunning = [];

        foreach ($knownMembers as $knownMember) {
            $details = ($this->processInspector)($knownMember['pid']);

            if ($details === null) {
                continue;
            }

            if (! $this->validProcessGroupMember($details, $knownMember['pid'], $expectedUid, $pgid)) {
                throw new LocalNodeSetupException('Local setup could not be completed.');
            }

            $stillRunning[] = $details;
        }

        return $this->mergeChildren([], $stillRunning);
    }

    private function validProcessGroupMember(mixed $details, int $pid, int $uid, int $pgid): bool
    {
        return (
            is_array($details)
            && ($details['pid'] ?? null) === $pid
            && is_int($details['ppid'] ?? null)
            && $details['ppid'] >= 0
            && ($details['uid'] ?? null) === $uid
            && ($details['pgid'] ?? null) === $pgid
        );
    }

    /** @param null|array{pgid: int, members: list<array{pid: int, ppid: int, uid: int, pgid: int}>} $ownedProcessGroup */
    private function assertOwnedProcessGroupGone(?array &$ownedProcessGroup, int $expectedUid): void
    {
        if (
            $ownedProcessGroup !== null
            && $this->liveOwnedProcessGroupMembers($ownedProcessGroup, $expectedUid) !== []
        ) {
            throw new LocalNodeSetupException('Local setup could not be completed.');
        }
    }

    /** @return array{pid: int, ppid: int, uid: int, pgid: int} */
    private function validatedParent(int $pid, int $ppid, int $uid): array
    {
        $details = ($this->processInspector)($pid);

        if (! is_array($details) || ! $this->validProcessDetails($details, $pid, $ppid, $uid)) {
            throw new LocalNodeSetupException('Local setup could not be completed.');
        }

        $pgid = $details['pgid'] ?? null;

        if (! is_int($pgid)) {
            throw new LocalNodeSetupException('Local setup could not be completed.');
        }

        return [
            'pid' => $pid,
            'ppid' => $ppid,
            'uid' => $uid,
            'pgid' => $pgid,
        ];
    }

    private function validProcessDetails(mixed $details, int $pid, int $ppid, int $uid): bool
    {
        return (
            is_array($details)
            && ($details['pid'] ?? null) === $pid
            && ($details['ppid'] ?? null) === $ppid
            && ($details['uid'] ?? null) === $uid
            && is_int($details['pgid'] ?? null)
            && $details['pgid'] > 0
        );
    }

    private function assertNoOwnedChildren(int $parentPid, int $expectedUid): void
    {
        if ($this->validatedChildren($parentPid, $expectedUid) !== []) {
            throw new LocalNodeSetupException('Local setup could not be completed.');
        }
    }

    /** @param list<array{pid: int, ppid: int, uid: int, pgid: int}> $knownChildren */
    private function assertKnownChildrenGone(array $knownChildren, int $parentPid, int $expectedUid): void
    {
        if ($this->validatedKnownChildren($knownChildren, $parentPid, $expectedUid) !== []) {
            throw new LocalNodeSetupException('Local setup could not be completed.');
        }
    }

    /** @param resource $transcript */
    private function drainTranscript(mixed $transcript, string $diagnostics): string
    {
        do {
            $chunk = stream_get_contents($transcript, 8192);

            if (! is_string($chunk) || $chunk === '') {
                break;
            }

            $diagnostics = $this->redactor->redactedTail([$diagnostics, $chunk]);
        } while (true);

        return $diagnostics;
    }

    /** @return array<string, mixed> */
    private function status(mixed $process): array
    {
        $status = ($this->processStatus)($process);

        if (! is_array($status)) {
            throw new LocalNodeSetupException('Local setup could not be completed.');
        }

        /** @var array<string, mixed> $status */
        return $status;
    }

    /** @param array<string, mixed> $status */
    private function statusPid(array $status): int
    {
        $pid = $status['pid'] ?? null;

        if (! is_int($pid) || $pid < 1) {
            throw new LocalNodeSetupException('Local setup could not be completed.');
        }

        return $pid;
    }

    /** @param array<string, mixed> $status */
    private function statusRunning(array $status): bool
    {
        return ($status['running'] ?? null) === true;
    }

    /** @param array<string, mixed> $status */
    private function reap(mixed $process, array $status): int
    {
        $statusExitCode = $status['exitcode'] ?? null;
        $closeExitCode = ($this->processCloser)($process);
        $exitCode = is_int($statusExitCode) && $statusExitCode >= 0
            ? $statusExitCode
            : $closeExitCode;

        if (! is_int($exitCode) || $exitCode < 0) {
            throw new LocalNodeSetupException('Local setup could not be completed.');
        }

        return $exitCode;
    }

    private function verifiedTemporaryDirectory(): string
    {
        $configured = ($this->tempDirectory)();

        if (! is_string($configured) || $configured === '') {
            throw new LocalNodeSetupException('Local setup could not be started.');
        }

        $details = $this->pathDetails($configured);
        $real = realpath($configured);
        $mode = is_array($details) ? $details['mode'] ?? null : null;

        if (
            ! is_array($details)
            || ! is_int($mode)
            || ($mode & 0o170_000) !== 0o040_000
            || ! is_string($real)
            || ! is_writable($real)
        ) {
            throw new LocalNodeSetupException('Local setup could not be started.');
        }

        return rtrim($real, '/');
    }

    private function createScript(string $directory, int $uid, string $script): string
    {
        $path = $this->candidatePath($directory, 'script');
        $stream = $this->exclusiveOpen($path);

        try {
            try {
                $opened = fstat($stream);

                if (! is_array($opened) || ! chmod($path, 0o600)) {
                    throw new LocalNodeSetupException('Local setup could not be started.');
                }

                $this->assertArtifact($path, 0o100_000, $directory, $uid, $opened);

                if (fwrite($stream, $script) !== strlen($script) || ! fflush($stream)) {
                    throw new LocalNodeSetupException('Local setup could not be started.');
                }
            } finally {
                fclose($stream);
            }

            $this->assertArtifact($path, 0o100_000, $directory, $uid);

            return $path;
        } catch (Throwable) {
            $this->removeArtifact($path, 0o100_000, $directory, $uid);

            throw new LocalNodeSetupException('Local setup could not be started.');
        }
    }

    private function createTranscriptFifo(string $directory, int $uid): string
    {
        $path = $this->candidatePath($directory, 'transcript');
        $created = false;

        try {
            $created = posix_mkfifo($path, 0o600);

            if (! $created || ! chmod($path, 0o600)) {
                throw new LocalNodeSetupException('Local setup could not be started.');
            }

            $this->assertArtifact($path, 0o010_000, $directory, $uid);

            return $path;
        } catch (Throwable) {
            if ($created) {
                $this->removeArtifact($path, 0o010_000, $directory, $uid);
            }

            throw new LocalNodeSetupException('Local setup could not be started.');
        }
    }

    /** @return resource */
    private function exclusiveOpen(string $path): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            $stream = fopen($path, 'x+b');
        } finally {
            restore_error_handler();
        }

        if (! is_resource($stream)) {
            throw new LocalNodeSetupException('Local setup could not be started.');
        }

        return $stream;
    }

    /** @return resource */
    private function openTranscript(string $path): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            $stream = fopen($path, 'r+b');
        } finally {
            restore_error_handler();
        }

        if (! is_resource($stream) || ! stream_set_blocking($stream, false)) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw new LocalNodeSetupException('Local setup could not be started.');
        }

        return $stream;
    }

    /** @return array{0: resource, 1: resource, 2: resource} */
    private function terminalDescriptors(ControllingTerminal $terminal): array
    {
        $descriptors = [];

        try {
            foreach ([0, 1, 2] as $descriptor) {
                $descriptors[$descriptor] = $terminal->open();
            }
        } catch (Throwable) {
            foreach ($descriptors as $stream) {
                fclose($stream);
            }

            throw new LocalNodeSetupException('Local setup could not be started.');
        }

        return $descriptors;
    }

    private function candidatePath(string $directory, string $kind): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $path = $directory.'/orbit-app-dev-'.$kind.'-'.bin2hex(random_bytes(16));

            if ($this->pathDetails($path) === false) {
                return $path;
            }
        }

        throw new LocalNodeSetupException('Local setup could not be started.');
    }

    /** @param array<string|int, mixed>|null $opened */
    private function assertArtifact(
        string $path,
        int $type,
        string $directory,
        int $uid,
        ?array $opened = null,
    ): void {
        $details = $this->pathDetails($path);
        $mode = is_array($details) ? $details['mode'] ?? null : null;

        if (
            ! is_array($details)
            || ! is_int($mode)
            || ($mode & 0o170_000) !== $type
            || ($mode & 0o777) !== 0o600
            || ($details['uid'] ?? null) !== $uid
            || realpath(dirname($path)) !== $directory
            || $opened !== null
            && (($opened['dev'] ?? null) !== ($details['dev'] ?? null)
            || ($opened['ino'] ?? null) !== ($details['ino'] ?? null))
        ) {
            throw new LocalNodeSetupException('Local setup could not be started.');
        }
    }

    private function removeArtifact(?string $path, int $type, string $directory, int $uid): void
    {
        if ($path === null) {
            return;
        }

        $details = $this->pathDetails($path);

        if ($details === false) {
            return;
        }

        $mode = $details['mode'] ?? null;

        if (
            ! is_int($mode)
            || ($mode & 0o170_000) !== $type
            || ($details['uid'] ?? null) !== $uid
            || realpath(dirname($path)) !== $directory
        ) {
            throw new LocalNodeSetupException('Local setup could not be completed.');
        }

        if (! unlink($path)) {
            throw new LocalNodeSetupException('Local setup could not be completed.');
        }
    }

    /** @return array<string|int, mixed>|false */
    private function pathDetails(string $path): array|false
    {
        set_error_handler(static fn (): bool => true);

        try {
            return lstat($path);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param list<string> $argv
     * @param array{0: resource, 1: resource, 2: resource} $descriptors
     */
    private function startNativeProcess(array $argv, array $descriptors): mixed
    {
        $pipes = [];

        return proc_open(
            $argv,
            $descriptors,
            $pipes,
            options: ['bypass_shell' => true],
        );
    }

    /** @return array<string, mixed>|false */
    private function nativeProcessStatus(mixed $process): array|false
    {
        return is_resource($process) ? proc_get_status($process) : false;
    }

    private function closeNativeProcess(mixed $process): int
    {
        return is_resource($process) ? proc_close($process) : -1;
    }

    /** @return list<string> */
    private function lookupChildren(int $parentPid): array
    {
        [$exitCode, $output] = $this->nativeOutput(['/usr/bin/pgrep', '-P', (string) $parentPid]);

        if ($exitCode === 1 && trim($output) === '') {
            return [];
        }

        if ($exitCode !== 0) {
            throw new LocalNodeSetupException('Local setup could not be completed.');
        }

        $children = preg_split('/\R/', trim($output), flags: PREG_SPLIT_NO_EMPTY);

        return is_array($children) ? array_values($children) : [];
    }

    /** @return list<string> */
    private function lookupProcessGroupMembers(int $pgid): array
    {
        [$exitCode, $output] = $this->nativeOutput(['/bin/ps', '-o', 'pid=', '-g', (string) $pgid]);

        if ($exitCode === 1 && trim($output) === '') {
            return [];
        }

        if ($exitCode !== 0) {
            throw new LocalNodeSetupException('Local setup could not be completed.');
        }

        $members = preg_split('/\s+/', trim($output), flags: PREG_SPLIT_NO_EMPTY);

        return is_array($members) ? array_values($members) : [];
    }

    /** @return array{pid: int, ppid: int, uid: int, pgid: int}|null */
    private function inspectProcess(int $pid): ?array
    {
        [$exitCode, $output] = $this->nativeOutput([
            '/bin/ps',
            '-o',
            'pid=',
            '-o',
            'ppid=',
            '-o',
            'uid=',
            '-o',
            'pgid=',
            '-p',
            (string) $pid,
        ]);

        if ($exitCode === 1 && trim($output) === '') {
            return null;
        }

        if ($exitCode !== 0) {
            throw new LocalNodeSetupException('Local setup could not be completed.');
        }

        $values = preg_split('/\s+/', trim($output));

        if (! is_array($values) || count($values) !== 4) {
            throw new LocalNodeSetupException('Local setup could not be completed.');
        }

        $numbers = [];

        foreach ($values as $value) {
            if (preg_match('/\A\d+\z/D', $value) !== 1) {
                throw new LocalNodeSetupException('Local setup could not be completed.');
            }

            $numbers[] = (int) $value;
        }

        return [
            'pid' => $numbers[0],
            'ppid' => $numbers[1],
            'uid' => $numbers[2],
            'pgid' => $numbers[3],
        ];
    }

    /** @param list<string> $argv
     *  @return array{int, string}
     */
    private function nativeOutput(array $argv): array
    {
        $pipes = [];
        $process = proc_open(
            $argv,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            options: ['bypass_shell' => true],
        );

        if (! is_resource($process)) {
            throw new LocalNodeSetupException('Local setup could not be completed.');
        }

        if (
            ! isset($pipes[0], $pipes[1], $pipes[2])
            || ! is_resource($pipes[0])
            || ! is_resource($pipes[1])
            || ! is_resource($pipes[2])
        ) {
            proc_close($process);

            throw new LocalNodeSetupException('Local setup could not be completed.');
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1], self::NATIVE_OUTPUT_BYTES + 1);
        stream_get_contents($pipes[2], 1024);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if (! is_string($output) || strlen($output) > self::NATIVE_OUTPUT_BYTES) {
            throw new LocalNodeSetupException('Local setup could not be completed.');
        }

        return [$exitCode, $output];
    }
}
