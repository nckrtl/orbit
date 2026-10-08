<?php

declare(strict_types=1);

use App\Infrastructure\Gateway\GatewayApplicationPath;

it('uses the configured stable Gateway path when it exists and base_path otherwise', function (): void {
    $directory = sys_get_temp_dir().'/orbit-application-path-'.bin2hex(random_bytes(4));
    mkdir($directory);
    config(['orbit.gateway_checkout' => $directory.'/']);

    expect(GatewayApplicationPath::resolve())->toBe($directory);

    rmdir($directory);

    expect(GatewayApplicationPath::resolve())->toBe(base_path());
});
