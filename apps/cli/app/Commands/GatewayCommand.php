<?php

declare(strict_types=1);

namespace App\Commands;

use App\Data\GatewayProfile;
use App\Exceptions\GatewayConfigException;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\CommandPrompts;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\ConsoleMode;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\HumanRenderer;
use App\Support\Console\InterruptIntent;
use App\Support\Console\ProgressDisplay;
use App\Support\Console\ProgressOutcome;
use App\Support\Console\ProgressState;
use App\Support\Console\PromptAborted;
use App\Support\Console\PromptContext;
use App\Support\Console\SpinnerDisplay;
use App\Support\Console\TerminalText;
use App\Support\GatewayFailureRenderer;
use Closure;
use InvalidArgumentException;
use JsonException;
use Laravel\Prompts\ConfirmPrompt;
use LaravelZero\Framework\Commands\Command;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Responses\Nodes\NodesResponse;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Response;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

abstract class GatewayCommand extends Command
{
    #[\Override]
    public function run(InputInterface $input, OutputInterface $output): int
    {
        return InterruptIntent::run(function () use ($input, $output): int {
            try {
                $status = PromptContext::preserve(fn (): int => parent::run($input, $output));
            } catch (PromptAborted $exception) {
                if (($cancelled = InterruptIntent::exitStatus()) !== null) {
                    return $cancelled;
                }

                if ($input->hasParameterOption('--json', true)) {
                    ConsoleWriter::write($output, GatewayFailureRenderer::json('input.invalid', $exception->getMessage())."\n");
                } else {
                    ConsoleWriter::write($output, new HumanRenderer(ConsoleMode::detect($input, $output))->failure($exception->getMessage()));
                }

                $status = self::FAILURE;
            } catch (ConsoleInterrupted $exception) {
                return $exception->getCode();
            } catch (ExceptionInterface $exception) {
                if (($cancelled = InterruptIntent::exitStatus()) !== null) {
                    return $cancelled;
                }

                if (! $input->hasParameterOption('--json', true)) {
                    throw $exception;
                }

                $message = trim($exception->getMessage());

                ConsoleWriter::write($output, GatewayFailureRenderer::json(
                    'input.invalid',
                    $message !== '' ? $message : 'Command input is invalid.',
                )."\n");

                $status = self::FAILURE;
            } catch (Throwable $exception) {
                if (($cancelled = InterruptIntent::exitStatus()) !== null) {
                    return $cancelled;
                }

                throw $exception;
            }

            return InterruptIntent::exitStatus() ?? $status;
        });
    }

    /** Resolve after framework setup and input binding, using the selected stream. */
    protected function consoleMode(?OutputInterface $output = null): ConsoleMode
    {
        return ConsoleMode::detect($this->input, $output ?? $this->output,
            machine: $this->getDefinition()->hasOption('json') && $this->option('json') === true);
    }

    protected function commandPrompts(): CommandPrompts
    {
        return new CommandPrompts($this->consoleMode(), $this->output);
    }

    protected function humanRenderer(): HumanRenderer
    {
        return new HumanRenderer($this->consoleMode());
    }

    protected function progressDisplay(string $title): ProgressDisplay
    {
        return new ProgressDisplay($this->consoleMode(), $this->output, $title);
    }

    protected function spinnerDisplay(): SpinnerDisplay
    {
        return new SpinnerDisplay($this->consoleMode(), $this->output);
    }

    protected function gatewayConnector(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): ?GatewayConnector {
        $profile = $this->activeGatewayProfile($repository);

        return $profile instanceof GatewayProfile ? $connectors->make($profile) : null;
    }

    protected function activeGatewayProfile(GatewayConfigRepository $repository): ?GatewayProfile
    {
        try {
            $profile = $repository->active();
        } catch (GatewayConfigException) {
            $this->renderGatewayFailure(
                'gateway.config_invalid',
                'Orbit gateway configuration is invalid.',
            );

            return null;
        }

        if ($profile === null) {
            $this->renderGatewayFailure(
                'gateway.profile_missing',
                'No active gateway profile.',
            );

            return null;
        }

        return $profile;
    }

