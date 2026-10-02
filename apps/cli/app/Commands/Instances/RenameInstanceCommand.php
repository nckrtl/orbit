<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\RenameInstanceRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;

final class RenameInstanceCommand extends GatewayCommand
{
    use InstanceOutput;

    #[\Override]
    protected $signature = 'instance:rename
        {instance : Numeric Instance ID}
        {--branch= : Branch already checked out on the Node}
        {--domain= : New domain for the Instance Route}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Record a development branch rename and move its Route.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $id = $this->positiveId('instance', 'Instance', 'instance.id_invalid');
        if ($id === null) {
            return self::FAILURE;
        }
        if ($this->option('branch') === '' || $this->option('domain') === '') {
            return $this->renderGatewayFailure('validation.failed', 'Branch and domain must be nonempty when supplied.');
        }
        $branch = $this->stringOption('branch');
        $domain = $this->stringOption('domain');
        if ($branch === null && $domain === null) {
            return $this->renderGatewayFailure('validation.failed', 'Provide --branch or --domain.');
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $response = $this->sendWithProgress(
            $connector,
            new RenameInstanceRequest($id, $branch, $domain),
            InstanceResponse::class,
            ['Rename Instance', 'Renaming Instance', 'Renamed Instance'],
        );
        if (! $response instanceof InstanceResponse) {
            return self::FAILURE;
        }
        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());
        } else {
            $this->writeInstanceDetails($response);
        }

        return self::SUCCESS;
    }
}
