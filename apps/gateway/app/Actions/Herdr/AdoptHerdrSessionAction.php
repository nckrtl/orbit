<?php

declare(strict_types=1);

namespace App\Actions\Herdr;

use App\Data\Herdr\AddHerdrSessionData;
use App\Domain\Herdr\HerdrObserveContract;
use App\Domain\Herdr\HerdrObserverPublisher;
use App\Domain\Herdr\HerdrSessionInspection;
use App\Domain\Herdr\HerdrSessionInspector;
use App\Domain\Herdr\HerdrSessionManagement;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\HerdrSession;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class AdoptHerdrSessionAction
{
    public function __construct(
        private RequireHerdrToolAction $requireTool,
        private ProcessTargetResolver $targets,
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

        $this->assertNoConflict($data, $node);

        $candidate = new HerdrSession([
            'node_id' => $node->id,
            'session' => $data->session,
            'user' => $data->user,
            'management' => HerdrSessionManagement::External,
        ]);

        try {
            $inspection = $this->inspector->inspect($candidate, $node);
        } catch (ResourceOperationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ResourceOperationException(
                errorCode: 'herdr.inspection_failed',
                message: 'Herdr session inspection failed.',
                status: 422,
                previous: $exception,
            );
        }

        [$session, $created] = $this->retainSession($data, $node);
        $this->applyInspection($session, $inspection);

        if ($session->publish_observer) {
            try {
                $this->contract->assertCompatible($inspection);
            } catch (ResourceOperationException $exception) {
                $this->recordObserverFailure($session, $exception->errorCode);

                throw $exception;
            }

            if ($created || $session->observer_status !== 'published') {
                $this->publishObserver($session, $node);
            }
        } else {
            $session->update(['observer_status' => 'unpublished']);
        }

        $session->fill([
            'status' => LifecycleStatus::Active,
            'failed_step' => $session->observer_status === 'failed' ? 'observer' : null,
            'error_code' => $session->observer_status === 'failed' ? 'herdr.observer_failed' : null,
        ])->save();

        return ['session' => $session->refresh()->load(['node', 'process']), 'created' => $created];
    }

    private function assertNoConflict(AddHerdrSessionData $data, Node $node): void
    {
        $existing = HerdrSession::query()
            ->where('node_id', $node->id)
            ->where('session', $data->session)
            ->first();

        if ($existing instanceof HerdrSession
            && ($existing->user !== $data->user || $existing->management !== HerdrSessionManagement::External)) {
            throw $this->conflict($data->session);
        }

        $processExists = Process::query()
            ->where('owner_type', Node::class)
            ->where('owner_id', $node->id)
            ->where('name', $this->contract->processName($data->session))
            ->exists();

        if ($processExists) {
            throw $this->conflict($data->session);
        }
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

            if ($existing instanceof HerdrSession) {
                if ($existing->user !== $data->user || $existing->management !== HerdrSessionManagement::External) {
                    throw $this->conflict($data->session);
                }

                $existing->publish_observer = $existing->publish_observer || $data->publishObserver;
                $existing->save();

                return [$existing, false];
            }

            $processExists = Process::query()
                ->where('owner_type', Node::class)
                ->where('owner_id', $node->id)
                ->where('name', $this->contract->processName($data->session))
                ->exists();

            if ($processExists) {
                throw $this->conflict($data->session);
            }

            $session = new HerdrSession([
                'node_id' => $node->id,
                'session' => $data->session,
                'user' => $data->user,
                'management' => HerdrSessionManagement::External,
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

    private function publishObserver(HerdrSession $session, Node $node): void
    {
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

    private function conflict(string $session): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'herdr.session_conflict',
            message: "Herdr session [{$session}] already exists with different lifecycle ownership.",
            status: 409,
        );
    }
}
