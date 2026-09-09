<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Registration;

use App\Models\AppInstance;
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

    public function relocate(AppInstance $appInstance, RegistrationSourceFacts $facts): void;

    /** @param list<array{appInstance: AppInstance, facts: RegistrationSourceFacts}> $members */
    public function relocateSet(array $members): void;

    public function restoreOriginal(AppInstance $appInstance, RegistrationSourceFacts $facts): void;

    public function prepareLaravelRollback(AppInstance $appInstance): void;

    public function restoreLaravelConfiguration(AppInstance $appInstance): void;

    public function discardLaravelRollback(AppInstance $appInstance): void;
}
