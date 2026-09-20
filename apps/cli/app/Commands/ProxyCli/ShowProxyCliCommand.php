<?php

declare(strict_types=1);

namespace App\Commands\ProxyCli;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\ProxyCli\ShowProxyCliProviderRequest;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliAccountResponse;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliProviderResponse;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliWindowResponse;

final class ShowProxyCliCommand extends ProxyCliCommand
{
    #[\Override]
    protected $signature = 'proxycli:show
        {provider : Provider slug such as codex or claude}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show one provider and its accounts.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        if (($blocked = $this->guardExtension()) !== null) {
            return $blocked;
        }

        $provider = $this->stringArgument('provider', 'Provider slug', 'proxycli.provider_required');

        if ($provider === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ShowProxyCliProviderRequest($provider),
            ProxyCliProviderResponse::class,
            ['Show proxycli provider', 'Loading provider pool', 'Loaded provider pool'],
        );

        if (! $response instanceof ProxyCliProviderResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Provider [{$response->provider}].", [
            'Provider' => $response->provider,
            'Windows' => implode(', ', array_map(
                static fn (ProxyCliWindowResponse $window): string => $window->label,
                $response->windows,
            )),
            'Request ID' => $response->requestId,
        ]));
        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Account', 'State', 'Windows', 'Resets'],
            array_map(static fn (ProxyCliAccountResponse $account): array => [
                $account->id,
                $account->disabled ? 'disabled' : ($account->status ?? 'ok'),
                implode(', ', array_map(
                    static fn (ProxyCliWindowResponse $window): string => sprintf(
                        '%s %s%% remaining',
                        $window->label,
                        rtrim(rtrim(number_format($window->remainingPercent, 1), '0'), '.'),
                    ),
                    $account->windows,
                )),
                implode(', ', array_values(array_filter(array_map(
                    static fn (ProxyCliWindowResponse $window): ?string => $window->resetsAt === null
                        ? null
                        : "{$window->label} {$window->resetsAt}",
                    $account->windows,
                )))),
            ], $response->accounts),
            'No accounts in this pool.',
        ));

        return self::SUCCESS;
    }
}
