<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\MetricsCredentialRuntime;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use JsonException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final readonly class MetricsSshExecutor implements MetricsCredentialRuntime, MetricsRuntimeHost
{
    private const string ConfigurationDirectory = MetricsFootprint::ConfigurationDirectory;

    private const string OwnershipMarker = MetricsFootprint::OwnershipMarker;

    /** @var non-empty-list<string> */
    private const array ConfigurationDirectories = MetricsFootprint::ConfigurationDirectories;

    /** @var non-empty-list<string> */
    private const array ConfigurationPaths = MetricsFootprint::ConfigurationPaths;

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private int $healthAttempts = 30,
        private int $healthPollMicroseconds = 2_000_000,
    ) {}

    public function snapshotConfiguration(
        Node $node,
        MetricsConfigurationBundle $configuration,
    ): MetricsConfigurationSnapshot {
        if (! $this->pathExists($node, self::ConfigurationDirectory, directory: true)) {
            return new MetricsConfigurationSnapshot(
                directoryExisted: false,
                files: array_fill_keys(self::ConfigurationPaths, null),
            );
        }

        $marker = $this->run(
            $node,
            new RemoteCommand(['sudo', 'cat', '--', self::OwnershipMarker]),
            'metrics.configuration_ownership_drift',
            'Metrics configuration ownership cannot be proved.',
        );

        if ($marker->stdout !== MetricsFootprint::OwnershipMarkerContents) {
            throw new ResourceOperationException(
                'metrics.configuration_ownership_drift',
                'Metrics configuration ownership cannot be proved.',
                409,
            );
        }

        $files = [];

        foreach ($configuration->files as $file) {
            if (! $this->pathExists($node, $file->path)) {
                $files[$file->path] = null;

                continue;
            }

            // The ownership marker above proves every file in the directory is
            // Orbit-generated, including the Grafana admin-password file, which
            // Grafana reads only at first start and which therefore lags behind
            // a credential reset until the next convergence re-renders it.
            $contents = $this->run(
                $node,
                new RemoteCommand(['sudo', 'cat', '--', $file->path]),
                'metrics.configuration_snapshot_failed',
                'Metrics configuration could not be snapshotted.',
            );
            $files[$file->path] = new MetricsGeneratedFile(
                path: $file->path,
                contents: new ProtectedMetricsSecret($contents->stdout),
                mode: $file->mode,
                owner: $file->owner,
                group: $file->group,
            );
        }

        return new MetricsConfigurationSnapshot(directoryExisted: true, files: $files);
    }

    public function publishConfiguration(Node $node, MetricsConfigurationBundle $configuration): void
    {
        $prometheus = $this->file($configuration, '/etc/orbit/metrics/prometheus.yml');
        $this->run(
            $node,
            new RemoteCommand(
                [
                    'sudo',
                    'docker',
                    'container',
                    'run',
                    '--rm',
                    '--interactive',
                    '--entrypoint',
                    '/bin/promtool',
                    MetricsRuntimeSpec::PrometheusImage,
                    'check',
                    'config',
                    '/dev/stdin',
                ],
                protectedInput: $prometheus->contents->input(),
            ),
            'metrics.prometheus_configuration_invalid',
            'The generated Prometheus configuration is invalid.',
        );

        foreach (self::ConfigurationDirectories as $directory) {
            $this->run(
                $node,
                new RemoteCommand([
                    'sudo',
                    'install',
                    '-d',
                    '-o',
                    'root',
                    '-g',
                    'root',
                    '-m',
                    '0755',
                    '--',
                    $directory,
                ]),
                'metrics.configuration_publish_failed',
                'Metrics configuration could not be published.',
            );
        }

        $this->publishFile($node, new MetricsGeneratedFile(
            path: self::OwnershipMarker,
            contents: new ProtectedMetricsSecret(MetricsFootprint::OwnershipMarkerContents),
            mode: 0o640,
        ));

        foreach ($configuration->files as $file) {
            $this->publishFile($node, $file);
        }
    }

    public function restoreConfiguration(Node $node, MetricsConfigurationSnapshot $snapshot): void
    {
        foreach (self::ConfigurationPaths as $path) {
            $this->run(
                $node,
                new RemoteCommand(['sudo', 'rm', '-f', '--', $path.MetricsFootprint::CandidateSuffix]),
                'metrics.configuration_rollback_failed',
                'Metrics configuration recovery did not complete.',
            );
        }

        foreach ($snapshot->files as $path => $file) {
            if (! $file instanceof MetricsGeneratedFile) {
                $this->run(
                    $node,
                    new RemoteCommand(['sudo', 'rm', '-f', '--', $path]),
                    'metrics.configuration_rollback_failed',
                    'Metrics configuration recovery did not complete.',
                );

                continue;
            }

            $this->publishFile($node, $file);
        }

        if ($snapshot->directoryExisted) {
            return;
        }

        $this->run(
            $node,
            new RemoteCommand(['sudo', 'rm', '-f', '--', self::OwnershipMarker]),
            'metrics.configuration_rollback_failed',
            'Metrics configuration recovery did not complete.',
        );

        foreach (array_reverse(self::ConfigurationDirectories) as $directory) {
            if (! $this->pathExists($node, $directory, directory: true)) {
                continue;
            }

            $this->run(
                $node,
                new RemoteCommand(['sudo', 'rmdir', '--ignore-fail-on-non-empty', '--', $directory]),
                'metrics.configuration_rollback_failed',
                'Metrics configuration recovery did not complete.',
            );
        }
    }

    public function convergeContainers(Node $node, array $specs): void
    {
        /** @var list<MetricsContainerReplacementProgress> $services */
        $services = [];

        foreach ($specs as $spec) {
            $state = $this->inspectContainer($node, $spec->name);
            $this->assertContainerOwnership($state, $spec);

            $backupState = $this->inspectContainer($node, $this->backupName($spec));
            $this->assertContainerOwnership($backupState, $spec);

            $volumeState = $this->inspectVolume($node, $spec->volume);
            $this->assertVolumeOwnership($volumeState, $spec);

            $services[] = new MetricsContainerReplacementProgress(
                spec: $spec,
                state: $state,
                backupState: $backupState,
                volumeState: $volumeState,
            );
        }

        $createdVolumes = [];
        /** @var list<MetricsContainerReplacementProgress> $replacements */
        $replacements = [];

        try {
            foreach ($services as $progress) {
                $spec = $progress->spec;

                if ($progress->state === null && $progress->backupState !== null) {
                    $this->renameContainer($node, $this->backupName($spec), $spec->name);
                    $this->startContainer($node, $spec->name);
                    $progress->state = $progress->backupState;
                    $progress->backupState = null;
                }

                if ($progress->state !== null && $progress->backupState !== null) {
                    $this->removeContainer($node, $this->backupName($spec));
                    $progress->backupState = null;
                }

                if ($progress->volumeState === null) {
                    $this->createVolume($node, $spec);
                    $createdVolumes[] = $spec;
                }

                if (
                    $progress->state !== null
                    && $this->containerMatches($progress->state, $spec)
                    && $this->healthy($node, $spec->name)
                ) {
                    continue;
                }

                $progress->hadPrevious = $progress->state !== null;
                $replacements[] = $progress;

                if ($progress->hadPrevious) {
                    $this->stopReplacement($node, $progress);
                    $this->renameReplacementBackup($node, $progress);
                }

                $this->startReplacement($node, $progress);

                if (! $this->awaitHealthy($node, $spec->name)) {
                    throw new RuntimeException("Metrics service [{$spec->service->value}] is unhealthy.");
                }
            }
        } catch (Throwable $exception) {
            try {
                foreach (array_reverse($replacements) as $progress) {
                    if ($progress->replacementStarted) {
                        $this->removeReplacementContainer($node, $progress->spec);
                    }

                    if ($progress->renamed) {
                        $this->renameContainer(
                            $node,
                            $this->backupName($progress->spec),
                            $progress->spec->name,
                        );
                        $this->startContainer($node, $progress->spec->name);

                        continue;
                    }

                    if ($progress->stopped) {
                        $this->startContainer($node, $progress->spec->name);
                    }
                }

                foreach (array_reverse($createdVolumes) as $spec) {
                    $this->run(
                        $node,
                        new RemoteCommand(['sudo', 'docker', 'volume', 'rm', '--', $spec->volume]),
                        'metrics.volume_rollback_failed',
                        'A created Metrics volume could not be removed during recovery.',
                    );
                }
            } catch (Throwable $rollback) {
                throw new ResourceOperationException(
                    'metrics.container_rollback_failed',
                    'Metrics container convergence failed and runtime recovery did not complete.',
                    502,
                    $rollback,
                );
            }

            throw new ResourceOperationException(
                'metrics.container_convergence_failed',
                'Metrics container convergence did not complete.',
                502,
                $exception,
            );
        }

        foreach ($replacements as $progress) {
            if ($progress->hadPrevious) {
                $this->removeCommittedBackup($node, $progress);
            }
        }
    }

    public function removeContainers(Node $node, array $specs): void
    {
        $states = [];
        $backupStates = [];

        foreach ($specs as $spec) {
            $states[$spec->service->value] = $this->inspectContainer($node, $spec->name);
            $this->assertContainerOwnership($states[$spec->service->value], $spec);
            $backupStates[$spec->service->value] = $this->inspectContainer($node, $this->backupName($spec));
            $this->assertContainerOwnership($backupStates[$spec->service->value], $spec);
        }

        foreach ($specs as $spec) {
            if ($states[$spec->service->value] !== null) {
                $this->removeContainer($node, $spec->name);
            }

            if ($backupStates[$spec->service->value] !== null) {
                $this->removeContainer($node, $this->backupName($spec));
            }
        }
    }

    public function removeConfiguration(Node $node): void
    {
        if (! $this->pathExists($node, self::ConfigurationDirectory, directory: true)) {
            return;
        }

        $marker = $this->run(
            $node,
            new RemoteCommand(['sudo', 'cat', '--', self::OwnershipMarker]),
            'metrics.configuration_ownership_drift',
            'Metrics configuration ownership cannot be proved.',
        );

        if ($marker->stdout !== MetricsFootprint::OwnershipMarkerContents) {
            throw new ResourceOperationException(
                'metrics.configuration_ownership_drift',
                'Metrics configuration ownership cannot be proved.',
                409,
            );
        }

        foreach ([...self::ConfigurationPaths, self::OwnershipMarker] as $path) {
            $this->run(
                $node,
                new RemoteCommand(['sudo', 'rm', '-f', '--', $path]),
                'metrics.configuration_remove_failed',
                'Metrics generated configuration could not be removed.',
            );
        }

        foreach (array_reverse(self::ConfigurationDirectories) as $directory) {
            $this->raw($node, new RemoteCommand(['sudo', 'rmdir', '--ignore-fail-on-non-empty', '--', $directory]));
        }
    }

    public function purgeVolumes(Node $node, array $specs): void
    {
        $states = [];

        foreach ($specs as $spec) {
            $states[$spec->service->value] = $this->inspectVolume($node, $spec->volume);
            $this->assertVolumeOwnership($states[$spec->service->value], $spec);
        }

        foreach ($specs as $spec) {
            if ($states[$spec->service->value] !== null) {
                $this->run(
                    $node,
                    new RemoteCommand(['sudo', 'docker', 'volume', 'rm', '--', $spec->volume]),
                    'metrics.volume_purge_failed',
                    'A proven Metrics volume could not be removed.',
                );
            }
        }
    }

    public function health(Node $node, MetricsService $service): bool
    {
        $name = match ($service) {
            MetricsService::Prometheus => 'orbit-metrics-prometheus',
            MetricsService::Grafana => 'orbit-metrics-grafana',
        };

        try {
            $state = $this->inspectContainer($node, $name);

            if (
                $state === null
                || ($state[MetricsFootprint::ManagedLabel] ?? null) !== MetricsFootprint::ManagedValue
            ) {
                return false;
            }

            return $this->healthy($node, $name);
        } catch (Throwable) {
            return false;
        }
    }

    public function apply(
        Node $node,
        #[SensitiveParameter]
        string $activePassword,
        #[SensitiveParameter]
        string $pendingPassword,
    ): void {
        $payload = $this->json(['password' => $pendingPassword]);
        $configuration = $this->curlConfiguration(
            node: $node,
            password: $activePassword,
            method: 'PUT',
            path: '/api/admin/users/1/password',
            payload: $payload,
        );
        $this->run(
            $node,
            new RemoteCommand(
                ['curl', '--config', '-'],
                protectedInput: ProtectedInput::fromString($configuration),
            ),
            'metrics.credentials_reset_failed',
            'Grafana credential reset did not complete.',
        );
    }

    /**
     * Grafana serves `/api/health` without authentication, so a probe there
     * proves the service is up and nothing about the credential. `/api/user`
     * answers only for the signed-in administrator and returns 401 otherwise,
     * which `--fail` turns into a non-zero exit.
     */
    public function verify(Node $node, #[SensitiveParameter] string $password): bool
    {
        $configuration = $this->curlConfiguration(
            node: $node,
            password: $password,
            method: 'GET',
            path: '/api/user',
        );

        return $this->raw(
            $node,
            new RemoteCommand(
                ['curl', '--config', '-'],
                protectedInput: ProtectedInput::fromString($configuration),
            ),
        )->succeeded();
    }

    /**
     * Writes one generated file through a candidate beside its target.
     *
     * `install` reads `/dev/stdin` and refuses an existing destination on the
     * uutils coreutils that Ubuntu ships, so every write lands on a fresh
     * candidate path and is moved into place. The move is atomic, which the
     * running containers need anyway.
     */
    private function publishFile(Node $node, MetricsGeneratedFile $file): void
    {
        $candidate = $file->path.MetricsFootprint::CandidateSuffix;
        $this->run(
            $node,
            new RemoteCommand(['sudo', 'rm', '-f', '--', $candidate]),
            'metrics.configuration_publish_failed',
            'Metrics configuration could not be staged.',
        );
        $this->run(
            $node,
            new RemoteCommand(
                ['sudo', 'install', '-m', sprintf('%04o', $file->mode), '/dev/stdin', $candidate],
                protectedInput: $file->contents->input(),
            ),
            'metrics.configuration_publish_failed',
            'Metrics configuration could not be staged.',
        );
        // Numeric container identities such as the Grafana UID have no passwd
        // entry on the host; chown accepts them where install rejects them.
        $this->run(
            $node,
            new RemoteCommand(['sudo', 'chown', '--', "{$file->owner}:{$file->group}", $candidate]),
            'metrics.configuration_publish_failed',
            'Metrics configuration ownership could not be staged.',
        );
        $this->run(
            $node,
            new RemoteCommand(['sudo', 'mv', '-fT', '--', $candidate, $file->path]),
            'metrics.configuration_publish_failed',
            'Metrics configuration could not be published.',
        );
    }

    /** @return array<string, string>|null */
    private function inspectContainer(Node $node, string $name): ?array
    {
        $result = $this->raw(
            $node,
            new RemoteCommand([
                'sudo',
                'docker',
                'container',
                'inspect',
                '--format={{json .Config.Labels}}',
                '--',
                $name,
            ]),
        );

        if ($result->succeeded()) {
            return $this->labels($result->stdout, 'container');
        }

        $absence = $this->raw(
            $node,
            new RemoteCommand([
                'sudo',
                'docker',
                'container',
                'ls',
                '--all',
                '--filter',
                "name=^/{$name}$",
                '--format={{.Names}}',
            ]),
        );

        if ($absence->succeeded() && trim($absence->stdout) === '') {
            return null;
        }

        throw new ResourceOperationException(
            'metrics.container_inspection_failed',
            'Metrics container state could not be inspected.',
            502,
        );
    }

    /** @return array<string, string>|null */
    private function inspectVolume(Node $node, string $name): ?array
    {
        $result = $this->raw(
            $node,
            new RemoteCommand(['sudo', 'docker', 'volume', 'inspect', '--format={{json .Labels}}', '--', $name]),
        );

        if ($result->succeeded()) {
            return $this->labels($result->stdout, 'volume');
        }

        $absence = $this->raw(
            $node,
            new RemoteCommand(['sudo', 'docker', 'volume', 'ls', '--filter', "name=^{$name}$", '--format={{.Name}}']),
        );

        if ($absence->succeeded() && trim($absence->stdout) === '') {
            return null;
        }

        throw new ResourceOperationException(
            'metrics.volume_inspection_failed',
            'Metrics volume state could not be inspected.',
            502,
        );
    }

    /** @param array<string, string>|null $labels */
    private function assertContainerOwnership(?array $labels, MetricsContainerSpec $spec): void
    {
        if ($labels === null) {
            return;
        }

        if (
            ($labels[MetricsFootprint::ManagedLabel] ?? null) !== MetricsFootprint::ManagedValue
            || ($labels['com.orbit.metrics.service'] ?? null) !== $spec->service->value
        ) {
            throw new ResourceOperationException(
                'metrics.container_ownership_drift',
                "Refusing to adopt a foreign Metrics container [{$spec->name}].",
                409,
            );
        }
    }

    /** @param array<string, string>|null $labels */
    private function assertVolumeOwnership(?array $labels, MetricsContainerSpec $spec): void
    {
        if ($labels === null) {
            return;
        }

        if (
            ($labels[MetricsFootprint::ManagedLabel] ?? null) !== MetricsFootprint::ManagedValue
            || ($labels['com.orbit.metrics.volume'] ?? null) !== $spec->service->value
        ) {
            throw new ResourceOperationException(
                'metrics.volume_ownership_drift',
                "Refusing to adopt a foreign Metrics volume [{$spec->volume}].",
                409,
            );
        }
    }

    /** @param array<string, string> $labels */
    private function containerMatches(array $labels, MetricsContainerSpec $spec): bool
    {
        return ($labels['com.orbit.metrics.spec-hash'] ?? null) === $spec->specHash;
    }

    private function createVolume(Node $node, MetricsContainerSpec $spec): void
    {
        $arguments = ['sudo', 'docker', 'volume', 'create'];

        foreach ($spec->volumeLabels as $key => $value) {
            $arguments[] = '--label';
            $arguments[] = "{$key}={$value}";
        }

        $arguments[] = '--';
        $arguments[] = $spec->volume;
        $this->run(
            $node,
            new RemoteCommand($arguments),
            'metrics.volume_create_failed',
            'A Metrics volume could not be created.',
        );
    }

    private function runContainer(Node $node, MetricsContainerSpec $spec): void
    {
        $arguments = [
            'sudo',
            'docker',
            'container',
            'run',
            '--detach',
            '--name',
            $spec->name,
            '--restart',
            'unless-stopped',
            '--network',
            'host',
            '--log-driver',
            $spec->logDriver,
        ];

        foreach ($spec->logOptions as $key => $value) {
            $arguments[] = '--log-opt';
            $arguments[] = "{$key}={$value}";
        }

        foreach ($spec->labels as $key => $value) {
            $arguments[] = '--label';
            $arguments[] = "{$key}={$value}";
        }

        $dataTarget = $spec->service === MetricsService::Prometheus ? '/prometheus' : '/var/lib/grafana';
        $arguments[] = '--mount';
        $arguments[] = "type=volume,source={$spec->volume},target={$dataTarget}";

        foreach ($spec->mounts as $mount) {
            $arguments[] = '--volume';
            $arguments[] = $mount;
        }

        foreach ($spec->environment as $key => $value) {
            $arguments[] = '--env';
            $arguments[] = "{$key}={$value}";
        }

        $arguments[] = '--health-cmd';
        $arguments[] = implode(' ', array_slice($spec->healthCommand, 1));
        $arguments[] = '--health-start-period';
        $arguments[] = "{$spec->healthStartPeriodSeconds}s";
        $arguments[] = '--health-interval';
        $arguments[] = "{$spec->healthIntervalSeconds}s";
        $arguments[] = '--health-retries';
        $arguments[] = (string) $spec->healthRetries;
        $arguments[] = '--';
        $arguments[] = $spec->image;
        array_push($arguments, ...$spec->command);

        $this->run(
            $node,
            new RemoteCommand($arguments),
            'metrics.container_start_failed',
            "Metrics service [{$spec->service->value}] could not start.",
        );
    }

    private function stopReplacement(Node $node, MetricsContainerReplacementProgress $progress): void
    {
        try {
            $this->stopContainer($node, $progress->spec->name);
            $progress->stopped = true;
        } catch (Throwable $exception) {
            if ($this->containerRunning($node, $progress->spec->name, $progress->spec) === false) {
                $progress->stopped = true;

                return;
            }

            throw $exception;
        }
    }

    private function renameReplacementBackup(Node $node, MetricsContainerReplacementProgress $progress): void
    {
        try {
            $this->renameContainer($node, $progress->spec->name, $this->backupName($progress->spec));
            $progress->renamed = true;
        } catch (Throwable $exception) {
            $current = $this->inspectContainer($node, $progress->spec->name);
            $backup = $this->inspectContainer($node, $this->backupName($progress->spec));
            $this->assertContainerOwnership($current, $progress->spec);
            $this->assertContainerOwnership($backup, $progress->spec);

            if ($current === null && $backup !== null) {
                $progress->renamed = true;

                return;
            }

            throw $exception;
        }
    }

    private function startReplacement(Node $node, MetricsContainerReplacementProgress $progress): void
    {
        try {
            $this->runContainer($node, $progress->spec);
            $progress->replacementStarted = true;
        } catch (Throwable $exception) {
            $current = $this->inspectContainer($node, $progress->spec->name);
            $this->assertContainerOwnership($current, $progress->spec);

            if ($current !== null && $this->containerMatches($current, $progress->spec)) {
                $progress->replacementStarted = true;

                return;
            }

            throw $exception;
        }
    }

    private function removeCommittedBackup(Node $node, MetricsContainerReplacementProgress $progress): void
    {
        try {
            $this->removeContainer($node, $this->backupName($progress->spec));
            $progress->backupDeleted = true;

            return;
        } catch (Throwable $exception) {
            try {
                $backup = $this->inspectContainer($node, $this->backupName($progress->spec));
                $this->assertContainerOwnership($backup, $progress->spec);
            } catch (Throwable $inspection) {
                throw new ResourceOperationException(
                    'metrics.container_cleanup_failed',
                    'Metrics containers were committed, but obsolete recovery containers could not be inspected.',
                    502,
                    $inspection,
                );
            }

            if ($backup === null) {
                $progress->backupDeleted = true;

                return;
            }

            throw new ResourceOperationException(
                'metrics.container_cleanup_failed',
                'Metrics containers were committed, but obsolete recovery containers could not be removed.',
                502,
                $exception,
            );
        }
    }

    private function stopContainer(Node $node, string $name): void
    {
        $this->run(
            $node,
            new RemoteCommand(['sudo', 'docker', 'container', 'stop', '--time', '30', '--', $name]),
            'metrics.container_stop_failed',
            'A Metrics container could not be stopped.',
        );
    }

    private function containerRunning(Node $node, string $name, MetricsContainerSpec $spec): ?bool
    {
        $state = $this->inspectContainer($node, $name);
        $this->assertContainerOwnership($state, $spec);

        if ($state === null) {
            return null;
        }

        $result = $this->raw(
            $node,
            new RemoteCommand([
                'sudo',
                'docker',
                'container',
                'inspect',
                '--format={{.State.Running}}',
                '--',
                $name,
            ]),
        );

        if ($result->succeeded() && in_array(trim($result->stdout), ['true', 'false'], true)) {
            return trim($result->stdout) === 'true';
        }

        throw new ResourceOperationException(
            'metrics.container_inspection_failed',
            'Metrics container state could not be inspected.',
            502,
        );
    }

    private function startContainer(Node $node, string $name): void
    {
        $this->run(
            $node,
            new RemoteCommand(['sudo', 'docker', 'container', 'start', '--', $name]),
            'metrics.container_start_failed',
            'A Metrics container could not be started.',
        );
    }

    private function renameContainer(Node $node, string $from, string $to): void
    {
        $this->run(
            $node,
            new RemoteCommand(['sudo', 'docker', 'container', 'rename', $from, $to]),
            'metrics.container_rename_failed',
            'A Metrics container recovery name could not be published.',
        );
    }

    private function removeContainer(Node $node, string $name): void
    {
        $this->run(
            $node,
            new RemoteCommand(['sudo', 'docker', 'container', 'rm', '--force', '--', $name]),
            'metrics.container_remove_failed',
            'A proven Metrics container could not be removed.',
        );
    }

    private function removeReplacementContainer(Node $node, MetricsContainerSpec $spec): void
    {
        $state = $this->inspectContainer($node, $spec->name);
        $this->assertContainerOwnership($state, $spec);

        if ($state !== null) {
            $this->removeContainer($node, $spec->name);
        }
    }

    /**
     * A freshly started container reports `starting` until its first health
     * probe succeeds, so a single inspection right after `run` never passes.
     */
    private function awaitHealthy(Node $node, string $name): bool
    {
        for ($attempt = 1; $attempt <= $this->healthAttempts; $attempt++) {
            $status = $this->healthStatus($node, $name);

            if ($status === 'healthy') {
                return true;
            }

            if ($status !== 'starting') {
                return false;
            }

            if ($attempt < $this->healthAttempts && $this->healthPollMicroseconds > 0) {
                usleep($this->healthPollMicroseconds);
            }
        }

        return false;
    }

    /** Returns the health status while the container runs; anything else ends the wait. */
    private function healthStatus(Node $node, string $name): ?string
    {
        $result = $this->raw(
            $node,
            new RemoteCommand([
                'sudo',
                'docker',
                'container',
                'inspect',
                '--format={{.State.Status}} {{.State.Health.Status}}',
                '--',
                $name,
            ]),
        );

        if (! $result->succeeded()) {
            return null;
        }

        [$state, $health] = array_pad(explode(' ', trim($result->stdout), 2), 2, null);

        return $state === 'running' ? $health : null;
    }

    private function healthy(Node $node, string $name): bool
    {
        $result = $this->raw(
            $node,
            new RemoteCommand([
                'sudo',
                'docker',
                'container',
                'inspect',
                '--format={{.State.Health.Status}}',
                '--',
                $name,
            ]),
        );

        return $result->succeeded() && trim($result->stdout) === 'healthy';
    }

    private function pathExists(Node $node, string $path, bool $directory = false): bool
    {
        $test = $directory ? '-d' : '-e';
        $result = $this->raw($node, new RemoteCommand(['sudo', 'test', $test, $path]));

        if ($result->exitCode === 0) {
            return true;
        }

        if ($result->exitCode === 1) {
            return false;
        }

        throw new ResourceOperationException(
            'metrics.configuration_probe_failed',
            'Metrics configuration state could not be inspected.',
            502,
        );
    }

    private function backupName(MetricsContainerSpec $spec): string
    {
        return $spec->name.'-orbit-rollback';
    }

    private function connection(Node $node): SshConnection
    {
        $address = $node->wireguard_ip;

        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ResourceOperationException(
                'metrics.wireguard_ip_missing',
                "Node [{$node->name}] has no valid WireGuard address.",
                409,
            );
        }

        return new SshConnection(
            host: $address,
            user: $node->user,
            port: 22,
            identityFile: $this->keys->privateKeyPath(),
            knownHostsFile: $this->knownHosts->path(),
        );
    }

    private function raw(Node $node, RemoteCommand $command): CommandResult
    {
        return $this->ssh->execute($this->connection($node), $command);
    }

    private function run(
        Node $node,
        RemoteCommand $command,
        string $errorCode,
        string $message,
    ): CommandResult {
        $result = $this->raw($node, $command);

        if (! $result->succeeded()) {
            throw new ResourceOperationException($errorCode, $message, 502);
        }

        return $result;
    }

    /** @return array<string, string> */
    private function labels(string $json, string $resource): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode(trim($json), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ResourceOperationException(
                "metrics.{$resource}_inspection_invalid",
                "Metrics {$resource} ownership labels are invalid.",
                502,
            );
        }

        if (! is_array($decoded)) {
            throw new ResourceOperationException(
                "metrics.{$resource}_inspection_invalid",
                "Metrics {$resource} ownership labels are invalid.",
                502,
            );
        }

        $labels = [];

        /** @var mixed $value */
        foreach ($decoded as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new ResourceOperationException(
                    "metrics.{$resource}_inspection_invalid",
                    "Metrics {$resource} ownership labels are invalid.",
                    502,
                );
            }

            $labels[$key] = $value;
        }

        return $labels;
    }

    private function file(MetricsConfigurationBundle $bundle, string $path): MetricsGeneratedFile
    {
        foreach ($bundle->files as $file) {
            if ($file->path === $path) {
                return $file;
            }
        }

        throw new RuntimeException("Required Metrics configuration [{$path}] is missing.");
    }

    /** @param array<string, string> $value */
    private function json(#[SensitiveParameter] array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Grafana credential input could not be encoded.', previous: $exception);
        }
    }

    private function curlConfiguration(
        Node $node,
        #[SensitiveParameter]
        string $password,
        string $method,
        string $path,
        #[SensitiveParameter]
        ?string $payload = null,
    ): string {
        $address = $this->connection($node)->host;
        $lines = [
            'silent',
            'show-error',
            'fail',
            'output = "/dev/null"',
            'user = '.$this->curlQuote("admin:{$password}"),
            'request = '.$this->curlQuote($method),
            'url = '.$this->curlQuote('http://'.$address.':'.MetricsFootprint::PublicationPort.$path),
        ];

        if (is_string($payload)) {
            $lines[] = 'header = "Content-Type: application/json"';
            $lines[] = 'data = '.$this->curlQuote($payload);
        }

        return implode("\n", $lines)."\n";
    }

    private function curlQuote(#[SensitiveParameter] string $value): string
    {
        return '"'.str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value).'"';
    }
}
