<?php

declare(strict_types=1);

namespace App\Commands\ProxyCli;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\ProxyCli\UpdateProxyCliAccountRequest;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliAccountResponse;

final class UpdateProxyCliCommand extends ProxyCliCommand
{
    #[\Override]
    protected $signature = 'proxycli:update
        {account : Auth file name or auth_index}
        {--disabled : Disable the account}
        {--enabled : Enable the account}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Enable or disable one CLIProxyAPI account.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        if (($blocked = $this->guardExtension()) !== null) {
            return $blocked;
        }

        $account = $this->stringArgument('account', 'Account identity', 'proxycli.account_required');

        if ($account === null) {
            return self::FAILURE;
        }

        $disabled = $this->option('disabled') === true;
        $enabled = $this->option('enabled') === true;

        if ($disabled === $enabled) {
            return $this->renderGatewayFailure(
                'proxycli.state_required',
                'Supply exactly one of --disabled or --enabled.',
            );
        }

        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new UpdateProxyCliAccountRequest($account, $disabled),
            ProxyCliAccountResponse::class,
            ['Update proxycli account', 'Updating account status', 'Updated proxycli account'],
        );

        if (! $response instanceof ProxyCliAccountResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Account [{$response->id}].", [
            'Account' => $response->id,
            'Provider' => $response->provider,
            'Label' => $response->label,
            'Disabled' => $response->disabled,
            'Status' => $response->status,
            'Error' => $response->error,
            'Request ID' => $response->requestId,
        ]));

        return self::SUCCESS;
    }
}
