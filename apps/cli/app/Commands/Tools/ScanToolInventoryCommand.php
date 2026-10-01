<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Tools\ScanToolInventoryRequest;
use Orbit\Sdk\Responses\Tools\ToolInventoryManagerResponse;
use Orbit\Sdk\Responses\Tools\ToolInventoryPackageResponse;
use Orbit\Sdk\Responses\Tools\ToolInventoryResponse;

final class ScanToolInventoryCommand extends ToolCommand
{
    #[\Override]
    protected $signature = 'tool:scan {--node= : Numeric target node ID} {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Discover installed packages for a node.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $node = $this->nodeId();
        if ($node === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ScanToolInventoryRequest($node),
            ToolInventoryResponse::class,
            ['Scan installed packages', 'Scanning installed packages', 'Scanned installed packages'],
        );
        if (! $response instanceof ToolInventoryResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Node #{$response->nodeId} observed at {$response->observedAt}.");
        ConsoleWriter::write(
            $this->output,
            $this->humanRenderer()->table(
                ['Manager', 'Scan state'],
                array_map(
                    static fn (ToolInventoryManagerResponse $manager): array => [$manager->manager, $manager->scanState],
                    $response->managers,
                ),
                'No managers returned.',
            ),
        );

        foreach ($response->managers as $manager) {
            if ($manager->scanState === 'complete') {
                continue;
            }

            $this->writeHumanMessage("{$manager->manager} is {$manager->scanState}, so its package list is not an inventory.");
        }

        $packages = [];
        foreach ($response->managers as $manager) {
            if ($manager->scanState !== 'complete') {
                continue;
            }

            foreach ($manager->packages as $package) {
                $packages[] = self::packageRow($package);
            }
        }

        $everyManagerCompleted = array_all(
            $response->managers,
            static fn (ToolInventoryManagerResponse $manager): bool => $manager->scanState === 'complete',
        );
        ConsoleWriter::write(
            $this->output,
            $this->humanRenderer()->table(
                ['Manager', 'Package', 'Kind', 'Version', 'Dependency', 'Tool ID', 'Adoption', 'Block'],
                $packages,
                $everyManagerCompleted ? 'No installed packages.' : 'No packages in the completed scans.',
            ),
        );
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }

    /** @return list<bool|int|string|null> */
    private static function packageRow(ToolInventoryPackageResponse $package): array
    {
        return [
            $package->manager,
            $package->package,
            $package->packageKind,
            $package->installedVersion,
            $package->dependency,
            $package->registered ? $package->toolId : null,
            $package->adoption,
            $package->adoptionBlock,
        ];
    }
}
