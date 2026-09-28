<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Extensions\ExtensionStore;

final readonly class TaskExtensionState
{
    public const string Key = 'extension.tasks.enabled';

    public function __construct(private ExtensionStore $extensions) {}

    public function enabled(): bool
    {
        return $this->extensions->enabled('tasks');
    }

    public function enable(): void
    {
        $this->extensions->set('tasks', true);
    }

    public function disable(): void
    {
        $this->extensions->set('tasks', false);
    }
}
