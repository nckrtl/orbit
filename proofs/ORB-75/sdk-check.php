<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Clusters\ListClustersRequest;
use Orbit\Sdk\Requests\Clusters\ShowClusterRequest;
use Orbit\Sdk\Responses\Clusters\ClusterResponse;
use Orbit\Sdk\Responses\Clusters\ClustersResponse;

require '/home/orbit/orbit/apps/cli/vendor/autoload.php';

function sdkFail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");

    exit(1);
}

$config = json_decode((string) file_get_contents('/home/orbit/.orbit/config.json'), true);
$active = is_array($config) ? ($config['active_gateway'] ?? null) : null;
$profile = is_string($active) && is_array($config['gateways'][$active] ?? null)
    ? $config['gateways'][$active]
    : [];
$url = $profile['url'] ?? null;
$caPath = $profile['ca_path'] ?? null;

if (! is_string($url) || ! is_string($caPath)) {
    sdkFail('Gateway profile is incomplete.');
}

$connector = new GatewayConnector($url, $caPath);
$mode = $argv[1] ?? '';

if ($mode === 'clusters') {
    $response = $connector->send(new ListClustersRequest)->dto();

    if (! $response instanceof ClustersResponse) {
        sdkFail('Cluster list did not return the typed collection DTO.');
    }

    $byName = [];

    foreach ($response->clusters as $cluster) {
        $byName[$cluster->name] = $cluster;
    }

    if (($byName['orb75-dev'] ?? null)?->tld !== 'orb75') {
        sdkFail('The SDK did not return the normalized Cluster TLD.');
    }

    if (($byName['orb75-prod'] ?? null)?->tld !== null) {
        sdkFail('The SDK did not preserve a null Cluster TLD.');
    }

    echo "sdk: typed Cluster list returned normalized and null TLDs\n";
    exit(0);
}

if ($mode === 'membership') {
    $clusterId = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT);
    $nodeId = filter_var($argv[3] ?? null, FILTER_VALIDATE_INT);

    if (! is_int($clusterId) || ! is_int($nodeId)) {
        sdkFail('Membership IDs are invalid.');
    }

    $response = $connector->send(new ShowClusterRequest($clusterId))->dto();

    if (! $response instanceof ClusterResponse) {
        sdkFail('Cluster show did not return the typed DTO.');
    }

    foreach ($response->nodes as $node) {
        if ($node->id === $nodeId && $node->name === 'app-dev') {
            echo "sdk: typed Cluster membership includes app-dev\n";
            exit(0);
        }
    }

    sdkFail('The SDK Cluster DTO omitted app-dev membership.');
}

sdkFail('Unknown SDK proof mode.');
