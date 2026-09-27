<?php

declare(strict_types=1);

namespace App\Commands\Extension;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\Extensions\GatewayExtensionState;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Extensions\EnableExtensionRequest;
use Orbit\Sdk\Responses\Extensions\ExtensionResponse;
use Throwable;

final class EnableExtensionCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'extension:enable {extension : Extension slug} {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Enable an optional Orbit CLI extension.';

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
            $response = $this->sendOrThrow($connectors->make($profile), new EnableExtensionRequest($extension), ExtensionResponse::class);
        } catch (Throwable $exception) {
            return $this->renderRequestFailure($exception, 'Could not enable the extension.');
        }
        GatewayExtensionState::reset();
        if ($this->option('json') === true) {
            $this->writeJson(['extension' => $extension, 'enabled' => $response->enabled]);
        } else {
            ConsoleWriter::write($this->output, "Orbit extension [{$extension}] is enabled.\n");
        }

        return self::SUCCESS;
    }
}
