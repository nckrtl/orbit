<?php

declare(strict_types=1);

namespace App\Commands;

use App\Data\GatewayProfile;
use App\Exceptions\GatewayConfigException;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\GatewayFailureRenderer;
use InvalidArgumentException;
use JsonException;
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

/**
 * @mago-expect lint:cyclomatic-complexity The shared boundary handles each command failure category.
 * @mago-expect lint:too-many-methods Shared gateway input, transport, and rendering helpers stay centralized for operator commands.
 */
abstract class GatewayCommand extends Command
{
    #[\Override]
    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (ExceptionInterface $exception) {
            if (! $input->hasParameterOption('--json')) {
                throw $exception;
            }

            $output->writeln(GatewayFailureRenderer::json(
                'input.invalid',
                'Command input is invalid.',
            ));

            return self::FAILURE;
        }
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
     */
    protected function resolveNodeId(GatewayConnector $connector, mixed $reference): ?int
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

        $nodes = $this->send($connector, new ListNodesRequest, NodesResponse::class);

        if (! $nodes instanceof NodesResponse) {
            return null;
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

    protected function send(
        GatewayConnector $connector,
        GatewayRequest $request,
        string $responseClass,
    ): ?object {
        try {
            $response = $this->sendOrThrow($connector, $request, $responseClass);
        } catch (GatewayApiException $exception) {
            GatewayFailureRenderer::write(
                $this,
                $exception->errorCode() ?? 'gateway.request_failed',
                $exception->getMessage(),
                $exception->requestId(),
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
            /** @mago-expect analysis:mixed-assignment Saloon returns DTOs through a mixed boundary. */
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
            /** @mago-expect analysis:mixed-assignment Malformed DTOs still expose untyped response metadata. */
            $metaRequestId = $response->json('meta.request_id');
        } catch (JsonException) {
            $metaRequestId = null;
        }

        return (
            $this->validResponseRequestId($metaRequestId) ?? $this->validResponseRequestId(
                $response->getPsrResponse()->getHeaderLine('X-Orbit-Request-Id'),
            )
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
        $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    protected function renderGatewayFailure(
        string $code,
        string $message,
        ?string $requestId = null,
        ?string $humanMessage = null,
    ): int {
        GatewayFailureRenderer::write($this, $code, $message, $requestId, $humanMessage);

        return self::FAILURE;
    }
}
