<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Boost\Install\GuidelineAssist;
use Laravel\Boost\Install\GuidelineComposer;

final class LaravelZeroGuidelineComposer extends GuidelineComposer
{
    protected function getGuidelineAssist(): GuidelineAssist
    {
        return new LaravelZeroGuidelineAssist($this->project, $this->config);
    }
}
