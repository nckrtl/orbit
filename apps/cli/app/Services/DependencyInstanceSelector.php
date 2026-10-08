<?php

declare(strict_types=1);

namespace App\Services;

use LogicException;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Instances\ResolveDirectoryInstanceRequest;
use Orbit\Sdk\Requests\Instances\ResolveInstanceRequest;
use Orbit\Sdk\Responses\Instances\ResolvedDirectoryInstanceResponse;
use Orbit\Sdk\Responses\Instances\ResolvedInstanceResponse;
use SensitiveParameter;

final readonly class DependencyInstanceSelector
{
    public function resolveDirectory(GatewayConnector $connector): ResolvedDirectoryInstanceResponse
    {
        $current = getcwd();
        $directory = $current === false ? false : realpath($current);
        if ($directory === false || ! is_dir($directory) || ! is_readable($directory)) {
            throw new GatewayApiException('The current directory is unavailable.', errorCode: 'dependencies.directory_unavailable');
        }

        $resolved = $connector->send(new ResolveDirectoryInstanceRequest($directory))->dtoOrFail();
        if (! $resolved instanceof ResolvedDirectoryInstanceResponse) {
            throw new LogicException('Directory resolution returned an unexpected response.');
        }

        return $resolved;
    }

    public function resolveDomain(GatewayConnector $connector, #[SensitiveParameter] string $domain): ResolvedInstanceResponse
    {
        $resolved = $connector->send(new ResolveInstanceRequest($domain))->dtoOrFail();
        if (! $resolved instanceof ResolvedInstanceResponse) {
            throw new LogicException('Domain resolution returned an unexpected response.');
        }

        return $resolved;
    }
}
