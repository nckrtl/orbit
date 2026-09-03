<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Data\AppInstances\RegisterAppInstanceData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppInstances\AppInstanceSourceKind;
use App\Domain\AppInstances\RegisteredWorktreeInspector;
use App\Domain\AppInstances\RegisteredWorktreeObservation;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Database\QueryException;

/** @mago-expect lint:cyclomatic-complexity Registration keeps its fail-closed identity checks in one source lock. */
final readonly class RegisterAppInstanceAction
{
    public function __construct(
        private AppDevSourceOperationLock $sourceLock,
        private RegisteredWorktreeInspector $inspector,
        private ManagedCheckoutOverlap $checkoutOverlap,
    ) {}

    /** @return array{appInstance: AppInstance, created: bool} */
    public function execute(Node $caller, RegisterAppInstanceData $data): array
    {
        $this->assertCaller($caller);
        $app = OrbitApp::query()->where('slug', $data->appSlug)->firstOrFail();
        GitRepositoryOrigin::validate($app->repository_url);
        $effectiveRoot = $data->root ?? $app->root;

        if (! is_string($effectiveRoot) || ! RelativeWebRoot::isValid($effectiveRoot)) {
            throw new ResourceOperationException(
                errorCode: 'app.source_defaults_incomplete',
                message: "App [{$app->slug}] does not have a valid effective root.",
            );
        }

        $checkout = StoragePath::parse($data->checkoutPath);
        $name = $data->name ?? basename(dirname($checkout->value));

        if (preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $name) !== 1 || strlen($name) > 63) {
            throw new ResourceOperationException(
                errorCode: 'instance.name_invalid',
                message: 'The derived or supplied AppInstance name is invalid.',
            );
        }

        return $this->sourceLock->synchronized(
            $caller->id,
            function () use ($caller, $app, $data, $checkout, $effectiveRoot, $name): array {
                $observation = $this->inspector->inspect(
                    $caller,
                    $app,
                    $checkout->value,
                    $effectiveRoot,
                );
                $canonicalCheckout = StoragePath::tryParse($observation->checkoutPath);

                if (! $canonicalCheckout instanceof StoragePath || ! $canonicalCheckout->equals($checkout)) {
                    throw $this->conflict(
                        'instance.worktree_identity_invalid',
                        'The registered worktree identity is invalid.',
                    );
                }

                $this->assertObservation($observation);
                $existing = AppInstance::query()
                    ->where('app_id', $app->id)
                    ->where('name', $name)
                    ->first();

                if ($existing instanceof AppInstance) {
                    $this->assertRetry($existing, $caller, $data, $name, $observation);

                    return ['appInstance' => $existing->refresh(), 'created' => false];
                }

                $this->checkoutOverlap->assertAvailable(
                    $caller->id,
                    $canonicalCheckout,
                    'instance.path_taken',
                );

                try {
                    $created = AppInstance::query()->create([
                        'app_id' => $app->id,
                        'node_id' => $caller->id,
                        'name' => $name,
                        'environment' => 'development',
                        'source_kind' => AppInstanceSourceKind::RegisteredWorktree->value,
                        'checkout_path' => $observation->checkoutPath,
                        'root' => $data->root,
                        'branch' => $observation->branch,
                        'starting_commit' => $observation->startingCommit,
                        'source_identity' => $observation->sourceIdentity,
                        'status' => 'active',
                    ]);
                } catch (QueryException) {
                    $raced = AppInstance::query()
                        ->where('app_id', $app->id)
                        ->where('name', $name)
                        ->first();

                    if (! $raced instanceof AppInstance) {
                        throw $this->conflict('instance.registration_conflict', 'AppInstance registration conflicted.');
                    }

                    $this->assertRetry($raced, $caller, $data, $name, $observation);

                    return ['appInstance' => $raced->refresh(), 'created' => false];
                }

                return ['appInstance' => $created->refresh(), 'created' => true];
            },
        );
    }

    private function assertCaller(Node $caller): void
    {
        if (
            $caller->status !== LifecycleStatus::Active
            || $caller->platform !== 'linux'
            || ! $caller
                ->roles()
                ->where('role', RoleName::AppDev)
                ->where('status', LifecycleStatus::Active)
                ->exists()
        ) {
            throw new ResourceOperationException(
                errorCode: 'instance.caller_not_app_dev',
                message: 'The authenticated caller is not an active supported app-dev Node.',
                status: 409,
            );
        }
    }

    private function assertObservation(RegisteredWorktreeObservation $observation): void
    {
        if (
            $observation->branch !== null
            && ($observation->branch === ''
            || strlen($observation->branch) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $observation->branch) === 1)
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $observation->startingCommit) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $observation->sourceIdentity) !== 1
        ) {
            throw $this->conflict(
                'instance.worktree_identity_invalid',
                'The registered worktree returned invalid identity evidence.',
            );
        }
    }

    private function assertRetry(
        AppInstance $existing,
        Node $caller,
        RegisterAppInstanceData $data,
        string $name,
        RegisteredWorktreeObservation $observation,
    ): void {
        if (
            $existing->node_id !== $caller->id
            || $existing->name !== $name
            || $existing->source_kind !== AppInstanceSourceKind::RegisteredWorktree->value
            || $existing->checkout_path !== $observation->checkoutPath
            || $existing->root !== $data->root
            || $existing->source_identity !== $observation->sourceIdentity
        ) {
            throw $this->conflict(
                'instance.registration_conflict',
                'AppInstance registration identity conflicts with the existing record.',
            );
        }

        $this->checkoutOverlap->assertAvailable(
            $caller->id,
            StoragePath::parse($observation->checkoutPath),
            'instance.registration_conflict',
            ignoreAppInstanceId: $existing->id,
        );
    }

    private function conflict(string $code, string $message): ResourceOperationException
    {
        return new ResourceOperationException(errorCode: $code, message: $message, status: 409);
    }
}
