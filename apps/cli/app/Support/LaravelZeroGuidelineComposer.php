<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Boost\Install\GuidelineAssist;
use Laravel\Boost\Install\GuidelineComposer;
use RuntimeException;

final class LaravelZeroGuidelineComposer extends GuidelineComposer
{
    public function compose(): string
    {
        $guidelines = parent::compose();
        $optionalLocation = 'in `.ai/rules` when that directory exists';
        $permissiveFallback = 'If `.ai/rules` does not exist, continue without it.';

        if (
            substr_count($guidelines, $optionalLocation) !== 1
            || substr_count($guidelines, $permissiveFallback) !== 1
        ) {
            throw new RuntimeException(
                'Boost project-rule guidance changed. Update the Orbit CLI hard-stop transformation before regenerating.',
            );
        }

        return str_replace(
            [
                $optionalLocation,
                $permissiveFallback,
            ],
            [
                'in `.ai/rules` as required repository state',
                'If `.ai/rules` does not exist, the checkout or Boost bootstrap is incomplete. '
                    .'Restore or regenerate the guidance before planning or editing any file. '
                    .'Do not silently continue without the project rule set.',
            ],
            $guidelines,
        );
    }

    protected function getGuidelineAssist(): GuidelineAssist
    {
        return new LaravelZeroGuidelineAssist($this->project, $this->config);
    }
}
