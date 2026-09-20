<?php

declare(strict_types=1);

namespace App\Commands\GitHub;

use App\Repositories\GatewayConfigRepository;
use App\Services\BrowserLauncher;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\ProgressState;
use App\Support\Console\PromptAborted;
use App\Support\Console\TerminalText;
use Laravel\Prompts\TextPrompt;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\GitHub\InstallGitHubAppRequest;
use Orbit\Sdk\Requests\GitHub\ShowGitHubAppRequest;
use Orbit\Sdk\Responses\GitHub\GitHubAppInstallResponse;
use Orbit\Sdk\Responses\GitHub\GitHubAppResponse;

/**
 * Sends the operator's browser to GitHub, first to register the App when the Gateway has none, then
 * to install it on an account. The Gateway receives no webhook, so the command polls until the new
 * installation appears.
 */
final class InstallGitHubAppCommand extends GitHubCommand
{
    private const string DEFAULT_NAME = 'orbit';

    private const string NAME_PATTERN = '/\A[A-Za-z0-9](?:[A-Za-z0-9 ._-]{0,32}[A-Za-z0-9])?\z/D';

    private const string OWNER_PATTERN = '/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?\z/D';

    private const int POLL_SECONDS = 3;

    private const int WAIT_SECONDS = 600;

    #[\Override]
    protected $signature = 'github:app:install
        {--name= : Proposed App name for a first registration}
        {--owner= : GitHub organization that owns the App registration}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Register the GitHub App when the Gateway has none, then install it on a GitHub account.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $owner = $this->stringOption('owner');

        if ($owner !== null && preg_match(self::OWNER_PATTERN, $owner) !== 1) {
            return $this->renderGatewayFailure('github.owner_invalid', 'Owner is not a GitHub account name.');
        }

        $connector = $this->connector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        $registered = $this->registeredApp($connector);

        if ($registered === false) {
            return self::FAILURE;
        }

        $name = $registered === null ? $this->resolveName() : null;

        if ($registered === null && $name === null) {
            return self::FAILURE;
        }

        $step = $this->sendWithProgress(
            $connector,
            new InstallGitHubAppRequest($name, $owner),
            GitHubAppInstallResponse::class,
            ['Start GitHub App install', 'Preparing the GitHub step', 'Prepared the GitHub step'],
        );

        if (! $step instanceof GitHubAppInstallResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true || $this->refusesInteraction()) {
            return $this->pending($step);
        }

        $this->writeHumanMessage($step->step === 'register'
            ? 'Confirm the App on GitHub, then choose the account to install it on.'
            : 'Choose the account or organization to install the App on.');
        $this->writeHumanMessage($step->url);
        app(BrowserLauncher::class)->open($step->url);

