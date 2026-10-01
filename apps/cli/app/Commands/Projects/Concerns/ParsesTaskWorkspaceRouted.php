<?php

declare(strict_types=1);

namespace App\Commands\Projects\Concerns;

/** Shared by project create and update so a bare flag cannot be mistaken for omission. */
trait ParsesTaskWorkspaceRouted
{
    /**
     * Only the exact strings true and false count. A present flag with any other value, including none, is invalid.
     *
     * @return array{valid: bool, value: ?bool}
     */
    private function taskWorkspaceRouted(): array
    {
        if (! $this->input->hasParameterOption('--task-workspace-routed')) {
            return ['valid' => true, 'value' => null];
        }

        $value = $this->option('task-workspace-routed');

        if ($value === 'true' || $value === 'false') {
            return ['valid' => true, 'value' => $value === 'true'];
        }

        return ['valid' => false, 'value' => null];
    }
}
