<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Hibernation\AppInstanceCheckoutInspector;
use App\Domain\Hibernation\HibernationException;
use App\Domain\Hibernation\RuntimeDependencyState;
use App\Models\AppInstance;

final class FakeAppInstanceCheckoutInspector implements AppInstanceCheckoutInspector
{
    public RuntimeDependencyState $state;

    /** @var list<string> */
    public array $pruned = [];

    /** @var list<string> */
    public array $restored = [];

    public ?HibernationException $restoreFailure = null;

    public ?ProcessesApiFakeRuntimeManager $runtime = null;

    /** @var list<int> */
    public array $startedBeforeRestore = [];

    public function __construct()
    {
        $this->state = new RuntimeDependencyState(
            vendorReconstructable: true,
            vendorPresent: true,
            nodeModulesReconstructable: true,
            nodeModulesPresent: true,
            sourceTreeLastActivityUnix: null,
        );
    }

    public function inspect(AppInstance $instance): RuntimeDependencyState
    {
        return $this->state;
    }

    public function prune(AppInstance $instance, RuntimeDependencyState $state): void
    {
        $this->pruned[] = (string) $instance->id;
    }

    public function restore(AppInstance $instance, RuntimeDependencyState $state): void
    {
        if ($this->restoreFailure instanceof HibernationException) {
            throw $this->restoreFailure;
        }

        $this->startedBeforeRestore = $this->runtime?->started ?? [];
        $this->restored[] = (string) $instance->id;
    }
}
