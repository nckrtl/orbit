<?php

declare(strict_types=1);

namespace App\Commands\Extension;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayConfigException;
use App\Repositories\GatewayConfigRepository;
use App\Services\Extensions\LocalExtensionState;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ProgressState;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\ProxyCli\DisableProxyCliRequest;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliStatusResponse;

final class DisableExtensionCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'extension:disable {extension : Extension slug} {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Disable an optional Orbit CLI extension.';

    public function handle(
        LocalExtensionState $extensions,
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $factory,
    ): int {
        $extension = $this->argument('extension');

        if (! $extensions->known($extension)) {
            return $this->renderGatewayFailure('extension.unknown', 'Unknown Orbit extension.');
        }

        $progress = $this->progressDisplay("Extension: {$extension}");
        $progress->admit('disable', 'Disable extension', 'Disabling extension', 'Disabled extension');

        if ($extension === 'proxycli' && ! $this->disableFleetFeature($repository, $factory)) {
            return self::FAILURE;
        }

        try {
            $progress->during('disable', fn () => $extensions->disable($extension));
        } catch (GatewayConfigException) {
            return $this->renderGatewayFailure(
                'extension.config_invalid',
                'Orbit extension configuration is invalid or not private.',
            );
        }

        $progress->complete('disable', ProgressState::Success);
        $progress->finish("Orbit extension [{$extension}] is disabled.");

        if ($this->option('json') === true) {
            $this->writeJson(['extension' => $extension, 'enabled' => false]);
        }

        return self::SUCCESS;
    }

    private function disableFleetFeature(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $factory,
    ): bool {
        try {
            $profile = $repository->active();
        } catch (GatewayConfigException $exception) {
            $this->renderGatewayFailure(
                $exception->isPrivacyFailure() ? GatewayConfigException::CONFIG_NOT_PRIVATE : 'gateway.config_invalid',
                $exception->isPrivacyFailure() ? $exception->getMessage() : 'Orbit gateway configuration is invalid.',
            );

            return false;
        }

        if ($profile === null) {
            return true;
        }

        try {
            $response = $this->sendOrThrow(
                $factory->make($profile),
                new DisableProxyCliRequest,
                ProxyCliStatusResponse::class,
            );
        } catch (GatewayApiException $exception) {
            $code = $exception->errorCode() ?? 'gateway.request_failed';
            $this->renderGatewayFailure($code, $exception->getMessage(), $exception->requestId());

            return false;
        }

        return $response instanceof ProxyCliStatusResponse;
    }
}
