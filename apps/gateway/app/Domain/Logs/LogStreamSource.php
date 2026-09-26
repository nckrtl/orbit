<?php

declare(strict_types=1);

namespace App\Domain\Logs;

use App\Domain\Nodes\Storage\StoragePath;
use InvalidArgumentException;

/**
 * The one source a live log stream reads, as the Gateway resolved it from its own records. A client
 * never names a source: it names an Instance or a Process. The agent checks every field again.
 */
final readonly class LogStreamSource
{
    public const string UNIT = '/\Aorbit-process-[1-9][0-9]*-[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.service\z/D';

    public const string CONTAINER = '/\Aorbit-process-([1-9][0-9]*)-[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D';

    private function __construct(
        public LogSourceType $type,
        public ?string $path = null,
        public ?string $unit = null,
        public ?string $container = null,
        public ?int $processId = null,
    ) {}

    public static function laravel(StoragePath $checkout): self
    {
        return new self(LogSourceType::Laravel, path: $checkout->value);
    }

    public static function journal(string $unit): self
    {
        if (preg_match(self::UNIT, $unit) !== 1) {
            throw new InvalidArgumentException('A journal log source needs an Orbit Process unit name.');
        }

        return new self(LogSourceType::Journal, unit: $unit);
    }

    public static function docker(string $container, int $processId): self
    {
        if (preg_match(self::CONTAINER, $container, $matches) !== 1 || (int) $matches[1] !== $processId) {
            throw new InvalidArgumentException('A Docker log source needs the Orbit container name of its Process.');
        }

        return new self(LogSourceType::Docker, container: $container, processId: $processId);
    }

    /**
     * Rebuilds a stored source, or returns null when it does not pass the same rules again.
     *
     * @param  array<array-key, mixed>  $source
     */
    public static function fromArray(array $source): ?self
    {
        try {
            return match ($source['type'] ?? null) {
                LogSourceType::Laravel->value => is_string($source['path'] ?? null) && ($path = StoragePath::tryParse($source['path'])) !== null
                    ? self::laravel($path)
                    : null,
                LogSourceType::Journal->value => is_string($source['unit'] ?? null) ? self::journal($source['unit']) : null,
                LogSourceType::Docker->value => is_string($source['container'] ?? null) && is_int($source['process_id'] ?? null)
                    ? self::docker($source['container'], $source['process_id'])
                    : null,
                default => null,
            };
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @return array{type: string, path?: string, unit?: string, container?: string, process_id?: int} */
    public function toArray(): array
    {
        return match ($this->type) {
            LogSourceType::Laravel => ['type' => $this->type->value, 'path' => (string) $this->path],
            LogSourceType::Journal => ['type' => $this->type->value, 'unit' => (string) $this->unit],
            LogSourceType::Docker => ['type' => $this->type->value, 'container' => (string) $this->container, 'process_id' => (int) $this->processId],
        };
    }
}
