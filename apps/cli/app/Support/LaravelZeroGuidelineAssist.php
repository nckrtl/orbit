<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Boost\Install\GuidelineAssist;

final class LaravelZeroGuidelineAssist extends GuidelineAssist
{
    public function artisan(): string
    {
        return 'php orbit';
    }
}
