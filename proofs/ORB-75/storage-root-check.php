<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\Storage\LegacyNodeSettings;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\ProtectedPathCatalog;
use App\Domain\Nodes\Storage\StorageRootResolver;

require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';

$resolver = new StorageRootResolver(new NodeSettingsNormalizer, new ProtectedPathCatalog);
$account = new ManagedUserAccount(user: 'orbit', group: 'orbit', home: '/home/orbit');
$default = $resolver->resolveApps(null, null, $account);
$legacy = $resolver->resolveApps(
    null,
    new LegacyNodeSettings(instancePath: '/srv/orbit/legacy-instances'),
    $account,
);

if ($default->instance->value !== '/home/orbit/apps') {
    fwrite(STDERR, "FAIL: managed-home apps default was not derived.\n");
    exit(1);
}

if ($legacy->instance->value !== '/srv/orbit/legacy-instances') {
    fwrite(STDERR, "FAIL: legacy instance.path was not the compatibility fallback.\n");
    exit(1);
}

echo "apps root: raw absence derives /home/orbit/apps after the legacy fallback\n";
