<?php

declare(strict_types=1);

namespace App\Commands;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use LaravelZero\Framework\Commands\Command;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Saloon\Exceptions\Request\FatalRequestException;

abstract class GatewayCommand extends Command
{
    protected function gatewayConnector(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): ?GatewayConnector {
        $profile = $repository->active();

        if ($profile === null) {
            $this->error('No active gateway profile.');

            return null;
        }

        return $connectors->make($profile);
    }

    protected function positiveId(string $argument, string $label): ?int
    {
        $id = filter_var(
            $this->argument($argument),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if (! is_int($id)) {
            $this->error("{$label} ID must be a positive integer.");

            return null;
        }

        return $id;
    }

    protected function stringArgument(string $argument, string $label): ?string
    {
        $value = $this->argument($argument);

        if (! is_string($value) || $value === '') {
            $this->error("{$label} is required.");

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

        $this->error('PHP version must use major.minor format, for example 8.5.');

        return false;
    }

    protected function send(GatewayConnector $connector, GatewayRequest $request): ?object
    {
        try {
            /** @mago-expect analysis:mixed-assignment Saloon returns DTOs through a mixed boundary. */
            $response = $connector->send($request)->dto();
        } catch (GatewayApiException $exception) {
            self::writeGatewayApiException($this, $exception);

            return null;
        } catch (FatalRequestException) {
            $this->error('Could not reach the gateway.');

            return null;
        }

        if (! is_object($response)) {
            $this->error('Gateway response is invalid.');

            return null;
        }

        return $response;
    }

    /** @param array<string, mixed> $payload */
    protected function writeJson(array $payload): void
    {
        $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public static function writeGatewayApiException(Command $command, GatewayApiException $exception): void
    {
        $command->error($exception->getMessage());

        if ($exception->requestId() !== null) {
            $command->line("Request ID: {$exception->requestId()}");
        }
    }
}