    protected function positiveId(string $argument, string $label, string $errorCode): ?int
    {
        $id = filter_var(
            $this->argument($argument),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if (! is_int($id)) {
            $this->renderGatewayFailure($errorCode, "{$label} ID must be a positive integer.");

            return null;
        }

        return $id;
    }

    /**
     * Resolves one node reference given as a numeric ID or a registered node name.
     *
     * Pass an already-fetched $nodes to resolve a name without listing nodes again.
     */
    protected function resolveNodeId(GatewayConnector $connector, mixed $reference, ?NodesResponse $nodes = null): ?int
    {
        if (is_int($reference)) {
            $reference = (string) $reference;
        }

        if (! is_string($reference) || trim($reference) === '') {
            $this->renderGatewayFailure('node.reference_required', 'Node ID or name is required.');

            return null;
        }

        $reference = trim($reference);

        if (preg_match('/\A-?[0-9]+\z/D', $reference) === 1) {
            $id = filter_var($reference, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if (! is_int($id)) {
                $this->renderGatewayFailure('node.id_invalid', 'Node ID must be a positive integer.');

                return null;
            }

            return $id;
        }

        if ($nodes === null) {
            $fetchedNodes = $this->send($connector, new ListNodesRequest, NodesResponse::class);

            if (! $fetchedNodes instanceof NodesResponse) {
                return null;
            }

            $nodes = $fetchedNodes;
        }

        foreach ($nodes->nodes as $node) {
            if ($node->name === $reference) {
                return $node->id;
            }
        }

        $this->renderGatewayFailure('node.not_found', "Node [{$reference}] is not registered.");

        return null;
    }

    protected function stringArgument(string $argument, string $label, string $errorCode): ?string
    {
        $value = $this->argument($argument);

        if (! is_string($value) || $value === '') {
            $this->renderGatewayFailure($errorCode, "{$label} is required.");

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

        $this->renderGatewayFailure(
            'php.version_invalid',
            'PHP version must use major.minor format, for example 8.5.',
        );

        return false;
    }

    protected function writeHumanMessage(string $message): void
    {
        if (! $this->consoleMode()->machine) {
            ConsoleWriter::write($this->output, implode("\n", TerminalText::wrap(
                TerminalText::safe($message),
                $this->consoleMode()->columns,
            ))."\n");
        }
    }

    /**
     * @param  array{string, string, string}  $labels  Waiting, running and completed labels.
     * @param  null|Closure(object): (ProgressState|ProgressOutcome)  $resultState  Validate the product result before settling
     *                                                                              progress. Return a bare ProgressState to keep
     *                                                                              the completed label as the footer; return a
     *                                                                              ProgressOutcome to replace it for a non-success
     *                                                                              state whose completed label would misstate the
     *                                                                              result.
     * @param  bool  $dismiss  Remove the tree once the result arrives, for a command whose result replaces it.
     */
    protected function sendWithProgress(
        GatewayConnector $connector,
        GatewayRequest $request,
        string $responseClass,
        array $labels,
        ?Closure $resultState = null,
        bool $dismiss = false,
    ): ?object {
        [$waiting, $running, $completed] = $labels;
        $progress = $this->progressDisplay($waiting);
        $progress->admit('request', $waiting, $running, $completed);

        try {
            [$response, $outcome] = $progress->during('request', function () use ($connector, $request, $responseClass, $resultState, $completed): array {
                $response = $this->sendOrThrow($connector, $request, $responseClass);
                $result = $resultState !== null ? $resultState($response) : ProgressState::Success;

                return [$response, $result instanceof ProgressOutcome ? $result : new ProgressOutcome($result, $completed)];
            });
        } catch (GatewayApiException $exception) {
            $code = $exception->errorCode() ?? 'gateway.request_failed';
            $this->renderGatewayFailure(
                $code,
                $exception->getMessage(),
                $exception->requestId(),
                details: GatewayFailureRenderer::safeDetails($code, $exception->details()),
            );

            return null;
        }

        $progress->complete('request', $outcome->state);
        if ($dismiss) {
            $progress->dismiss();
        } else {
            $progress->finish($outcome->footer.'.');
        }

        return $response;
    }

    protected function confirmAction(
        string $label,
        string $cancelledMessage,
        string $option = 'yes',
        string $requiredCode = 'input.confirmation_required',
        ?string $requiredMessage = null,
    ): bool {
        if ($this->option($option) === true) {
            return true;
        }

        if (! $this->consoleMode()->mayPrompt) {
            $this->renderGatewayFailure($requiredCode, $requiredMessage ?? "Supply --{$option} to confirm this operation.");

            return false;
        }

        try {
            if ($this->commandPrompts()->run(fn (): ConfirmPrompt => new ConfirmPrompt(TerminalText::safe($label), default: false)) === true) {
                return true;
            }
        } catch (PromptAborted|ConsoleInterrupted) {
            // Cancellation is handled below before the caller admits a mutation.
        }

        $this->renderGatewayFailure('input.cancelled', $cancelledMessage);

        return false;
    }

    protected function send(
        GatewayConnector $connector,
        GatewayRequest $request,
        string $responseClass,
    ): ?object {
        try {
            $response = $this->sendOrThrow($connector, $request, $responseClass);
        } catch (GatewayApiException $exception) {
            $code = $exception->errorCode() ?? 'gateway.request_failed';

            GatewayFailureRenderer::write(
                $this,
                $code,
                $exception->getMessage(),
                $exception->requestId(),
                details: GatewayFailureRenderer::safeDetails($code, $exception->details()),
            );

            return null;
        } catch (FatalRequestException) {
            GatewayFailureRenderer::write($this, 'gateway.unreachable', 'Could not reach the gateway.');

            return null;
        }

        return $response;
    }

    protected function sendOrThrow(
        GatewayConnector $connector,
        GatewayRequest $request,
        string $responseClass,
    ): object {
        try {
            $response = $connector->send($request);
        } catch (FatalRequestException) {
            throw new GatewayApiException('Could not reach the gateway.', 'gateway.unreachable');
        }

        try {
            $dto = $response->dto();
        } catch (InvalidArgumentException $exception) {
            throw new GatewayApiException(
                message: 'Gateway response is invalid.',
                errorCode: 'gateway.invalid_response',
                previous: $exception,
                requestId: $this->responseRequestId($response),
            );
        }

        if (! $dto instanceof $responseClass) {
            throw new GatewayApiException('Gateway response is invalid.', 'gateway.invalid_response');
        }

        return $dto;
    }

    private function responseRequestId(Response $response): ?string
    {
        try {
            $metaRequestId = $response->json('meta.request_id');
        } catch (JsonException) {
            $metaRequestId = null;
        }

        return
            $this->validResponseRequestId($metaRequestId) ?? $this->validResponseRequestId(
                $response->getPsrResponse()->getHeaderLine('X-Orbit-Request-Id'),
            );
    }

    private function validResponseRequestId(mixed $requestId): ?string
    {
        if (
            is_string($requestId)
            && preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD',
                $requestId,
            ) === 1
        ) {
            return $requestId;
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    protected function writeJson(array $payload): void
    {
        ConsoleWriter::write($this->output, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
    }

    /** @param array<string,string> $details */
    protected function renderGatewayFailure(
        string $code,
        string $message,
        ?string $requestId = null,
        ?string $humanMessage = null,
        array $details = [],
    ): int {
        GatewayFailureRenderer::write($this, $code, $message, $requestId, $humanMessage, $details);

        return self::FAILURE;
    }
}
