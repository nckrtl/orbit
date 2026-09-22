<?php

declare(strict_types=1);

namespace Orbit\Sdk\Support;

use DateTimeImmutable;
use JsonException;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\Dependencies\DependencyGraphResponse;
use Orbit\Sdk\Responses\Dependencies\DependencyInventoryResponse;
use Orbit\Sdk\Responses\Dependencies\DependencyRequirementResponse;
use Orbit\Sdk\Responses\Dependencies\DependencyResolutionResponse;
use Orbit\Sdk\Responses\Dependencies\DependencySnapshotResponse;
use Orbit\Sdk\Responses\Dependencies\DependencySourceResponse;
use Orbit\Sdk\Responses\Dependencies\DependencyUpdateStepResponse;
use Orbit\Sdk\Responses\Dependencies\InstanceDependencyInventoryResponse;
use Orbit\Sdk\Responses\Dependencies\InstanceDependencyUpdateResponse;
use Saloon\Http\Response;
use SensitiveParameter;
use stdClass;

/** @internal */
final class DependencyInventoryDecoder
{
    private const int MAX_BYTES = 33_554_432;

    private const string INVENTORY_MESSAGE = 'Gateway response contains invalid dependency inventory data.';

    private const string UPDATE_MESSAGE = 'Gateway response contains invalid dependency update data.';

    private function __construct(
        private ?string $requestId,
        private readonly string $invalidMessage = self::INVENTORY_MESSAGE,
    ) {}

    public static function guardBody(
        #[SensitiveParameter] string $body,
        #[SensitiveParameter] mixed $requestId,
        bool $update = false,
    ): void {
        if (strlen($body) > self::MAX_BYTES) {
            new self(GatewayRequestId::fromTransport($requestId), $update ? self::UPDATE_MESSAGE : self::INVENTORY_MESSAGE)->invalid();
        }
    }

    public static function decode(
        #[SensitiveParameter] string $body,
        int $instanceId,
        #[SensitiveParameter] mixed $headerRequestId,
        bool $scan,
    ): InstanceDependencyInventoryResponse {
        self::guardBody($body, $headerRequestId);
        $decoder = new self(GatewayRequestId::fromTransport($headerRequestId));
        $data = $decoder->object($decoder->envelope($body)->data, ['instance_id', 'succeeded', 'composer', 'javascript']);

        return $decoder->inventoryResponse($data, $instanceId, $scan);
    }

    public static function decodeFromResponse(
        #[SensitiveParameter] Response $response,
        int $instanceId,
        bool $scan,
    ): InstanceDependencyInventoryResponse {
        return self::decode($response->body(), $instanceId, $response->header('X-Orbit-Request-Id'), $scan);
    }

    public static function decodeUpdate(
        #[SensitiveParameter] Response $response,
        int $instanceId,
    ): InstanceDependencyUpdateResponse {
        $body = $response->body();
        $headerRequestId = $response->header('X-Orbit-Request-Id');
        self::guardBody($body, $headerRequestId, update: true);
        $decoder = new self(GatewayRequestId::fromTransport($headerRequestId), self::UPDATE_MESSAGE);
        $data = $decoder->object(
            $decoder->envelope($body)->data,
            ['instance_id', 'succeeded', 'error_code', 'may_have_mutated', 'composer', 'javascript', 'inventory'],
        );
        if (! is_int($data->instance_id) || $data->instance_id < 1 || $data->instance_id !== $instanceId
            || ! is_bool($data->succeeded) || ! is_bool($data->may_have_mutated)) {
            $decoder->invalid();
        }
        $errorCode = $data->error_code === null ? null : GatewayErrorCode::fromTransport($data->error_code);
        if ($data->error_code !== null && $errorCode === null) {
            $decoder->invalid();
        }
        $composer = $decoder->step($data->composer, 'composer');
        $javascript = $decoder->step($data->javascript, 'npm');
        if ($data->may_have_mutated !== ($composer->mayHaveMutated || $javascript->mayHaveMutated)) {
            $decoder->invalid();
        }
        $inventory = $data->inventory === null
            ? null
            : $decoder->inventoryResponse($data->inventory, $instanceId, true);
        $completed = in_array($composer->status, ['succeeded', 'absent'], true)
            && in_array($javascript->status, ['succeeded', 'absent'], true);
        $expectedSucceeded = $errorCode === null
            && $completed
            && $inventory?->succeeded === true;
        if ($data->succeeded !== $expectedSucceeded) {
            $decoder->invalid();
        }

        return new InstanceDependencyUpdateResponse(
            $instanceId, $data->succeeded, $errorCode, $data->may_have_mutated,
            $composer, $javascript, $inventory, $decoder->requestId ?? $decoder->invalid(),
        );
    }

