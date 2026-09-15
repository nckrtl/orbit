<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Apps\AppUpdateSourceMutator;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;

final class FakeAppUpdateSourceMutator implements AppUpdateSourceMutator
{
    /** @var list<string> */
    public array $originMutations = [];

    /** @var list<string> */
    public array $originRestores = [];

    /** @var list<int> */
    public array $switchedInstances = [];

    public bool $refuseRepositoryPreflight = false;

    public bool $failOriginChange = false;

    public bool $refuseDefaultBranchPreflight = false;

    /**
     * @param  list<AppInstance>  $checkouts
     */
    public function preflightRepository(array $checkouts, string $currentUrl, string $proposedUrl): void
    {
        if ($this->refuseRepositoryPreflight) {
            throw new ResourceOperationException(
                errorCode: 'app.repository_preflight_failed',
                message: 'An affected source cannot switch to the proposed repository.',
                status: 409,
            );
        }
    }

    public function changeOrigins(array $checkouts, string $previousUrl, string $newUrl, array $evidence): array
    {
        if ($this->failOriginChange && $evidence === []) {
            throw new ResourceOperationException(
                errorCode: 'app.repository_origin_failed',
                message: 'Changing a checkout origin failed.',
                status: 409,
            );
        }

        $byPath = [];

        foreach ($evidence as $row) {
            $byPath[$row['path']] = $row;
        }

        foreach ($checkouts as $checkout) {
            $path = rtrim($checkout->checkout_path, '/');

            if (($byPath[$path]['mutated'] ?? false) === true) {
                continue;
            }

            $this->originMutations[] = $path;
            $byPath[$path] = [
                'path' => $path,
                'previous_url' => $previousUrl,
                'current_url' => $newUrl,
                'mutated' => true,
            ];
        }

        return array_values($byPath);
    }

    public function restoreOrigins(array $mutations): void
    {
        foreach ($mutations as $mutation) {
            if (($mutation['mutated'] ?? false) !== true) {
                continue;
            }

            $this->originRestores[] = $mutation['path'];
        }
    }

    public function preflightDefaultBranch(AppInstance $instance, string $newBranch): void
    {
        if ($this->refuseDefaultBranchPreflight) {
            throw new ResourceOperationException(
                errorCode: 'app.source_switch_failed',
                message: 'An affected source cannot switch to the proposed default branch.',
                status: 409,
            );
        }
    }

    public function switchDefaultBranch(AppInstance $instance, string $newBranch): void
    {
        $this->switchedInstances[] = $instance->id;
    }

    public function restoreDefaultBranch(AppInstance $instance, string $previousBranch): void {}
}
