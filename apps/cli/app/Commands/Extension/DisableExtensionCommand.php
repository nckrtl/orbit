<?php

declare(strict_types=1);

namespace App\Commands\Extension;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\Extensions\GatewayExtensionState;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Extensions\DisableExtensionRequest;
use Orbit\Sdk\Responses\Extensions\ExtensionResponse;
use Throwable;

final class DisableExtensionCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'extension:disable {extension : Extension slug} {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Disable a Gateway extension for every client.';

    public function handle(GatewayConfigRepository $profiles, GatewayConnectorFactory $connectors): int
    {
        $extension = (string) $this->argument('extension');
        if (! in_array($extension, ['tasks', 'proxycli'], true)) {
            return $this->renderGatewayFailure('extension.unknown', 'Unknown Orbit extension.');
        }

        try {
            $profile = $this->activeGatewayProfile($profiles);
            if ($profile === null) {
                return self::FAILURE;
            }
            $response = $this->sendOrThrow($connectors->make($profile), new DisableExtensionRequest($extension), ExtensionResponse::class);
        } catch (Throwable $exception) {
            return $this->renderRequestFailure($exception, 'Could not disable the extension.');
        }
        GatewayExtensionState::reset();
        if ($this->option('json') === true) {
            $this->writeJson(['extension' => $extension, 'enabled' => $response->enabled]);
        } else {
            ConsoleWriter::write($this->output, "Orbit extension [{$extension}] is disabled.\n");
        }

        return self::SUCCESS;
    }
}
