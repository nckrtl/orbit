<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolRemovalPlan;
use App\Models\Node;
use Throwable;

/** @mago-expect lint:too-many-methods The fake implements the complete closed manager lifecycle contract. */
final class FakeToolManager implements ToolManager
{
    /** @var list<string|null|Throwable> */
    public array $installedVersions = [];

    /** @var list<string|null|Throwable> */
    public array $candidateVersions = [];

    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, list<Throwable>> */
    public array $failures = [];

    public bool $supports = true;

    public bool $validPackage = true;

    public ToolRemovalPlan $removalPlan;

    public function __construct(
        public ToolManagerName $managerName = ToolManagerName::Apt,
    ) {
        $this->removalPlan = new ToolRemovalPlan([]);
    }

    public function name(): ToolManagerName
    {
        return $this->managerName;
    }

    public function supportsNode(Node $node): bool
    {
        return $this->supports;
    }

    public function validatePackage(string $package): bool
    {
        $this->calls[] = 'validatePackage';

        return $this->validPackage;
    }

    public function managerVersion(Node $node): string
    {
        $this->calls[] = 'managerVersion';

        return '1.0.0';
    }

    public function candidateVersion(Node $node, string $package, ToolOperation $operation): ?string
    {
        return $this->next($this->candidateVersions, 'candidateVersion');
    }

    public function installedVersion(Node $node, string $package): ?string
    {
        return $this->next($this->installedVersions, 'installedVersion');
    }

    public function normalizeVersion(string $rawVersion): ?string
    {
        return preg_match('/\A\d+(?:\.\d+){0,2}\z/', $rawVersion) === 1 ? $rawVersion : null;
    }

    public function install(Node $node, string $package): void
    {
        $this->failOrCall('install');
    }

    public function update(Node $node, string $package): void
    {
        $this->failOrCall('update');
    }

    public function planRemoval(Node $node, string $package): ToolRemovalPlan
    {
        $this->failOrCall('planRemoval');

        return $this->removalPlan;
    }

    public function remove(Node $node, string $package): void
    {
        $this->failOrCall('remove');
    }

    /** @param list<string|null|Throwable> $queue */
    private function next(array &$queue, string $call): ?string
    {
        $this->calls[] = $call;
        $value = array_shift($queue);

        if ($value instanceof Throwable) {
            throw $value;
        }

        return $value;
    }

    private function failOrCall(string $call): void
    {
        $this->calls[] = $call;
        $failure = $this->failures[$call][0] ?? null;

        if (! $failure instanceof Throwable) {
            return;
        }

        array_shift($this->failures[$call]);

        throw $failure;
    }
}
