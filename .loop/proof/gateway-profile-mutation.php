<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;

require '/home/orbit/orbit/apps/cli/vendor/autoload.php';

$mode = $argv[1] ?? '';
$targetPin = $argv[2] ?? '';

if (! is_string($targetPin) || ! str_starts_with($targetPin, '/')) {
    exit(64);
}

$repository = new GatewayConfigRepository('/home/orbit/.orbit/config.json');

match ($mode) {
    'prepare-active' => $repository->add(new GatewayProfile('orb169-other', 'https://10.44.0.2')),
    'url' => $repository->add(new GatewayProfile(
        'e2e',
        'https://orb169-replacement-secret.example',
        $targetPin,
    )),
    'pin' => $repository->add(new GatewayProfile(
        'e2e',
        'https://10.44.0.1',
        '/tmp/orb169-replacement-secret-pin.pem',
    )),
    'active' => $repository->use('orb169-other'),
    'identical' => $repository->add(new GatewayProfile('e2e', 'https://10.44.0.1', $targetPin)),
    default => exit(64),
};
