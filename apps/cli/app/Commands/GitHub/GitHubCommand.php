<?php

declare(strict_types=1);

namespace App\Commands\GitHub;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Responses\GitHub\GitHubAppResponse;

abstract class GitHubCommand extends GatewayCommand
{
    protected function connector(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $factory,
    ): ?GatewayConnector {
        return $this->gatewayConnector($repository, $factory);
    }

    protected function writeApp(GitHubAppResponse $app, string $title): void
    {
        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($title, [
            'Name' => $app->name,
            'Slug' => $app->slug,
            'App ID' => $app->appId,
            'Owner' => $app->owner,
            'URL' => $app->url,
        ]));

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Account', 'Type', 'Repositories', 'Suspended'],
            array_map(static fn (array $row): array => [
                $row['account'],
                $row['type'],
                $row['repositories'],
                $row['suspended'] ? 'yes' : 'no',
            ], $app->installations),
            'No installations.',
        ));
    }
}
