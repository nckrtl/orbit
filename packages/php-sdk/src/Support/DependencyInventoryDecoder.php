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
use Orbit\Sdk\Responses\Dependencies\InstanceDependencyInventoryResponse;
use SensitiveParameter;
use stdClass;

/** @internal */
final class DependencyInventoryDecoder
{
    private const int MAX_BYTES = 33_554_432;

    private function __construct(private ?string $requestId) {}

    public static function guardBody(#[SensitiveParameter] string $body, #[SensitiveParameter] mixed $requestId): void
    {
        if (strlen($body) > self::MAX_BYTES) {
            new self(GatewayRequestId::fromTransport($requestId))->invalid();
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

        try {
            $envelope = json_decode($body, depth: 16, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoder->invalid();
        }

        $envelope = $decoder->object($envelope, ['data', 'meta']);
        $meta = $decoder->object($envelope->meta, ['request_id']);
        $requestId = GatewayRequestId::fromTransport($meta->request_id);
        if ($requestId === null || ($decoder->requestId !== null && $decoder->requestId !== $requestId)) {
            $decoder->invalid();
        }
        $decoder->requestId = $requestId;
        $decoder->uniqueKeys($body);
        $data = $decoder->object($envelope->data, ['instance_id', 'succeeded', 'composer', 'javascript']);
        if (! is_int($data->instance_id) || $data->instance_id < 1 || $data->instance_id !== $instanceId) {
            $decoder->invalid();
        }
        $composer = $decoder->inventory($data->composer, 'composer');
        $javascript = $decoder->inventory($data->javascript, 'npm');
        $succeeded = $composer->succeeded === null || $javascript->succeeded === null
            ? null : $composer->succeeded && $javascript->succeeded;
        if ($data->succeeded !== $succeeded || ($scan && $succeeded === null)) {
            $decoder->invalid();
        }

        return new InstanceDependencyInventoryResponse($instanceId, $succeeded, $composer, $javascript, $requestId);
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

    /** Reject duplicate JSON keys before a decoded overwrite can become inventory. */
    private function uniqueKeys(#[SensitiveParameter] string $body): void
    {
        $stack = [];
        $offset = 0;
        $length = strlen($body);
        while ($offset < $length) {
            $matched = preg_match('/"(?:[^"\\\\]++|\\\\.)*+"|[{}\[\]]/s', $body, $match, PREG_OFFSET_CAPTURE, $offset);
            if ($matched === false) {
                $this->invalid();
            }
            if ($matched === 0) {
                break;
            }
            [$token, $position] = $match[0];
            $offset = $position + strlen($token);
            if ($token === '{' || $token === '[') {
                $stack[] = [];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif (($body[$offset + strspn($body, " \t\r\n", $offset)] ?? null) === ':') {
                $key = json_decode($token, flags: JSON_THROW_ON_ERROR);
                $level = count($stack) - 1;
                if (isset($stack[$level][$key])) {
                    $this->invalid();
                }
                $stack[$level][$key] = true;
            }
        }
    }

    private function invalid(): never
    {
        throw new GatewayApiException('Gateway response contains invalid dependency inventory data.', requestId: $this->requestId);
    }
}
