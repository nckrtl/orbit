<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\OperationJournal;
use App\E2E\State\SecretRedactor;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\IncusNetwork;
use App\E2E\Value\IncusSnapshot;
use App\E2E\Value\OperationId;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity Exact Incus operations keep validation at the process boundary.
 * @mago-expect lint:kan-defect The boundary fails closed for each identity and ownership check.
 * @mago-expect lint:too-many-methods The approved adapter contract requires this exact method surface.
 */
final readonly class IncusHost
{
    /**
     * @param array<string, string> $ownershipMetadata
     * @mago-expect lint:excessive-parameter-list Explicit dependencies keep this infrastructure boundary configurable and testable.
     */
    public function __construct(
        private string $remote = 'local',
        private string $project = 'default',
        private string $pool = 'default',
        private array $ownershipMetadata = ['user.orbit.e2e.owner' => 'orbit-e2e'],
        private SecretRedactor $redactor = new SecretRedactor,
        private ?OperationJournal $journal = null,
        private ?OperationId $operationId = null,
    ) {
        $this->validateName($remote, 'remote');
        $this->validateName($project, 'project');
        $this->validateName($pool, 'storage pool');

        if ($ownershipMetadata === []) {
            throw new RuntimeException('Incus ownership metadata cannot be empty.');
        }

        foreach ($ownershipMetadata as $key => $value) {
            $this->validateMetadata($key, $value);
        }

        if (($journal === null) !== ($operationId === null)) {
            throw new RuntimeException('Incus journal and operation identity must be provided together.');
        }
    }

    public function instance(string $name): ?IncusInstance
    {
        $this->validateName($name, 'instance');
        $resources = $this->readJson(['list', $this->target($name), '--format=json']);

        foreach ($resources as $resource) {
            if (is_array($resource) && ($resource['name'] ?? null) === $name) {
                if (($resource['type'] ?? null) !== 'virtual-machine') {
                    throw new RuntimeException("Incus instance {$name} is not a virtual machine.");
                }

                $pool = $resource['devices']['root']['pool'] ?? $resource['expanded_devices']['root']['pool'] ?? null;
                if (! is_string($pool) || $pool === '') {
                    throw new RuntimeException("Incus instance {$name} has no storage pool identity.");
                }

                if ($pool !== $this->pool) {
                    throw new RuntimeException("Incus instance {$name} storage pool identity does not match.");
                }

                $status = $resource['status'] ?? null;
                $statusCode = $resource['status_code'] ?? null;
                if (! is_string($status) || ! is_int($statusCode)) {
                    throw new RuntimeException("Incus instance {$name} has no valid power status.");
                }
                $network =
                    $resource['devices']['eth0']['network'] ?? $resource['expanded_devices']['eth0']['network'] ?? null;
                if ($network !== null && ! is_string($network)) {
                    throw new RuntimeException("Incus instance {$name} has an invalid network identity.");
                }

                return new IncusInstance(
                    $this->remote,
                    $this->project,
                    $name,
                    $pool,
                    $this->metadata($resource),
                    strtoupper($status),
                    $statusCode,
                    $network,
                );
            }
        }

        return null;
    }

    public function network(string $name): ?IncusNetwork
    {
        $this->validateName($name, 'network');
        $resources = $this->readJson(['network', 'list', "{$this->remote}:", '--format=json']);

        foreach ($resources as $resource) {
            if (is_array($resource) && ($resource['name'] ?? null) === $name) {
                return new IncusNetwork($this->remote, $this->project, $name, $this->metadata($resource));
            }
        }

        return null;
    }

    public function imageFingerprint(string $alias): string
    {
        $this->validateImage($alias);
        [$remote, $selector] = $this->imageSelector($alias);
        $images = $this->readJson(['image', 'list', $remote, $selector, '--format=json']);
        $matches = array_values(array_filter($images, function (mixed $image) use ($selector): bool {
            if (! is_array($image) || ($image['type'] ?? null) !== 'virtual-machine') {
                return false;
            }

            if (($image['fingerprint'] ?? null) === $selector) {
                return true;
            }

            $aliases = $image['aliases'] ?? null;
            if (! is_array($aliases)) {
                return false;
            }

            return array_any(
                $aliases,
                fn ($imageAlias) => is_array($imageAlias) && ($imageAlias['name'] ?? null) === $selector,
            );
        }));
        if (count($matches) !== 1) {
            throw new RuntimeException('Incus image selector did not identify exactly one virtual-machine image.');
        }
        $fingerprint = $matches[0]['fingerprint'] ?? null;

        if (! is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1) {
            throw new RuntimeException('Incus returned an invalid image fingerprint.');
        }

        return $fingerprint;
    }

    /** @return array{remote:string,project:string,pool:string} */
    public function scope(): array
    {
        return ['remote' => $this->remote, 'project' => $this->project, 'pool' => $this->pool];
    }

    /** @param array<string, string> $configuration */
    public function createNetwork(string $name, array $configuration): IncusNetwork
    {
        $this->validateName($name, 'network');
        if (strlen($name) > 15) {
            throw new RuntimeException('Incus network names must be 15 ASCII characters or fewer.');
        }
        $this->validateStringMap($configuration, 'network configuration');
        $configuration = [...$configuration, ...$this->ownershipMetadata];
        $arguments = ['network', 'create', "{$this->remote}:{$name}"];
        foreach ($configuration as $key => $value) {
            $this->validateConfiguration($key, $value);
            $arguments[] = "{$key}={$value}";
        }

        $this->run($arguments);

        return new IncusNetwork($this->remote, $this->project, $name, $this->e2eMetadata($configuration));
    }

    public function initVm(string $image, string $name, string $network): IncusInstance
    {
        $this->validateImage($image);
        $this->validateName($name, 'instance');
        $this->validateName($network, 'network');
        [$imageRemote, $imageSelector] = $this->imageSelector($image);
        $image = $imageRemote.$imageSelector;
        $arguments = ['init', $image, $this->target($name), '--vm', '--storage', $this->pool, '--network', $network];
        foreach ($this->ownershipMetadata as $key => $value) {
            $arguments[] = '--config';
            $arguments[] = "{$key}={$value}";
        }

        $this->run($arguments, 300);

        return new IncusInstance(
            $this->remote,
            $this->project,
            $name,
            $this->pool,
            $this->ownershipMetadata,
            network: $network,
        );
    }

    public function copySnapshot(string $source, string $snapshot, string $target): IncusInstance
    {
        $this->validateName($source, 'instance');
        $this->validateName($snapshot, 'snapshot');
        $this->validateName($target, 'instance');
        $this->run([
            'copy',
            "{$this->target($source)}/{$snapshot}",
            $this->target($target),
            '--storage',
            $this->pool,
        ], 300);

        return new IncusInstance($this->remote, $this->project, $target, $this->pool);
    }

    public function setNetwork(string $instance, string $network): void
    {
        $this->validatedOwnedVm($instance);
        $resource = $this->network($network);
        if ($resource === null) {
            throw new RuntimeException("Incus network {$network} does not exist.");
        }

        $this->assertOwned($resource->metadata, "network {$network}");
        $this->run(['config', 'device', 'override', $this->target($instance), 'eth0', "network={$network}"]);
    }

    /** @param array<string, string> $metadata */
    public function setMetadata(string $resource, array $metadata): void
    {
        $this->validateName($resource, 'resource');
        if ($metadata === []) {
            throw new RuntimeException('Incus metadata cannot be empty.');
        }

        $this->validateStringMap($metadata, 'metadata');
        foreach ($metadata as $key => $value) {
            $this->validateMetadata($key, $value);
        }

        $instance = $this->instance($resource);
        if ($instance !== null) {
            $this->assertOwned($instance->metadata, "instance {$resource}");
            $arguments = ['config', 'set', $this->target($resource)];
        } else {
            $network = $this->network($resource);
            if ($network === null) {
                throw new RuntimeException("Incus resource {$resource} does not exist.");
            }

            $this->assertOwned($network->metadata, "network {$resource}");
            $arguments = ['network', 'set', "{$this->remote}:{$resource}"];
        }

        foreach ($metadata as $key => $value) {
            $arguments[] = "{$key}={$value}";
        }

        $this->run($arguments);
    }

    public function start(string $instance): void
    {
        $this->validatedOwnedVm($instance);
        $this->run(['start', $this->target($instance)], 120);
    }

    public function stop(string $instance): void
    {
        $this->validatedOwnedVm($instance);
        $this->run(['stop', $this->target($instance)], 120);
    }

    public function snapshot(string $instance, string $snapshot): IncusSnapshot
    {
        $vm = $this->validatedOwnedVm($instance);
        $this->validateName($snapshot, 'snapshot');
        $this->run(['snapshot', 'create', $this->target($instance), $snapshot], 300);

        return new IncusSnapshot($vm, $snapshot);
    }

    public function restore(string $instance, string $snapshot): void
    {
        $this->validatedOwnedSnapshot($instance, $snapshot);
        $this->run(['snapshot', 'restore', $this->target($instance), $snapshot], 300);
    }

    public function deleteSnapshot(string $instance, string $snapshot): void
    {
        $this->validatedOwnedSnapshot($instance, $snapshot);
        $this->run(['snapshot', 'delete', $this->target($instance), $snapshot], 300);
    }

    public function deleteInstance(string $instance): void
    {
        $this->validatedOwnedVm($instance);
        $this->run(['delete', $this->target($instance)], 300);
    }

    public function deleteNetwork(string $network): void
    {
        $resource = $this->network($network);
        if ($resource === null) {
            throw new RuntimeException("Incus network {$network} does not exist.");
        }

        $this->assertOwned($resource->metadata, "network {$network}");
        $this->run(['network', 'delete', "{$this->remote}:{$network}"]);
    }

    public function pushFile(string $instance, string $source, string $destination): void
    {
        $this->validatedOwnedVm($instance);
        if (
            $source === ''
            || str_contains($source, "\0")
            || ! str_starts_with($destination, '/')
            || str_contains($destination, "\0")
        ) {
            throw new RuntimeException('Invalid Incus file path.');
        }

        $this->run(['file', 'push', $source, "{$this->target($instance)}{$destination}"], 300);
    }

    public function exec(string $instance, GuestCommand $command): GuestCommandResult
    {
        $this->validatedOwnedVm($instance);
        $result = $this->run(
            ['exec', $this->target($instance), '--', ...$command->command],
            $command->timeout,
            false,
            $command->stdin,
        );

        return new GuestCommandResult($result->output(), $result->errorOutput(), (int) $result->exitCode());
    }

    private function validatedVm(string $name): IncusInstance
    {
        $instance = $this->instance($name);
        if ($instance === null) {
            throw new RuntimeException("Incus instance {$name} does not exist.");
        }

        return $instance;
    }

    private function validatedOwnedVm(string $name): IncusInstance
    {
        $instance = $this->validatedVm($name);
        $this->assertOwned($instance->metadata, "instance {$name}");

        return $instance;
    }

    private function validatedOwnedSnapshot(string $instance, string $snapshot): void
    {
        $vm = $this->validatedOwnedVm($instance);
        $this->validateName($snapshot, 'snapshot');
        $resources = $this->readJson(['snapshot', 'list', $this->target($instance), '--format=json']);
        $expectedNames = [$snapshot, "{$instance}/{$snapshot}"];
        $resource = array_find(
            $resources,
            fn ($candidate) => is_array($candidate) && in_array($candidate['name'] ?? null, $expectedNames, true),
        );
        if (! is_array($resource)) {
            throw new RuntimeException('Incus snapshot identity changed before mutation.');
        }
        $observedName = $resource['name'] ?? null;

        if ($observedName !== $snapshot && $observedName !== "{$instance}/{$snapshot}") {
            throw new RuntimeException('Incus snapshot identity changed before mutation.');
        }

        $metadata = $this->metadata($resource);
        $this->assertOwned($metadata === [] ? $vm->metadata : $metadata, "snapshot {$instance}/{$snapshot}");
    }

    /** @param array<string, string> $metadata */
    private function assertOwned(array $metadata, string $resource): void
    {
        foreach ($this->ownershipMetadata as $key => $value) {
            if (($metadata[$key] ?? null) !== $value) {
                throw new RuntimeException("Incus {$resource} ownership metadata does not match.");
            }
        }
    }

    /**
     * @param list<string> $arguments
     * @return array<mixed>
     */
    private function readJson(array $arguments): array
    {
        $result = $this->run($arguments);

        try {
            $decoded = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Incus returned malformed JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Incus returned malformed JSON.');
        }

        return $decoded;
    }

    /** @param list<string> $arguments */
    private function run(
        array $arguments,
        int $timeout = 60,
        bool $failOnError = true,
        ?string $stdin = null,
    ): ProcessResult {
        $command = ['incus', '--project', $this->project, ...$arguments];

        try {
            $process = Process::timeout($timeout);
            if ($stdin !== null) {
                $process = $process->input($stdin);
            }
            $result = $process->run($command);
        } catch (Throwable $exception) {
            $message = $this->redactor->redact($exception->getMessage());
            if ($this->isGuestExecCommand($command)) {
                $message = 'Incus guest command could not run.';
            }
            $this->recordFailure($command, null, $message);

            throw new RuntimeException("Incus command timed out or could not run: {$message}", 0, $exception);
        }

        if ($failOnError && ! $result->successful()) {
            $error = $this->redactor->redact(trim($result->errorOutput()."\n".$result->output()));
            $this->recordFailure($command, $result->exitCode(), $error);

            throw new RuntimeException("Incus command failed with exit code {$result->exitCode()}: {$error}");
        }

        return $result;
    }

    /** @param list<string> $command */
    private function recordFailure(array $command, ?int $exitCode, string $error): void
    {
        if ($this->journal === null || $this->operationId === null) {
            return;
        }

        $journalCommand = $command;
        if ($this->isGuestExecCommand($command)) {
            $journalCommand = array_slice($command, 0, 6);
        }

        $this->journal->append($this->operationId, [
            'command' => $this->redactor->redactArray($journalCommand),
            'exit_code' => $exitCode,
            'error' => $error,
        ]);
    }

    /** @param list<string> $command */
    private function isGuestExecCommand(array $command): bool
    {
        return (
            count($command) >= 6
            && ($command[0] ?? null) === 'incus'
            && ($command[1] ?? null) === '--project'
            && is_string($command[2] ?? null)
            && ($command[3] ?? null) === 'exec'
            && is_string($command[4] ?? null)
            && ($command[5] ?? null) === '--'
        );
    }

    /** @return array<string, string> */
    private function metadata(array $resource): array
    {
        $configuration = $resource['config'] ?? [];
        if (! is_array($configuration)) {
            throw new RuntimeException('Incus returned invalid resource configuration.');
        }

        return $this->e2eMetadata($configuration);
    }

    /** @return array<string, string> */
    private function e2eMetadata(array $configuration): array
    {
        $metadata = [];

        foreach ($configuration as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'user.orbit.e2e.') && is_string($value)) {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    private function validateName(string $value, string $label): void
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/', $value) !== 1) {
            throw new RuntimeException("Invalid Incus {$label} identity.");
        }
    }

    private function target(string $name): string
    {
        return "{$this->remote}:{$name}";
    }

    /** @return array{string, string} */
    private function imageSelector(string $image): array
    {
        $separator = strpos($image, ':');
        if ($separator === false) {
            return ["{$this->remote}:", $image];
        }

        $remote = substr($image, 0, $separator);
        $selector = substr($image, $separator + 1);
        $this->validateName($remote, 'image remote');
        if ($selector === '' || str_contains($selector, ':')) {
            throw new RuntimeException('Invalid Incus image identity.');
        }

        return ["{$remote}:", $selector];
    }

    private function validateImage(string $image): void
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:\/-]{0,254}\z/', $image) !== 1) {
            throw new RuntimeException('Invalid Incus image identity.');
        }
    }

    private function validateMetadata(string $key, string $value): void
    {
        if (preg_match('/\Auser\.orbit\.e2e\.[a-z0-9.-]+\z/', $key) !== 1 || str_contains($value, "\0")) {
            throw new RuntimeException('Invalid Incus ownership metadata.');
        }
    }

    private function validateConfiguration(string $key, string $value): void
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/', $key) !== 1 || str_contains($value, "\0")) {
            throw new RuntimeException('Invalid Incus network configuration.');
        }
    }

    private function validateStringMap(array $values, string $label): void
    {
        foreach ($values as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new RuntimeException("Incus {$label} must contain string keys and values.");
            }
        }
    }
}
