<?php

declare(strict_types=1);

namespace App\Actions\Herdr;

use App\Actions\Processes\AddProcessAction;
use App\Actions\Processes\StartProcessAction;
use App\Data\Herdr\AddHerdrSessionData;
use App\Data\Processes\AddProcessData;
use App\Domain\Herdr\HerdrObserveContract;
use App\Domain\Herdr\HerdrObserverPublisher;
use App\Domain\Herdr\HerdrSessionInspection;
use App\Domain\Herdr\HerdrSessionInspector;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\HerdrSession;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class EnsureHerdrSessionAction
{
    public function __construct(
        private RequireHerdrToolAction $requireTool,
        private ProcessTargetResolver $targets,
        private AddProcessAction $addProcess,
        private StartProcessAction $startProcess,
        private ProcessRuntimeManager $runtime,
        private ProcessSpecification $specifications,
        private HerdrObserveContract $contract,
        private HerdrObserverPublisher $observers,
        private HerdrSessionInspector $inspector,
    ) {}

    /** @return array{session: HerdrSession, created: bool} */
    public function execute(AddHerdrSessionData $data): array
    {
        $node = $this->targets->forNodeAdmission(Node::query()->findOrFail($data->nodeId))->node;
        $this->requireTool->execute($node);

        if ($data->user !== $node->user) {
            throw new ResourceOperationException(
                errorCode: 'herdr.user_mismatch',
                message: "Unix user [{$data->user}] does not match managed user [{$node->user}] on node [{$node->name}].",
                status: 422,
            );
        }

        [$session, $created] = $this->retainSession($data, $node);
        $process = $this->ensureProcess($session, $node);
        $session->process_id = $process->id;
        $session->save();
        $this->keepCompatibleProcess($process);
        $this->inspectObserverCapability($session->refresh(), $node);
        $this->publishObserver($session->refresh(), $node);

        $session->fill([
            'status' => LifecycleStatus::Active,
            'failed_step' => $session->observer_status === 'failed' ? 'observer' : null,
            'error_code' => $session->observer_status === 'failed' ? 'herdr.observer_failed' : null,
        ])->save();

        return ['session' => $session->refresh()->load(['node', 'process']), 'created' => $created];
    }

    /** @return array{0: HerdrSession, 1: bool} */
    private function retainSession(AddHerdrSessionData $data, Node $node): array
    {
        return DB::transaction(function () use ($data, $node): array {
            $existing = HerdrSession::query()
                ->where('node_id', $node->id)
                ->where('session', $data->session)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof HerdrSession && $existing->user !== $data->user) {
                throw new ResourceOperationException(
                    errorCode: 'herdr.session_conflict',
                    message: "Herdr session [{$data->session}] already exists with different configuration.",
                    status: 409,
                );
            }

            if ($existing instanceof HerdrSession) {
                $existing->publish_observer = $existing->publish_observer || $data->publishObserver;
                $existing->save();

                return [$existing, false];
            }

            $session = new HerdrSession([
                'node_id' => $node->id,
                'session' => $data->session,
                'user' => $data->user,
                'observer_port' => $this->contract->nextPort($node),
                'observer_hostname' => $this->contract->hostname($node, $data->session),
                'observer_status' => 'pending',
                'status' => LifecycleStatus::Provisioning,
                'publish_observer' => $data->publishObserver,
            ]);
            $session->save();

            return [$session, true];
        });
    }

    private function ensureProcess(HerdrSession $session, Node $node): Process
    {
        $command = $this->contract->serverCommand($session->session);
        $name = $this->contract->processName($session->session);
        $existing = Process::query()
            ->where('owner_type', Node::class)
            ->where('owner_id', $node->id)
            ->where('name', $name)
            ->first();

        if ($existing instanceof Process) {
            $target = $this->targets->forProcess($existing);
            $attributes = $this->specifications->attributes(new AddProcessData(
                targetType: ProcessTargetType::Node,
                targetId: $node->id,
                name: $name,
                runtime: ProcessRuntime::Systemd,
                command: $command,
                image: null,
                workingDirectory: null,
                environment: [],
                ports: [],
                volumes: [],
                restartPolicy: 'unless-stopped',
                start: true,
            ), $target);

            if (! $this->specifications->matches($existing, $attributes)) {
                throw new ResourceOperationException(
                    errorCode: 'herdr.session_conflict',
                    message: "Herdr session [{$session->session}] already exists with different configuration.",
                    status: 409,
                );
            }

            return $existing;
        }

        return $this->addProcess->execute(new AddProcessData(
            targetType: ProcessTargetType::Node,
            targetId: $node->id,
            name: $name,
            runtime: ProcessRuntime::Systemd,
            command: $command,
            image: null,
            workingDirectory: null,
            environment: [],
            ports: [],
            volumes: [],
            restartPolicy: 'unless-stopped',
            start: true,
        ))['process'];
    }

    private function keepCompatibleProcess(Process $process): void
    {
        $observed = $this->runtime->status($process);

        if (in_array($observed, ['running', 'active'], true)) {
            return;
        }

        $this->startProcess->execute($process);
    }

    private function publishObserver(HerdrSession $session, Node $node): void
    {
        if (! $session->publish_observer) {
            return;
        }

        try {
            $publication = $this->observers->publish($session, $node);
        } catch (Throwable) {
            $session->update([
                'observer_status' => 'failed',
                'observer_error' => 'observer publication failed',
            ]);

            return;
        }

        $session->update([
            'observer_url' => $publication->url,
            'observer_status' => $publication->published ? 'published' : 'failed',
            'observer_error' => $publication->error,
        ]);
    }

    private function inspectObserverCapability(HerdrSession $session, Node $node): void
    {
        try {
            $inspection = $this->inspector->inspect($session, $node);
        } catch (Throwable $exception) {
            if ($session->publish_observer) {
                $this->recordObserverFailure($session, 'herdr.inspection_failed');

                if ($exception instanceof ResourceOperationException) {
                    throw $exception;
                }

                throw new ResourceOperationException(
                    errorCode: 'herdr.inspection_failed',
                    message: 'Herdr session inspection failed.',
                    status: 422,
                    previous: $exception,
                );
            }

            return;
        }

        $this->applyInspection($session, $inspection);

        if (! $session->publish_observer) {
            return;
        }

        try {
            $this->contract->assertCompatible($inspection);
        } catch (ResourceOperationException $exception) {
            $this->recordObserverFailure($session, $exception->errorCode);

            throw $exception;
        }
    }

    private function applyInspection(HerdrSession $session, HerdrSessionInspection $inspection): void
    {
        $session->update([
            'herdr_version' => $inspection->version,
            'protocol' => $inspection->protocol,
            'handoff_supported' => $inspection->handoffSupported,
        ]);
    }

    private function recordObserverFailure(HerdrSession $session, string $errorCode): void
    {
        $session->update([
            'observer_status' => 'failed',
            'observer_error' => 'observer capability verification failed',
            'status' => LifecycleStatus::Failed,
            'failed_step' => 'observer',
            'error_code' => $errorCode,
        ]);
    }
}
