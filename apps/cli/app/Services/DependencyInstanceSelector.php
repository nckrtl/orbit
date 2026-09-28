<?php

declare(strict_types=1);

namespace App\Services;

use LogicException;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\AppInstances\ResolveAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\ResolveDirectoryInstanceRequest;
use Orbit\Sdk\Responses\AppInstances\ResolvedAppInstanceResponse;
use Orbit\Sdk\Responses\AppInstances\ResolvedDirectoryInstanceResponse;
use SensitiveParameter;

final readonly class DependencyInstanceSelector
{
    public function select(GatewayConnector $connector, #[SensitiveParameter] ?string $domain = null, bool $all = false): ResolvedAppInstanceResponse|ResolvedDirectoryInstanceResponse|null
    {
        if ($all && $domain !== null) {
            throw new GatewayApiException('Project and all-instance selectors cannot be combined.', errorCode: 'dependencies.target_conflict');
        }
        if ($all) {
            return null;
        }
        if ($domain !== null) {
            return $this->resolveDomain($connector, $domain);
        }

        return $this->resolveDirectory($connector);
    }

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

    public function resolveDomain(GatewayConnector $connector, #[SensitiveParameter] string $domain): ResolvedAppInstanceResponse
    {
        $resolved = $connector->send(new ResolveAppInstanceRequest($domain))->dtoOrFail();
        if (! $resolved instanceof ResolvedAppInstanceResponse) {
            throw new LogicException('Domain resolution returned an unexpected response.');
        }

        return $resolved;
    }
}