        return $this->waitForInstallation($connector, $step);
    }

    /**
     * Waiting for the installation needs no input, so only a machine-readable run and an explicit
     * refusal of interaction skip it. A terminal-less run still waits.
     */
    private function refusesInteraction(): bool
    {
        return $this->input->hasParameterOption(['--no-interaction', '-n'], true);
    }

    /** The stored App, null when the Gateway has none, or false when the request failed. */
    private function registeredApp(GatewayConnector $connector): GitHubAppResponse|null|false
    {
        try {
            $app = $this->sendOrThrow($connector, new ShowGitHubAppRequest, GitHubAppResponse::class);
        } catch (GatewayApiException $exception) {
            if ($exception->errorCode() === 'github.app_missing') {
                return null;
            }

            $this->renderGatewayFailure(
                $exception->errorCode() ?? 'gateway.request_failed',
                $exception->getMessage(),
                $exception->requestId(),
            );

            return false;
        }

        return $app instanceof GitHubAppResponse ? $app : false;
    }

    private function resolveName(): ?string
    {
        $name = $this->stringOption('name');

        if ($name === null && $this->consoleMode()->mayPrompt && $this->option('json') !== true) {
            try {
                $answer = $this->commandPrompts()->run(fn (): TextPrompt => new TextPrompt(
                    TerminalText::safe('GitHub App name'),
                    default: self::DEFAULT_NAME,
                    validate: static fn (string $value): ?string => preg_match(self::NAME_PATTERN, $value) === 1
                        ? null
                        : 'Use 1 to 34 characters: letters, digits, spaces, dots, dashes, or underscores.',
                ));
            } catch (PromptAborted|ConsoleInterrupted) {
                $this->renderGatewayFailure('input.cancelled', 'GitHub App registration cancelled.');

                return null;
            }

            $name = is_string($answer) && $answer !== '' ? $answer : self::DEFAULT_NAME;
        }

        $name ??= self::DEFAULT_NAME;

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            $this->renderGatewayFailure('github.name_invalid', 'App name is not a GitHub App name.');

            return null;
        }

        return $name;
    }

    private function pending(GitHubAppInstallResponse $step): int
    {
        if ($this->option('json') === true) {
            $this->writeJson([
                'status' => 'pending',
                'step' => $step->step,
                'url' => $step->url,
                'request_id' => $step->requestId,
            ]);

            return self::SUCCESS;
        }

        $this->writeHumanMessage('Open this URL in a browser that reaches this Gateway:');
        $this->writeHumanMessage($step->url);
        $this->writeHumanMessage("Request ID: {$step->requestId}");

        return self::SUCCESS;
    }

    private function waitForInstallation(GatewayConnector $connector, GitHubAppInstallResponse $step): int
    {
        $poll = max(0, (int) config('orbit.github.install_poll_seconds', self::POLL_SECONDS));
        $deadline = time() + max(0, (int) config('orbit.github.install_wait_seconds', self::WAIT_SECONDS));
        $progress = $this->progressDisplay('Wait for the installation');
        $progress->admit('install', 'Wait for the installation', 'Waiting for GitHub', 'Installed the App');

        $failure = null;
        $account = $progress->during('install', function () use ($connector, $step, $deadline, $poll, &$failure): ?string {
            while (true) {
                try {
                    $account = $this->newAccount($connector, $step->accounts);
                } catch (GatewayApiException $exception) {
                    $failure = $exception;

                    return null;
                }

                if ($account !== null) {
                    return $account;
                }

                if (time() >= $deadline) {
                    return null;
                }

                if ($poll > 0) {
                    sleep($poll);
                }
            }
        });

        if ($failure instanceof GatewayApiException) {
            $progress->complete('install', ProgressState::Failure);
            $progress->finish('Stopped waiting for the installation.');

            return $this->renderGatewayFailure(
                $failure->errorCode() ?? 'gateway.request_failed',
                $failure->getMessage(),
                $failure->requestId(),
            );
        }

        if ($account === null) {
            $progress->complete('install', ProgressState::Failure);
            $progress->finish('Stopped waiting for the installation.');

            return $this->renderGatewayFailure(
                'github.installation_pending',
                'Stopped waiting before the Gateway saw a new installation. Finish the installation in the browser, then run github:app:show.',
            );
        }

        $progress->complete('install', ProgressState::Success);
        $progress->finish("Installed the App on {$account}.");

        return self::SUCCESS;
    }

    /**
     * The account of an installation that the baseline did not contain, or null while the Gateway
     * reports no new account. A Gateway that still has no App means the operator has not confirmed
     * the registration yet, so the wait continues; any other failure ends it.
     *
     * @param  list<string>  $baseline
     *
     * @throws GatewayApiException
     */
    private function newAccount(GatewayConnector $connector, array $baseline): ?string
    {
        try {
            $app = $this->sendOrThrow($connector, new ShowGitHubAppRequest, GitHubAppResponse::class);
        } catch (GatewayApiException $exception) {
            if ($exception->errorCode() === 'github.app_missing') {
                return null;
            }

            throw $exception;
        }

        if (! $app instanceof GitHubAppResponse) {
            return null;
        }

        foreach ($app->installations as $installation) {
            if (! in_array($installation['account'], $baseline, true)) {
                return $installation['account'];
            }
        }

        return null;
    }
}
