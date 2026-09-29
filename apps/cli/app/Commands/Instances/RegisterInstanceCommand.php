<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Services\Git\GitRegistrationDiscovery;
use App\Services\Git\GitRegistrationFacts;
use App\Services\Git\GitRepositoryOriginPolicy;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\ProgressState;
use App\Support\Console\PromptAborted;
use App\Support\Console\TerminalText;
use App\Support\GatewayFailureRenderer;
use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\TextPrompt;
use Orbit\Sdk\Requests\Instances\RegisterInstanceRequest;
use Orbit\Sdk\Responses\Instances\InstanceRegistrationResponse;

final class RegisterInstanceCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:register
        {--path= : Existing Git checkout or worktree; defaults to the current directory}
        {--include-worktrees : Adopt the checkout and every linked worktree}
        {--project= : Existing numeric Project ID}
        {--project-name= : Confirmed Project display name}
        {--project-slug= : Confirmed Project slug}
        {--default-branch= : Confirmed Project default branch}
        {--name= : Optional non-default Instance name}
        {--root= : Confirmed Project root or existing-Project root override}
        {--domain= : Optional explicit Route domain}
        {--yes : Confirm source ownership transfer without prompting}
        {--setup : Run the Project setup steps after adoption}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Adopt the current Git source as a managed Instance.';

    public function handle(
        GitRegistrationDiscovery $git,
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $workingDirectory = getcwd();
        $requestedPath = $this->stringOption('path') ?? ($workingDirectory === false ? '' : $workingDirectory);
        $inspection = $this->progressDisplay('Inspect source');
        $inspection->admit('inspect', 'Inspect source', 'Inspecting source', 'Inspected source');
        $facts = $inspection->during('inspect', fn (): ?GitRegistrationFacts => $git->inspect($requestedPath));
        $validSource = $facts instanceof GitRegistrationFacts && GitRepositoryOriginPolicy::isSafe($facts->repositoryUrl);
        $inspection->complete('inspect', $validSource ? ProgressState::Success : ProgressState::Failure);
        $inspection->finish($validSource ? 'Source inspected.' : 'Source inspection failed.');

        if (! $facts instanceof GitRegistrationFacts) {
            return $this->renderGatewayFailure(
                'instance.source_invalid',
                'The current path is not a supported Git checkout or worktree.',
            );
        }

        if (! GitRepositoryOriginPolicy::isSafe($facts->repositoryUrl)) {
            return $this->renderGatewayFailure(
                'instance.source_invalid',
                'The current path is not a supported Git checkout or worktree.',
            );
        }

        try {
            $values = $this->confirmedValues($facts);
        } catch (PromptAborted|ConsoleInterrupted) {
            return $this->renderGatewayFailure('instance.registration_cancelled', 'Registration was cancelled.');
        }

        if ($values === null) {
            return self::FAILURE;
        }

        if (! $this->confirmOwnership($facts)) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new RegisterInstanceRequest(
                sourcePath: $facts->path,
                includeWorktrees: $this->option('include-worktrees') === true,
                projectId: $values['projectId'],
                projectName: $values['projectName'],
                projectSlug: $values['projectSlug'],
                defaultBranch: $values['defaultBranch'],
                instanceName: $this->stringOption('name'),
                root: $values['root'],
                domain: $this->stringOption('domain'),
                setup: $this->option('setup') === true,
            ),
            InstanceRegistrationResponse::class,
            ['Register source', 'Registering source', 'Registered source'],
        );

        if (! $response instanceof InstanceRegistrationResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $instance = $response->instance;
        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Instance: {$instance->name}", [
            'ID' => $instance->id,
            'Status' => $instance->status,
            'Project' => "{$response->project->slug} (#{$response->project->id})",
            'Source layout' => $instance->sourceLayout,
            'Managed path' => $instance->checkoutPath,
            'Effective root' => $instance->effectiveRoot,
            'Git state' => $instance->detached ? 'detached' : $instance->selectedBranch,
            'Commit' => $instance->startingCommit,
            'Route domain' => $instance->domain,
            'Registered sources' => "{$response->completedCount}/{$response->sourceCount}",
            'Request ID' => $response->requestId,
        ]));

        return self::SUCCESS;
    }

    /**
     * @return array{projectId: ?int, projectName: ?string, projectSlug: ?string, defaultBranch: ?string, root: ?string}|null
     */
    private function confirmedValues(GitRegistrationFacts $facts): ?array
    {
        $projectId = $this->stringOption('project');
        $projectIdValue = $projectId === null ? null : filter_var($projectId, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 1,
        ]]);

        if ($projectId !== null && ! is_int($projectIdValue)) {
            $this->renderGatewayFailure('project.id_invalid', 'Project ID must be a positive integer.');

            return null;
        }

        $selectedProject = is_int($projectIdValue);
        $projectValues = $selectedProject ? $this->explicitProjectValues() : $this->inferredProjectValues();
        $errors = [];
        if ($projectValues['branch'] !== null && self::branchError($projectValues['branch']) !== null) {
            $errors['default_branch'] = ['The default branch is not a valid Git branch name.'];
        }
        if ($projectValues['root'] !== null && self::rootError($projectValues['root']) !== null) {
            $errors['root'] = ['The root must be a normalized relative web path.'];
        }
        if ($errors !== []) {
            GatewayFailureRenderer::write($this, 'validation.failed', 'The request data is invalid.', details: $errors);

            return null;
        }
        $name = $this->stringOption('project-name');
        $nonInteractive = ! $this->consoleMode()->mayPrompt;

        if ($nonInteractive) {
            if (
                ! $selectedProject
                && (($projectValues['branch'] ?? $facts->defaultBranch) === null
                || ($projectValues['root'] ?? $facts->root) === null)
            ) {
                $this->renderGatewayFailure(
                    'instance.registration_values_unresolved',
                    'Non-interactive registration requires unresolved Project values as options.',
                );

                return null;
            }

            return [
                'projectId' => is_int($projectIdValue) ? $projectIdValue : null,
                'projectName' => $name,
                'projectSlug' => $projectValues['slug'],
                'defaultBranch' => $projectValues['branch'],
                'root' => $projectValues['root'],
            ];
        }

        return $this->confirmInteractiveValues(
            $facts,
            is_int($projectIdValue) ? $projectIdValue : null,
            $name,
            $projectValues,
        );
    }

    /**
     * @param  array{slug: ?string, branch: ?string, root: ?string}  $projectValues
     * @return array{projectId: ?int, projectName: ?string, projectSlug: ?string, defaultBranch: ?string, root: ?string}|null
     */
    private function confirmInteractiveValues(
        GitRegistrationFacts $facts,
        ?int $projectId,
        ?string $name,
        array $projectValues,
    ): ?array {
        $slug = $projectValues['slug'] ?? $facts->slug;
        $branch = $projectValues['branch'] ?? $facts->defaultBranch;
        $root = $projectValues['root'] ?? $facts->root;
        $selectedProject = $projectId !== null;

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Source: '.$facts->path, [
            'Repository' => $facts->repositoryUrl,
            'Project slug' => $slug,
            'Default branch' => $branch ?? $facts->defaultBranch ?? 'unresolved',
            'Web root' => $root ?? $facts->root ?? 'unresolved',
        ]));
        if (! $selectedProject && $branch === null) {

            $branchAnswer = $this->commandPrompts()->run(fn (): TextPrompt => new TextPrompt(
                'Default branch', required: true, validate: self::branchError(...),
            ));
            $branch = is_string($branchAnswer) ? $branchAnswer : null;
            $projectValues['branch'] = $branch;
        }

        if (! $selectedProject && $root === null) {

            $rootAnswer = $this->commandPrompts()->run(fn (): TextPrompt => new TextPrompt(
                'Web root', required: true, validate: self::rootError(...),
            ));
            $root = is_string($rootAnswer) ? $rootAnswer : null;
            $projectValues['root'] = $root;
        }

        if (! $selectedProject && (! is_string($branch) || $branch === '' || ! is_string($root) || $root === '')) {
            $this->renderGatewayFailure(
                'instance.registration_values_unresolved',
                'Required Project values remain unresolved.',
            );

            return null;
        }

        return [
            'projectId' => $projectId,
            'projectName' => $name,
            'projectSlug' => $projectValues['slug'],
            'defaultBranch' => $projectValues['branch'],
            'root' => $projectValues['root'],
        ];
    }

    private function confirmOwnership(GitRegistrationFacts $facts): bool
    {
        if ($this->option('yes') === true) {
            return true;
        }
        if (! $this->consoleMode()->mayPrompt) {
            $this->renderGatewayFailure('input.confirmation_required', 'Supply --yes to transfer this source to Orbit ownership.');

            return false;
        }
        $scope = $this->option('include-worktrees') === true ? ' and all linked worktrees' : '';
        try {
            if ($this->commandPrompts()->run(fn (): ConfirmPrompt => new ConfirmPrompt(
                TerminalText::safe("Transfer source [{$facts->path}]{$scope} to Orbit ownership, allowing relocation and later removal?"),
                default: false,
            )) === true) {
                return true;
            }
        } catch (PromptAborted|ConsoleInterrupted) {
            // Registration keeps its existing cancellation code.
        }
        $this->renderGatewayFailure('instance.registration_cancelled', 'Registration was cancelled.');

        return false;
    }

    private static function branchError(string $branch): ?string
    {
        $invalid = $branch === '' || strlen($branch) > 255 || $branch === 'HEAD' || str_starts_with($branch, '-')
            || str_contains($branch, '..') || str_contains($branch, '@{') || str_ends_with($branch, '.')
            || preg_match('//u', $branch) !== 1
            || preg_match('/[\\x00-\\x20\\x7F~^:?*\\[\\\\\\\\]/', $branch) === 1
            || ! array_all(explode('/', $branch), static fn (string $part): bool => $part !== '' && ! str_starts_with($part, '.') && ! str_ends_with($part, '.lock'));

        return $invalid ? 'Enter a valid Git branch name.' : null;
    }

    private static function rootError(string $root): ?string
    {
        $valid = $root !== '' && strlen($root) <= 255 && array_all(explode('/', $root),
            static fn (string $part): bool => $part !== '' && $part !== '.' && $part !== '..' && preg_match('/\\A[A-Za-z0-9._-]+\\z/D', $part) === 1);

        return $valid ? null : 'Enter a normalized relative web path.';
    }

    /** @return array{slug: ?string, branch: ?string, root: ?string} */
    private function explicitProjectValues(): array
    {
        return [
            'slug' => $this->stringOption('project-slug'),
            'branch' => $this->stringOption('default-branch'),
            'root' => $this->stringOption('root'),
        ];
    }

    /** @return array{slug: ?string, branch: ?string, root: ?string} */
    private function inferredProjectValues(): array
    {
        return [
            'slug' => $this->stringOption('project-slug'),
            'branch' => $this->stringOption('default-branch'),
            'root' => $this->stringOption('root'),
        ];
    }
}
