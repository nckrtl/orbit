<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Actions\Routes\CreateRouteAction;
use App\Data\AppInstances\CreateAppInstanceData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentSourceProfile;
use App\Domain\AppInstances\ProductionAppInstanceProvisioner;
use App\Domain\AppInstances\ProductionAppInstanceSourceLifecycle;
use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;

final readonly class NativeProductionAppInstanceProvisioner implements ProductionAppInstanceProvisioner
{
    public function __construct(
        private AppDevSourceOperationLock $sourceLock,
        private ProductionAppInstanceSourceLifecycle $source,
        private CreateRouteAction $routes,
    ) {}

    /** @return array{appInstance: AppInstance, created: bool} */
    public function execute(CreateAppInstanceData $data, App $app, Node $node, ?string $root): array
    {
        $existing = AppInstance::query()
            ->where('app_id', $app->id)
            ->where('name', $data->name)
            ->first();

        if (
            $existing instanceof AppInstance
            && $existing->status === AppInstanceState::Active
            && $existing->environment === 'production'
        ) {
            $expectedUser = "orbit-app-{$app->id}";
            $expectedHome = "/home/{$expectedUser}";
            $this->assertRetryIdentity(
                $existing,
                $node,
                $root,
                $data->branch,
                $expectedUser,
                $expectedHome,
                "{$expectedHome}/releases/initial",
            );
            $this->routes->ensureForAppInstance($existing, $data->domain);

            if ($data->recoverSourceProfile && $existing->source_is_laravel === null) {
                $existing = $this->sourceLock->synchronized(
                    $node->id,
                    fn (): AppInstance => $this->recoverActiveSourceProfile($existing),
                );
            }

            return ['appInstance' => $existing->load('routes.targets'), 'created' => false];
        }

        throw new ResourceOperationException(
            errorCode: 'instance.candidate_required',
            message: 'New production AppInstances require a candidate. Use instance:clone.',
            status: 409,
        );
    }

    private function assertRetryIdentity(
        AppInstance $appInstance,
        Node $node,
        ?string $root,
        ?string $branchOverride,
        string $expectedUser,
        string $expectedHome,
        string $expectedCheckout,
    ): void {
        if ($appInstance->status === AppInstanceState::Removing) {
            throw $this->conflict('instance.removal_conflict', 'The AppInstance is being removed.');
        }

        if (
            $appInstance->environment !== 'production'
            || $appInstance->node_id !== $node->id
            || $appInstance->source_layout !== AppInstanceSourceLayout::Checkout->value
            || $appInstance->checkout_path !== $expectedCheckout
            && ! ($appInstance->status === AppInstanceState::Active
            && $appInstance->checkout_path === $expectedHome)
            || $appInstance->production_user !== $expectedUser
            || $appInstance->production_home !== $expectedHome
            || $appInstance->root !== $root
            || $appInstance->branch_override !== $branchOverride
        ) {
            throw $this->conflict('instance.placement_conflict', 'AppInstance placement is immutable.');
        }
    }

    private function recoverActiveSourceProfile(AppInstance $appInstance): AppInstance
    {
        $appInstance->refresh()->loadMissing(['app', 'node']);

        if ($appInstance->source_is_laravel !== null) {
            return $appInstance;
        }

        $profile = $this->source->inspectProfile($appInstance);
        $appInstance->update([
            'source_is_laravel' => $profile->laravel,
            ...$this->recoveredRuntime($appInstance, $profile),
        ]);

        return $appInstance->refresh();
    }

    /** @return array<string, string> */
    private function recoveredRuntime(AppInstance $appInstance, DevelopmentSourceProfile $profile): array
    {
        if ($appInstance->selected_php_version !== null || ! is_string($profile->phpVersion)) {
            return [];
        }

        return [
            'selected_php_version' => $profile->phpVersion,
            ...ProductionPhpRuntimeIdentity::forProvisioning($appInstance, $profile->phpVersion)->attributes(),
        ];
    }

    private function conflict(string $errorCode, string $message): ResourceOperationException
    {
        return new ResourceOperationException($errorCode, $message, 409);
    }
}