    private function envelope(#[SensitiveParameter] string $body): stdClass
    {
        try {
            $envelope = json_decode($body, depth: 16, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalid();
        }

        $envelope = $this->object($envelope, ['data', 'meta']);
        $meta = $this->object($envelope->meta, ['request_id']);
        $requestId = GatewayRequestId::fromTransport($meta->request_id);
        if ($requestId === null || ($this->requestId !== null && $this->requestId !== $requestId)) {
            $this->invalid();
        }
        $this->requestId = $requestId;
        if (! JsonObjectKeys::areUnique($body)) {
            $this->invalid();
        }

        return $envelope;
    }

    private function inventoryResponse(#[SensitiveParameter] mixed $value, int $instanceId, bool $scan): InstanceDependencyInventoryResponse
    {
        $data = $this->object($value, ['instance_id', 'succeeded', 'composer', 'javascript']);
        if (! is_int($data->instance_id) || $data->instance_id < 1 || $data->instance_id !== $instanceId) {
            $this->invalid();
        }
        $composer = $this->inventory($data->composer, 'composer');
        $javascript = $this->inventory($data->javascript, 'npm');
        $succeeded = $composer->succeeded === null || $javascript->succeeded === null
            ? null : $composer->succeeded && $javascript->succeeded;
        if ($data->succeeded !== $succeeded || ($scan && $succeeded === null)) {
            $this->invalid();
        }

        return new InstanceDependencyInventoryResponse($instanceId, $succeeded, $composer, $javascript, $this->requestId ?? $this->invalid());
    }

    private function step(#[SensitiveParameter] mixed $value, string $ecosystem): DependencyUpdateStepResponse
    {
        $data = $this->object($value, ['ecosystem', 'status', 'may_have_mutated', 'error_code']);
        if ($data->ecosystem !== $ecosystem || ! is_bool($data->may_have_mutated)
            || ! in_array($data->status, ['succeeded', 'absent', 'failed', 'not_run'], true)) {
            $this->invalid();
        }
        $errorCode = $data->error_code === null ? null : GatewayErrorCode::fromTransport($data->error_code);
        if ($data->error_code !== null && $errorCode === null) {
            $this->invalid();
        }
        $valid = match ($data->status) {
            'succeeded' => $errorCode === null && $data->may_have_mutated,
            'absent', 'not_run' => $errorCode === null && ! $data->may_have_mutated,
            'failed' => $errorCode !== null,
        };
        if (! $valid) {
            $this->invalid();
        }

        return new DependencyUpdateStepResponse($ecosystem, $data->status, $data->may_have_mutated, $errorCode);
    }

    private function inventory(#[SensitiveParameter] mixed $value, string $ecosystem): DependencyInventoryResponse
    {
        $data = $this->object($value, ['ecosystem', 'state', 'succeeded', 'attempted_at', 'error_code', 'snapshot']);
        if ($data->ecosystem !== $ecosystem || ! in_array($data->succeeded, [true, false, null], true)) {
            $this->invalid();
        }
        $attemptedAt = $data->attempted_at === null ? null : $this->date($data->attempted_at);
        $errorCode = $data->error_code === null ? null : GatewayErrorCode::fromTransport($data->error_code);
        if ($data->error_code !== null && $errorCode === null) {
            $this->invalid();
        }
        $snapshot = $data->snapshot === null ? null : $this->snapshot($data->snapshot, $ecosystem);
        $state = match ($data->succeeded) {
            null => 'unknown',
            false => $snapshot === null ? 'unknown' : 'stale',
            true => $snapshot?->graph === null ? 'absent' : 'present',
        };
        if ($data->state !== $state
            || ($data->succeeded === null && ($attemptedAt !== null || $errorCode !== null || $snapshot !== null))
            || ($data->succeeded !== null && $attemptedAt === null)
            || ($data->succeeded === false && $errorCode === null)
            || ($data->succeeded === true && ($errorCode !== null || $snapshot === null))) {
            $this->invalid();
        }

        return new DependencyInventoryResponse($ecosystem, $state, $data->succeeded, $attemptedAt, $errorCode, $snapshot);
    }

    private function snapshot(#[SensitiveParameter] mixed $value, string $ecosystem): DependencySnapshotResponse
    {
        $data = $this->object($value, ['observed_at', 'source', 'graph']);
        $source = $this->object($data->source, ['project_root', 'reference', 'file_hashes', 'format']);
        // PHP serializes an empty hash map as [], while populated maps are JSON objects.
        if (! $source->file_hashes instanceof stdClass && $source->file_hashes !== []) {
            $this->invalid();
        }
        $hashes = (array) $source->file_hashes;
        if (count($hashes) > 64) {
            $this->invalid();
        }
        $validatedHashes = [];
        foreach ($hashes as $name => $hash) {
            if (! is_string($name) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,254}\z/D', $name) !== 1
                || ($hash !== null && (! is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1))) {
                $this->invalid();
            }
            $validatedHashes[$name] = $hash;
        }

        return new DependencySnapshotResponse(
            $this->date($data->observed_at),
            new DependencySourceResponse($this->text($source->project_root), $this->nullableText($source->reference),
                $validatedHashes, $this->nullableText($source->format)),
            $data->graph === null ? null : $this->graph($data->graph, $ecosystem),
        );
    }

    private function graph(#[SensitiveParameter] mixed $value, string $ecosystem): DependencyGraphResponse
    {
        $data = $this->object($value, ['resolutions', 'requirements']);
        $resolutions = [];
        $ids = [];
        foreach ($this->items($data->resolutions, 50_000) as $item) {
            $item = $this->object($item, ['id', 'ecosystem', 'name', 'version', 'regular', 'development', 'source_reference', 'integrity']);
            $id = $this->text($item->id);
            if (isset($ids[$id]) || $item->ecosystem !== $ecosystem) {
                $this->invalid();
            }
            $ids[$id] = true;
            $resolutions[] = new DependencyResolutionResponse($id, $ecosystem, $this->text($item->name),
                $this->text($item->version), $this->boolean($item->regular), $this->boolean($item->development),
                $this->nullableText($item->source_reference), $this->nullableText($item->integrity));
        }
        $requirements = [];
        foreach ($this->items($data->requirements, 200_000) as $item) {
            $item = $this->object($item, ['from', 'to', 'name', 'constraint', 'kind', 'scope', 'optional']);
            $from = $this->nullableText($item->from);
            $to = $this->nullableText($item->to);
            if (($from !== null && ! isset($ids[$from])) || ($to !== null && ! isset($ids[$to]))
                || ! in_array($item->kind, ['dependency', 'peer'], true)
                || ! in_array($item->scope, ['regular', 'development'], true)) {
                $this->invalid();
            }
            $requirements[] = new DependencyRequirementResponse($from, $to, $this->text($item->name),
                $this->text($item->constraint, allowEmpty: true), $item->kind, $item->scope, $this->boolean($item->optional));
        }

        return new DependencyGraphResponse($resolutions, $requirements);
    }

    /** @param list<string> $fields */
    private function object(#[SensitiveParameter] mixed $value, array $fields): stdClass
    {
        if (! $value instanceof stdClass || count(get_object_vars($value)) !== count($fields)) {
            $this->invalid();
        }
        foreach ($fields as $field) {
            if (! property_exists($value, $field)) {
                $this->invalid();
            }
        }

        return $value;
    }

    /** @return list<mixed> */
    private function items(#[SensitiveParameter] mixed $value, int $maximum): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $maximum) {
            $this->invalid();
        }

        return $value;
    }

    private function text(#[SensitiveParameter] mixed $value, bool $allowEmpty = false): string
    {
        if (! is_string($value) || (! $allowEmpty && $value === '') || strlen($value) > 16_384
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || new CredentialRedactor()->redactText($value) !== $value) {
            $this->invalid();
        }

        return $value;
    }

    private function nullableText(#[SensitiveParameter] mixed $value): ?string
    {
        return $value === null ? null : $this->text($value);
    }

    private function boolean(#[SensitiveParameter] mixed $value): bool
    {
        if (! is_bool($value)) {
            $this->invalid();
        }

        return $value;
    }

    private function date(#[SensitiveParameter] mixed $value): string
    {
        $value = $this->text($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        if ($date === false || $date->format(DATE_RFC3339) !== $value || ! str_ends_with($value, '+00:00')) {
            $this->invalid();
        }

        return $value;
    }

    private function invalid(): never
    {
        throw new GatewayApiException($this->invalidMessage, requestId: $this->requestId);
    }
}
