<?php

declare(strict_types=1);

namespace App\Commands;

use App\Data\GatewayProfile;
use App\Exceptions\GatewayConfigException;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\GatewayFailureRenderer;
use LaravelZero\Framework\Commands\Command;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** @mago-expect lint:cyclomatic-complexity The shared boundary handles each command failure category. */
abstract class GatewayCommand extends Command
{
    #[\Override]
    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (ExceptionInterface $exception) {
            if (! $input->hasParameterOption('--json')) {
                throw $exception;
            }

            $output->writeln(GatewayFailureRenderer::json(
                'input.invalid',
                'Command input is invalid.',
            ));

            return self::FAILURE;
        }
    }

    protected function gatewayConnector(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): ?GatewayConnector {
        $profile = $this->activeGatewayProfile($repository);

        return $profile instanceof GatewayProfile ? $connectors->make($profile) : null;
    }

    protected function activeGatewayProfile(GatewayConfigRepository $repository): ?GatewayProfile
    {
        try {
            $profile = $repository->active();
        } catch (GatewayConfigException) {
            $this->renderGatewayFailure(
                'gateway.config_invalid',
                'Orbit gateway configuration is invalid.',
            );

            return null;
        }

        if ($profile === null) {
            $this->renderGatewayFailure(
                'gateway.profile_missing',
                'No active gateway profile.',
            );

            return null;
        }

        return $profile;
    }

    protected function positiveId(string $argument, string $label, string $errorCode): ?int
    {
        $id = filter_var(
            $this->argument($argument),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if (! is_int($id)) {
            $this->renderGatewayFailure($errorCode, "{$label} ID must be a positive integer.");

            return null;
        }

        return $id;
    }

    protected function stringArgument(string $argument, string $label, string $errorCode): ?string
    {
        $value = $this->argument($argument);

        if (! is_string($value) || $value === '') {
            $this->renderGatewayFailure($errorCode, "{$label} is required.");

            return null;
        }

        return $value;
    }

    protected function stringOption(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function validPhpVersion(string $version): bool
    {
        if (preg_match('/\A\d+\.\d+\z/D', $version) === 1) {
            return true;
        }

        $this->renderGatewayFailure(
            'php.version_invalid',
            'PHP version must use major.minor format, for example 8.5.',
        );

        return false;
    }

    protected function send(
        GatewayConnector $connector,
        GatewayRequest $request,
        string $responseClass,
    ): ?object {
        try {
            /** @mago-expect analysis:mixed-assignment Saloon returns DTOs through a mixed boundary. */
            $response = $connector->send($request)->dto();
        } catch (GatewayApiException $exception) {
            GatewayFailureRenderer::write(
                $this,
                $exception->errorCode() ?? 'gateway.request_failed',
                $exception->getMessage(),
                $exception->requestId(),
            );

            return null;
        } catch (FatalRequestException) {
            GatewayFailureRenderer::write($this, 'gateway.unreachable', 'Could not reach the gateway.');

            return null;
        }

        if (! is_object($response) || ! $response instanceof $responseClass) {
            GatewayFailureRenderer::write($this, 'gateway.invalid_response', 'Gateway response is invalid.');

            return null;
        }

        return $response;
    }

    /** @param array<string, mixed> $payload */
    protected function writeJson(array $payload): void
    {
        $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    protected function renderGatewayFailure(
        string $code,
        string $message,
        ?string $requestId = null,
        ?string $humanMessage = null,
    ): int {
        GatewayFailureRenderer::write($this, $code, $message, $requestId, $humanMessage);

        return self::FAILURE;
    }
}
