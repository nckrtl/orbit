<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Services\Git\GitRegistrationDiscovery;
use App\Services\Git\GitRegistrationFacts;
use Orbit\Sdk\Requests\AppInstances\RegisterAppInstanceRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceRegistrationResponse;

/** @mago-expect lint:cyclomatic-complexity Registration keeps discovery, confirmation, transport, and output failure paths explicit. */
final class RegisterInstanceCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:register
        {--path= : Existing Git checkout or worktree; defaults to the current directory}
        {--include-worktrees : Adopt the checkout and every linked worktree}
        {--app= : Existing numeric App ID}
        {--app-name= : Confirmed App display name}
        {--app-slug= : Confirmed App slug}
        {--default-branch= : Confirmed App default branch}
        {--name= : Optional non-default AppInstance name}
        {--root= : Confirmed App root or existing-App root override}
        {--hostname= : Optional explicit Route hostname}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Adopt the current Git source as a managed AppInstance.';

    public function handle(
        GitRegistrationDiscovery $git,
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $workingDirectory = getcwd();
        $requestedPath = $this->stringOption('path') ?? ($workingDirectory === false ? '' : $workingDirectory);
        $facts = $git->inspect($requestedPath);

        if (! $facts instanceof GitRegistrationFacts) {
            return $this->renderGatewayFailure(
                'instance.source_invalid',
                'The current path is not a supported Git checkout or worktree.',
            );
        }

        $values = $this->confirmedValues($facts);

        if ($values === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->send(
            $connector,
            new RegisterAppInstanceRequest(
                sourcePath: $facts->path,
                includeWorktrees: $this->option('include-worktrees') === true,
                appId: $values['appId'],
                appName: $values['appName'],
                appSlug: $values['appSlug'],
                defaultBranch: $values['defaultBranch'],
                instanceName: $this->stringOption('name'),
                root: $values['root'],
                hostname: $this->stringOption('hostname'),
            ),
            AppInstanceRegistrationResponse::class,
        );

        if (! $response instanceof AppInstanceRegistrationResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $instance = $response->appInstance;
        $this->info("Instance [{$instance->name}] is {$instance->status}.");
        $this->line("App: {$response->app->slug} (#{$response->app->id})");
        $this->line("Source layout: {$instance->sourceLayout}");
        $this->line("Managed path: {$instance->checkoutPath}");
        $this->line('Effective root: '.($instance->effectiveRoot ?? '-'));
        $this->line('Git state: '.($instance->detached ? 'detached' : $instance->selectedBranch ?? '-'));
        $this->line('Commit: '.($instance->startingCommit ?? '-'));
        $this->line('Route hostname: '.($instance->hostname ?? '-'));
        $this->line("Registered sources: {$response->completedCount}/{$response->sourceCount}");
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }

    /**
     * @return array{appId: ?int, appName: ?string, appSlug: ?string, defaultBranch: ?string, root: ?string}|null
     */
    private function confirmedValues(GitRegistrationFacts $facts): ?array
    {
        $appId = $this->stringOption('app');
        $appIdValue = $appId === null ? null : filter_var($appId, FILTER_VALIDATE_INT, ['options' => [
                'min_range' => 1,
            ]]);

        if ($appId !== null && ! is_int($appIdValue)) {
            $this->renderGatewayFailure('app.id_invalid', 'App ID must be a positive integer.');

            return null;
        }

        $selectedApp = is_int($appIdValue);
        $appValues = $selectedApp ? $this->explicitAppValues() : $this->inferredAppValues($facts);
        $name = $this->stringOption('app-name');

        if (! $this->input->isInteractive()) {
            if (! $selectedApp && ($appValues['branch'] === null || $appValues['root'] === null)) {
                $this->renderGatewayFailure(
                    'instance.registration_values_unresolved',
                    'Non-interactive registration requires unresolved App values as options.',
                );

                return null;
            }

            return [
                'appId' => is_int($appIdValue) ? $appIdValue : null,
                'appName' => $name,
                'appSlug' => $appValues['slug'],
                'defaultBranch' => $appValues['branch'],
                'root' => $appValues['root'],
            ];
        }

        return $this->confirmInteractiveValues(
            $facts,
            is_int($appIdValue) ? $appIdValue : null,
            $name,
            $appValues,
        );
    }

    /**
     * @param array{slug: ?string, branch: ?string, root: ?string} $appValues
     * @return array{appId: ?int, appName: ?string, appSlug: ?string, defaultBranch: ?string, root: ?string}|null
     */
    private function confirmInteractiveValues(
        GitRegistrationFacts $facts,
        ?int $appId,
        ?string $name,
        array $appValues,
    ): ?array {
        $slug = $appValues['slug'];
        $branch = $appValues['branch'];
        $root = $appValues['root'];
        $selectedApp = $appId !== null;

        $this->line("Source: {$facts->path}");
        $this->line("Repository: {$facts->repositoryUrl}");
        $this->line('App slug: '.($slug ?? $facts->slug));
        $this->line('Default branch: '.($branch ?? $facts->defaultBranch ?? 'unresolved'));
        $this->line('Root: '.($root ?? $facts->root ?? 'unresolved'));
        if (! $selectedApp && $branch === null) {
            /** @mago-expect analysis:mixed-assignment Console prompts cross an untyped framework boundary. */
            $branchAnswer = $this->ask('Default branch');
            $branch = is_string($branchAnswer) ? $branchAnswer : null;
        }

        if (! $selectedApp && $root === null) {
            /** @mago-expect analysis:mixed-assignment Console prompts cross an untyped framework boundary. */
            $rootAnswer = $this->ask('Application root');
            $root = is_string($rootAnswer) ? $rootAnswer : null;
        }

        if (! $selectedApp && (! is_string($branch) || $branch === '' || ! is_string($root) || $root === '')) {
            $this->renderGatewayFailure(
                'instance.registration_values_unresolved',
                'Required App values remain unresolved.',
            );

            return null;
        }

        if (! $this->confirm('Transfer this source to Orbit ownership?', true)) {
            $this->renderGatewayFailure('instance.registration_cancelled', 'Registration was cancelled.');

            return null;
        }

        return [
            'appId' => $appId,
            'appName' => $name,
            'appSlug' => $slug,
            'defaultBranch' => $branch,
            'root' => $root,
        ];
    }

    /** @return array{slug: ?string, branch: ?string, root: ?string} */
    private function explicitAppValues(): array
    {
        return [
            'slug' => $this->stringOption('app-slug'),
            'branch' => $this->stringOption('default-branch'),
            'root' => $this->stringOption('root'),
        ];
    }

    /** @return array{slug: ?string, branch: ?string, root: ?string} */
    private function inferredAppValues(GitRegistrationFacts $facts): array
    {
        return [
            'slug' => $this->stringOption('app-slug') ?? $facts->slug,
            'branch' => $this->stringOption('default-branch') ?? $facts->defaultBranch,
            'root' => $this->stringOption('root') ?? $facts->root,
        ];
    }
}
