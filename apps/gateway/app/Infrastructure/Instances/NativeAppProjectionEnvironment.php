<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\AppProjectionEnvironment;
use App\Domain\Instances\Apps\AppProjectionIdentity;
use App\Domain\Instances\Apps\AppProjectionReceipt;
use App\Domain\Instances\Environment\AppProjectionEnvironmentTarget;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\InstanceTestEnvironment;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\InstanceAppProjectionStep;
use InvalidArgumentException;
use Throwable;

/** A per-app adapter bound by the parent to frozen contexts. It does not publish or resolve candidate maps. */
final readonly class NativeAppProjectionEnvironment implements AppProjectionEnvironment
{
    /** @param list<AppProjectionEnvironmentTarget> $targets */
    public function __construct(
        private DevelopmentSshExecutor $ssh,
        private InstanceEnvironmentStore $store,
        private InstanceEnvironmentRenderer $renderer,
        private InstanceTestEnvironment $testing,
        private InstanceEnvironmentContext $published,
        private array $targets,
    ) {
        if ($targets === [] || count(array_unique(array_map(static fn (AppProjectionEnvironmentTarget $target): string => $target->scope, $targets))) !== count($targets)
            || array_any($targets, fn (AppProjectionEnvironmentTarget $target): bool => $target->old->instanceId !== $published->instanceId
                || $target->old->nodeId !== $published->nodeId || $target->old->app !== $published->app
                || $target->old->executionUser !== $published->executionUser)) {
            throw new InvalidArgumentException('Environment projection targets must belong to one published app.');
        }
    }

    /**
     * Commit these exact identities in the step before invoking this adapter.
     *
     * @return array<string, string>
     */
    public function targetIdentities(): array
    {
        $identities = [];
        foreach ($this->targets as $target) {
            $identities = [...$identities, ...$target->identities()];
        }

        return $identities;
    }

    public function mutate(InstanceAppProjectionStep $step): AppProjectionReceipt
    {
        return $this->execute($step, false);
    }

    public function recover(InstanceAppProjectionStep $step): AppProjectionReceipt
    {
        return $this->execute($step, true);
    }

    private function execute(InstanceAppProjectionStep $step, bool $recover): AppProjectionReceipt
    {
        $intent = $step->intent;
        $phase = $intent['phase'] ?? null;
        if (! in_array($phase, ['prepare', 'restore', 'cleanup'], true) || ($intent['resource'] ?? null) !== 'environment'
            || ($intent['instance_id'] ?? null) !== $this->published->instanceId || ($intent['node_id'] ?? null) !== $this->published->nodeId
            || ($intent['app'] ?? null) !== $this->published->app || ! is_array($intent['targets'] ?? null)) {
            $this->conflict();
        }
        foreach ($this->targetIdentities() as $key => $identity) {
            if (($intent['targets'][$key] ?? null) !== $identity) {
                $this->conflict();
            }
        }
        $payload = ['action' => $recover ? 'recover' : $phase, 'binding' => $this->binding($step), 'targets' => $intent['targets'],
            'user' => $this->published->executionUser];
        if ($phase !== 'prepare') {
            $sourceId = $intent['targets']['restores_step_id'] ?? $intent['targets']['cleans_step_id'] ?? null;
            $source = is_string($sourceId) ? InstanceAppProjectionStep::query()->find($sourceId) : null;
            if (! $source instanceof InstanceAppProjectionStep || $source->instance_app_projection_id !== $step->instance_app_projection_id
                || ($source->intent['phase'] ?? null) !== 'prepare' || ($source->intent['app'] ?? null) !== $this->published->app
                || $source->plan_digest !== $step->plan_digest) {
                $this->conflict();
            }
            $payload['source_receipt'] = $source->receipt_id;
            $payload['source_binding'] = $this->binding($source);
        } elseif (! $recover) {
            $values = $this->store->projectionSnapshot($this->published, $step)->values();
            $testing = $values === [] ? null : $this->testing->values($this->published->instanceId, $values);
            $payload['contexts'] = [];
            $payload['files'] = [];
            foreach ($this->targets as $target) {
                $payload['contexts'][] = ['scope' => $target->scope, 'old' => $target->old->path, 'candidate' => $target->candidate->path,
                    'old_checkout' => $target->oldCheckout, 'candidate_checkout' => $target->candidateCheckout, 'release' => $target->releaseIdentity];
                foreach (['.env' => $values === [] ? null : $values, '.env.testing' => $testing] as $name => $fileValues) {
                    if ($fileValues !== null) {
                        $payload['files'][$target->scope.':'.$name] = ['old' => base64_encode($this->renderer->render($target->old, $fileValues)),
                            'candidate' => base64_encode($this->renderer->render($target->candidate, $fileValues))];
                    }
                }
            }
        }
        try {
            $result = $this->ssh->execute($this->published->node, new RemoteCommand(
                arguments: ['sudo', 'python3', '-c', AppProjectionEnvironmentProgram::script()],
                protectedInput: ProtectedInput::fromString(json_encode($payload, JSON_THROW_ON_ERROR)), maxOutputBytes: 16384,
            ), 'app-projection-environment', 'app.projection_receipt_conflict');
            $evidence = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($evidence)) {
                $this->conflict();
            }
            if (($evidence['complete'] ?? null) !== true) {
                $this->conflict();
            }
            $receipt = new AppProjectionReceipt($this->string($evidence['step_id'] ?? null), $this->string($evidence['projection_id'] ?? null),
                $this->string($evidence['receipt_id'] ?? null), $this->string($evidence['plan_digest'] ?? null),
                $this->string($evidence['intent_digest'] ?? null), $this->string($evidence['result_fingerprint'] ?? null),
                $this->strings($evidence['snapshots'] ?? null), true, $this->strings($evidence['targets'] ?? null), $this->artifacts($evidence['artifacts'] ?? null));
            if (! $receipt->matches($step)) {
                $this->conflict();
            }

            return $receipt;
        } catch (RuntimeConvergenceException $exception) {
            $observation = json_decode($exception->result->stdout ?? '', true);
            $reason = is_array($observation) && is_string($observation['conflict'] ?? null)
                && in_array($observation['conflict'], ['source', 'destination', 'unconfigured_file', 'containment', 'directory', 'file', 'permissions', 'capacity',
                    'foreign_replacement', 'directory_changed', 'protection', 'binding', 'missing_receipt', 'unsafe_or_damaged'], true)
                ? $observation['conflict'] : 'protected_evidence';
            if ($exception->result?->exitCode === 42) {
                throw new ResourceOperationException('instance.app_environment_conflict', 'The app environment source or destination is unsafe or differs from stored configuration.', 409,
                    details: ['app' => $this->published->app, 'reason' => $reason]);
            }
            $this->conflict($reason);
        } catch (ResourceOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->conflict();
        }
    }

    /** @return array<string, mixed> */
    private function binding(InstanceAppProjectionStep $step): array
    {
        return ['step_id' => $step->id, 'projection_id' => $step->instance_app_projection_id, 'receipt_id' => $step->receipt_id,
            'plan_digest' => $step->plan_digest, 'intent_digest' => AppProjectionIdentity::digest($step->intent),
            'instance_id' => $step->intent['instance_id'], 'node_id' => $step->intent['node_id'], 'app' => $step->intent['app'],
            'execution_user' => $this->published->executionUser,
            'owner' => $step->intent['project_update_id'] ?? $step->intent['instance_app_update_id']];
    }

    private function string(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            $this->conflict();
        }

        return $value;
    }

    /** @return array<string, string> */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            $this->conflict();
        }
        $strings = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                $this->conflict();
            }
            $strings[$key] = $this->string($item);
        }

        return $strings;
    }

    /** @return array<string, array{created: bool, protection_fingerprint: string, result_fingerprint: string}> */
    private function artifacts(mixed $value): array
    {
        if (! is_array($value)) {
            $this->conflict();
        }
        $artifacts = [];
        foreach ($value as $key => $artifact) {
            if (! is_string($key) || ! is_array($artifact) || ! is_bool($artifact['created'] ?? null)) {
                $this->conflict();
            }
            $artifacts[$key] = ['created' => $artifact['created'], 'protection_fingerprint' => $this->string($artifact['protection_fingerprint'] ?? null),
                'result_fingerprint' => $this->string($artifact['result_fingerprint'] ?? null)];
        }

        return $artifacts;
    }

    private function conflict(string $reason = 'protected_evidence'): never
    {
        throw new ResourceOperationException('app.projection_receipt_conflict', 'Protected app environment evidence is missing, damaged or foreign.', 409,
            details: ['app' => $this->published->app, 'reason' => $reason]);
    }
}
