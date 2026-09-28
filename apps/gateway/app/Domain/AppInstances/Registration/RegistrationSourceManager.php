<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Registration;

use App\Models\Instance;
use App\Models\Node;

interface RegistrationSourceManager
{
    /** @return list<RegistrationSourceFacts> */
    public function inspect(Node $node, string $sourcePath, bool $includeWorktrees): array;

    public function validateRetained(Node $node, RegistrationSourceFacts $facts, string $authoritativePath): void;

    public function validateRelocationRecovery(
        Node $node,
        RegistrationSourceFacts $facts,
        string $candidatePath,
    ): void;

    public function relocate(Instance $appInstance, RegistrationSourceFacts $facts): void;

    /** @param list<array{appInstance: Instance, facts: RegistrationSourceFacts}> $members */
    public function relocateSet(array $members): void;

    public function restoreOriginal(Instance $appInstance, RegistrationSourceFacts $facts): void;

    public function prepareLaravelRollback(Instance $appInstance): void;

    public function restoreLaravelConfiguration(Instance $appInstance): void;

    public function discardLaravelRollback(Instance $appInstance): void;
}
