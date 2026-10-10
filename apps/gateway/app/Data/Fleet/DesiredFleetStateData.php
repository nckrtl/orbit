<?php

declare(strict_types=1);

namespace App\Data\Fleet;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * What every machine in the fleet should run for the Gateway's commit (ADR 0202): the CLI release and the
 * pinned agent. `toArray()` is the stored and served form, and `fromArray()` reads it back, so a Gateway release
 * record can keep the state it rolled out. The expected footprint digest per Node joins this record when the
 * footprint re-apply lands; until then the state names only binaries.
 */
#[MapOutputName(SnakeCaseMapper::class)]
final class DesiredFleetStateData extends Data
{
    public function __construct(
        /** The full SHA of the Gateway's Orbit commit, or null when the Gateway version is not a known commit. */
        public ?string $commit,
        public DesiredCliReleaseData $cli,
        public DesiredAgentData $agent,
    ) {}

    /** Whether the CLI release is a fallback: it belongs to a commit this one reaches, not to this commit. */
    public function cliFallback(): bool
    {
        return $this->cli->isAvailable() && $this->commit !== null && $this->cli->commit !== null && $this->cli->commit !== $this->commit;
    }

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $commit = $value['commit'] ?? null;
        $cli = DesiredCliReleaseData::fromArray($value['cli'] ?? null);
        $agent = $value['agent'] ?? null;
        $agentVersion = is_array($agent) ? ($agent['version'] ?? null) : null;
        $agentAssets = is_array($agent) ? FleetReleaseAssetData::listFromArray($agent['assets'] ?? null) : null;

        if (
            ($commit !== null && (! is_string($commit) || preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1))
            || ! $cli instanceof DesiredCliReleaseData
            || ! is_string($agentVersion) || $agentAssets === null
        ) {
            return null;
        }

        return new self($commit, $cli, new DesiredAgentData($agentVersion, $agentAssets));
    }
}
