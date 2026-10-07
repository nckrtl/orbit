<?php

declare(strict_types=1);

namespace App\Commands\SelfUpdate;

use App\Commands\GatewayCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Services\SelfUpdate\InstalledVersion;
use App\Services\SelfUpdate\SelfUpdateFailure;
use App\Services\SelfUpdate\SelfUpdateHost;
use App\Services\SelfUpdate\SelfUpdateLock;
use App\Services\SelfUpdate\SelfUpdateOutcome;
use App\Services\SelfUpdate\SelfUpdater;
use App\Services\SelfUpdate\SelfUpdateStep;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\HumanRenderer;
use App\Support\Console\ProgressDisplay;
use App\Support\Console\ProgressOutcome;
use App\Support\Console\ProgressState;
use App\Support\Console\TerminalText;
use LogicException;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\Gateway\ShowDesiredFleetStateRequest;
use Orbit\Sdk\Responses\Gateway\DesiredFleetStateResponse;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SelfUpdateCommand extends GatewayCommand
{
    /** Reasons a step does not apply to this machine. Any other skip leaves the machine `incomplete`. */
    private const array NOT_APPLICABLE_REASONS = ['source_checkout', 'not_a_release_binary', 'platform_unsupported', 'not_managed_node'];

    #[\Override]
    protected $signature = 'self-update
        {--allow-downgrade : Install the Gateway\'s CLI release even when it is older than this orbit, or this orbit is not a release}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Update this machine to the Gateway\'s release: the orbit CLI, and orbit-agent on a managed Node.';

    #[\Override]
    protected $help = 'Reads the desired fleet state from the active Gateway, replaces this orbit binary with the Gateway\'s CLI release after a SHA-256 check, and on a managed Linux Node replaces orbit-agent when it differs from the pin and restarts it. Run it with sudo on a Node. A source checkout is left alone.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
        SelfUpdater $updater,
        SelfUpdateHost $host,
        SelfUpdateLock $lock,
    ): int {
        $profile = $this->activeGatewayProfile($repository);

        if ($profile === null) {
            return self::FAILURE;
        }

        try {
            return $lock->hold($host->lockPath(), fn (): int => $this->update($profile, $connectors, $updater, $host));
        } catch (SelfUpdateFailure $failure) {
            return $this->renderGatewayFailure($failure->errorCode, $failure->getMessage());
        }
    }

    /** Runs under the self-update lock, so no other self-update or agent converge swaps a binary meanwhile. */
    private function update(GatewayProfile $profile, GatewayConnectorFactory $connectors, SelfUpdater $updater, SelfUpdateHost $host): int
    {
        $progress = $this->progressDisplay('Update Orbit');
        $progress->admit('state', 'Read desired state', 'Reading desired state', 'Read desired state');
        $progress->admit('agent', 'Update agent', 'Updating agent', 'Agent up to date');
        $progress->admit('cli', 'Update CLI', 'Updating CLI', 'CLI up to date');

        try {
            $state = $progress->during('state', fn (): DesiredFleetStateResponse => $this->sendOrThrow(
                $connectors->make($profile),
                new ShowDesiredFleetStateRequest,
                DesiredFleetStateResponse::class,
            ));
        } catch (GatewayApiException $exception) {
            return $this->renderApiFailure($exception);
        }

        $progress->complete('state', ProgressState::Success, match (true) {
            $state->cli->isAvailable() => "CLI {$state->cli->version}, agent {$state->agent->version}",
            $state->cli->isPending() => "CLI {$state->cli->version} (pending), agent {$state->agent->version}",
            default => "Agent {$state->agent->version}",
        });

        $version = config('app.version');
        $currentVersion = is_string($version) ? $version : '';
        $allowDowngrade = $this->option('allow-downgrade') === true;

        // The CLI goes last: when its first update turns the plain binary into a link, this process can load no
        // more code from its path.
        $agent = $progress->during('agent', static fn (): SelfUpdateStep => $updater->updateAgent($state));
        $this->settle($progress, 'agent', $agent);

        if ($agent->outcome === SelfUpdateOutcome::Failed) {
            $cli = new SelfUpdateStep('cli', SelfUpdateOutcome::Skipped, reason: 'previous_step_failed');
            $progress->complete('cli', ProgressState::Skipped, $this->skipReason($cli->reason));
        } else {
            self::loadClassesUsedAfterReplacement();
            $cli = $progress->during('cli', static fn (): SelfUpdateStep => $updater->updateCli($state, $currentVersion, $allowDowngrade));
            $this->settle($progress, 'cli', $cli);
        }

        $steps = [$agent, $cli];
        $outcome = $this->outcome($steps);
        $progress->finish(match ($outcome) {
            SelfUpdateOutcome::Failed => 'Update failed.',
            SelfUpdateOutcome::Incomplete => 'Update incomplete.',
            SelfUpdateOutcome::Pending => 'Waiting for the CLI release.',
            SelfUpdateOutcome::Updated => 'Orbit updated.',
            default => 'Orbit is up to date.',
        });

        if ($this->option('json') === true) {
            $this->writeJson([
                'gateway' => $profile->name,
                'commit' => $state->commit,
                'outcome' => $outcome->value,
                'steps' => array_map(static fn (SelfUpdateStep $step): array => $step->toArray(), $steps),
                'request_id' => $state->requestId,
            ]);
        } else {
            foreach ($steps as $step) {
                if ($step->failure !== null) {
                    ConsoleWriter::write($this->output, $this->humanRenderer()->failure($step->failure->getMessage(), code: $step->failure->errorCode));
                }
            }
        }

        $status = $outcome === SelfUpdateOutcome::Failed ? self::FAILURE : self::SUCCESS;

        if ($cli->outcome === SelfUpdateOutcome::Updated && $cli->path === $host->runningBinary()->path) {
            $host->exitAfterReplacingItself($status);
        }

        return $status;
    }

    /**
     * A standalone binary reads the PHAR appended to it by path. After the rename that path holds the new binary,
     * so every class the command still needs is loaded before the CLI step starts.
     */
    private static function loadClassesUsedAfterReplacement(): void
    {
        foreach ([SelfUpdateStep::class, SelfUpdateFailure::class, InstalledVersion::class, ProgressOutcome::class, HumanRenderer::class, ConsoleWriter::class, TerminalText::class] as $class) {
            class_exists($class);
        }
    }

    /** Self-update prints its own result, so it never adds the notice that asks for it. */
    #[\Override]
    protected function announceNewerRelease(InputInterface $input, OutputInterface $output): void {}

    private function settle(ProgressDisplay $progress, string $id, SelfUpdateStep $step): void
    {
        $before = $step->before?->version;
        $after = $step->after?->version;

        match ($step->outcome) {
            SelfUpdateOutcome::Updated => $progress->complete($id, ProgressState::Success, ($before ?? 'unknown').' → '.($after ?? 'unknown')),
            SelfUpdateOutcome::Unchanged => $progress->complete($id, ProgressState::Success, 'Already '.($after ?? 'current').'.'),
            SelfUpdateOutcome::Skipped => $progress->complete($id, ProgressState::Skipped, $this->skipReason($step->reason)),
            SelfUpdateOutcome::Pending => $progress->complete($id, ProgressState::Skipped, 'CLI '.($after ?? 'release').' is not published yet. Run orbit self-update again in a few minutes.'),
            // The failure prints once, below the tree.
            SelfUpdateOutcome::Failed => $progress->complete($id, ProgressState::Failure),
            SelfUpdateOutcome::Incomplete => throw new LogicException('Incomplete is an outcome of the command, not of a step.'),
        };
    }

    private function skipReason(?string $reason): string
    {
        return match ($reason) {
            'source_checkout' => 'This orbit runs from a source checkout. Update it with git pull.',
            'not_a_release_binary' => 'This orbit is a PHAR run by php, not a release binary.',
            'platform_unsupported' => 'No release binary exists for this platform.',
            'not_managed_node' => 'This machine is not a managed Node.',
            'root_required' => 'Run orbit self-update with sudo to replace orbit-agent.',
            'previous_step_failed' => 'Not started, because the agent update failed.',
            null => 'Skipped.',
            default => "The Gateway's CLI release is unavailable: {$reason}.",
        };
    }

    /**
     * `failed` before `incomplete` before `pending` before `updated` before `unchanged`. A step skipped for a
     * reason in INCOMPLETE_REASONS makes the command `incomplete`: it should have run and could not. A step that
     * does not apply here, such as the agent on an operator Mac, does not.
     *
     * @param  list<SelfUpdateStep>  $steps
     */
    private function outcome(array $steps): SelfUpdateOutcome
    {
        $outcomes = array_map(static fn (SelfUpdateStep $step): SelfUpdateOutcome => $step->outcome, $steps);
        $incomplete = array_filter($steps, static fn (SelfUpdateStep $step): bool => $step->outcome === SelfUpdateOutcome::Skipped
            && ! in_array($step->reason, self::NOT_APPLICABLE_REASONS, true));

        return match (true) {
            in_array(SelfUpdateOutcome::Failed, $outcomes, true) => SelfUpdateOutcome::Failed,
            $incomplete !== [] => SelfUpdateOutcome::Incomplete,
            in_array(SelfUpdateOutcome::Pending, $outcomes, true) => SelfUpdateOutcome::Pending,
            in_array(SelfUpdateOutcome::Updated, $outcomes, true) => SelfUpdateOutcome::Updated,
            default => SelfUpdateOutcome::Unchanged,
        };
    }
}
