<?php

declare(strict_types=1);

use App\Infrastructure\AppDev\AppDevDnsConfigRenderer;
use App\Infrastructure\AppDev\AppDevSiteRepository;

it('renders managed header and terminal newline', function (): void {
    $result = new AppDevDnsConfigRenderer(new AppDevSiteRepository)->render();
    expect($result)->toStartWith('# Managed by Orbit.')->toEndWith("\n");
});
