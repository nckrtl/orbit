<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Commands\GatewayCommand;
use App\Data\GatewayProfile;
use App\Exceptions\GatewayConfigException;
use App\Repositories\GatewayConfigRepository;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\ProgressState;
use App\Support\Console\PromptAborted;
use Laravel\Prompts\ConfirmPrompt;

final class GatewayRemoveCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'gateway:remove
        {name : Local profile name}
        {--force : Remove the active profile and clear the active selection}
        {--yes : Confirm removal without prompting}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove a gateway profile.';

    public function handle(GatewayConfigRepository $repository): int
    {
        $name = $this->input->getArgument('name');

        if (! is_string($name)) {
            return $this->renderGatewayFailure(
                'gateway.profile_invalid',
                'Gateway name must be a string.',
            );
        }

        if (! GatewayProfile::hasValidName($name)) {
            return $this->renderGatewayFailure(
                'gateway.profile_invalid',
                'Gateway profile name is invalid.',
            );
        }

        try {
            if ($repository->find($name) === null) {
                return $this->renderGatewayFailure('gateway.profile_not_found', 'Gateway profile does not exist.');
            }

            if ($repository->active()?->name === $name && $this->option('force') !== true) {
                return $this->renderGatewayFailure('gateway.profile_active', 'Cannot remove the active gateway profile.');
            }
        } catch (GatewayConfigException $exception) {
            return $this->renderRemoveFailure($exception);
        }

        if ($this->option('yes') !== true) {
            if (! $this->consoleMode()->mayPrompt) {
                return $this->renderGatewayFailure('input.confirmation_required', 'Supply --yes to confirm gateway profile removal.');
            }

            try {
                $confirmed = $this->commandPrompts()->run(fn (): ConfirmPrompt => new ConfirmPrompt(
                    label: "Remove gateway profile [{$name}] and its pinned certificate?",
                    default: false,
                ));
            } catch (PromptAborted|ConsoleInterrupted) {
                return $this->renderGatewayFailure('input.cancelled', 'Gateway profile removal cancelled.');
            }

            if ($confirmed !== true) {
                return $this->renderGatewayFailure('input.cancelled', 'Gateway profile removal cancelled.');
            }
        }

        $progress = $this->progressDisplay("Gateway profile: {$name}");
        $progress->admit('remove', 'Remove profile', 'Removing profile', 'Removed profile');

        try {
            $progress->during('remove', fn () => $repository->remove($name, $this->option('force') === true));
        } catch (GatewayConfigException $exception) {
            return $this->renderRemoveFailure($exception);
        }

        $progress->complete('remove', ProgressState::Success);
        $progress->finish("Gateway [{$name}] removed.");

        if ($this->option('json') === true) {
            $this->writeJson(['profile' => $name]);

            return self::SUCCESS;
        }

        return self::SUCCESS;
    }

    private function renderRemoveFailure(GatewayConfigException $exception): int
    {
        $code = $exception->errorCode;

        if ($code === 'gateway.profile_not_found') {
            return $this->renderGatewayFailure($code, 'Gateway profile does not exist.');
        }

        if ($code === 'gateway.profile_active') {
            return $this->renderGatewayFailure($code, 'Cannot remove the active gateway profile.');
        }

        return $this->renderGatewayFailure(
            'gateway.config_invalid',
            'Orbit gateway configuration is invalid.',
        );
    }
}
