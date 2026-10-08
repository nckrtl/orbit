<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\PromptAborted;
use InvalidArgumentException;
use Laravel\Prompts\PasswordPrompt;
use Laravel\Prompts\TextPrompt;
use Orbit\Sdk\Requests\ProjectDocuments\ShowProjectDocumentStorageRequest;
use Orbit\Sdk\Requests\ProjectDocuments\UpdateProjectDocumentStorageRequest;
use Orbit\Sdk\Responses\ProjectDocuments\ProjectDocumentStorageResponse;

abstract class ProjectDocumentStorageCommand extends GatewayCommand
{
    protected bool $updateStorage;

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        try {
            $connector = null;
            $request = new ShowProjectDocumentStorageRequest;
            if ($this->updateStorage) {
                $endpoint = $this->stringOption('endpoint');
                $region = $this->stringOption('region');
                $bucket = $this->stringOption('bucket');
                $credentialsRequired = false;
                if ($this->consoleMode()->mayPrompt) {
                    $connector = $this->gatewayConnector($repository, $connectors);
                    if ($connector === null) {
                        return 1;
                    }
                    $current = $this->send($connector, new ShowProjectDocumentStorageRequest, ProjectDocumentStorageResponse::class);
                    if ($current === null) {
                        return 1;
                    }
                    $credentialsRequired = ! $current->storage->credentialsConfigured;
                    if (! $current->storage->configured) {
                        $endpoint ??= $this->requiredSetting('HTTPS storage origin');
                        $region ??= $this->requiredSetting('Signing region');
                        $bucket ??= $this->requiredSetting('Bucket name');
                    }
                }
                $access = $this->stringOption('access-key-id-file');
                $secret = $this->stringOption('secret-access-key-file');
                if (($access === null) !== ($secret === null)) {
                    throw new InvalidArgumentException('Supply both credential files together.');
                }
                $accessBytes = $access === null ? null : $this->secretFile($access);
                $secretBytes = $secret === null ? null : $this->secretFile($secret);
                if ($access === null && $this->consoleMode()->mayPrompt) {
                    $accessBytes = $this->commandPrompts()->run(fn (): PasswordPrompt => new PasswordPrompt($credentialsRequired ? 'Access key ID' : 'Access key ID (empty preserves credentials)', required: $credentialsRequired));
                    if ($accessBytes !== '') {
                        $secretBytes = $this->commandPrompts()->run(fn (): PasswordPrompt => new PasswordPrompt('Secret access key', required: true));
                    } else {
                        $accessBytes = null;
                    }
                }
                if (($accessBytes !== null && ! is_string($accessBytes)) || ($secretBytes !== null && ! is_string($secretBytes))) {
                    throw new InvalidArgumentException('Credential input was cancelled.');
                }
                $request = new UpdateProjectDocumentStorageRequest($endpoint, $region, $bucket, $accessBytes, $secretBytes);
            }
            $connector ??= $this->gatewayConnector($repository, $connectors);
            if ($connector === null) {
                return 1;
            }
            $response = $this->sendWithProgress($connector, $request, ProjectDocumentStorageResponse::class, ['Document storage', 'Sending request', 'Request completed'], dismiss: true);
            if ($response === null) {
                return 1;
            }
            if ($this->option('json') === true) {
                $this->writeJson($response->toArray());
            } else {
                ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Project Document storage', ['Configured' => $response->storage->configured, 'Endpoint' => $response->storage->endpoint, 'Region' => $response->storage->region, 'Bucket' => $response->storage->bucket, 'Credentials configured' => $response->storage->credentialsConfigured, 'Pending cleanup' => $response->storage->pendingCleanupCount, 'Request ID' => $response->requestId]));
            }

            return 0;
        } catch (InvalidArgumentException|PromptAborted $exception) {
            $this->renderGatewayFailure('input.invalid', $exception->getMessage());

            return 2;
        }
    }

    private function requiredSetting(string $label): string
    {
        $value = $this->commandPrompts()->run(fn (): TextPrompt => new TextPrompt($label, required: true));
        if (! is_string($value)) {
            throw new InvalidArgumentException('Storage input was cancelled.');
        }

        return $value;
    }

    private function secretFile(string $path): string
    {
        $bytes = @file_get_contents($path, length: 1026);
        if ($bytes === false) {
            throw new InvalidArgumentException('Cannot read credential input file.');
        }
        $bytes = preg_replace('/\r?\n\z/', '', $bytes) ?? $bytes;
        if ($bytes === '' || strlen($bytes) > 1024 || ! mb_check_encoding($bytes, 'UTF-8')) {
            throw new InvalidArgumentException('Invalid credential input file.');
        }

        return $bytes;
    }
}
