<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\DatabaseConnections\DockerPublishedPort;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Process;

final readonly class CustomProxyProcessListener
{
    public function resolve(Process $process): CustomProxyUpstream
    {
        if ($process->runtime !== ProcessRuntime::Docker) {
            throw new ResourceOperationException(
                errorCode: 'route.upstream_unresolved',
                message: "Process [{$process->name}] has no single Node-local listener.",
                status: 409,
            );
        }

        $ports = $process->runtime_config['ports'] ?? null;

        if (! is_array($ports) || $ports === []) {
            throw new ResourceOperationException(
                errorCode: 'route.upstream_unresolved',
                message: "Process [{$process->name}] has no single Node-local listener.",
                status: 409,
            );
        }

        $resolved = [];

        foreach ($ports as $spec) {
            if (! is_string($spec)) {
                continue;
            }

            $parsed = DockerPublishedPort::parse($spec);

            if (! $parsed instanceof DockerPublishedPort) {
                continue;
            }

            if (
                $parsed->bindAddress !== null
                && $parsed->bindAddress !== '127.0.0.1'
                && $parsed->bindAddress !== '0.0.0.0'
                && $parsed->bindAddress !== '::1'
                && $parsed->bindAddress !== 'localhost'
            ) {
                continue;
            }

            $resolved[] = new CustomProxyUpstream('127.0.0.1', $parsed->publishedPort);
        }

        if (count($resolved) !== 1) {
            throw new ResourceOperationException(
                errorCode: 'route.upstream_unresolved',
                message: "Process [{$process->name}] has no single Node-local listener.",
                status: 409,
            );
        }

        return $resolved[0];
    }
}
