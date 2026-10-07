<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GreenCommit;
use App\Domain\GitHub\GreenCommitResolver;
use App\Models\GatewayRelease;
use InvalidArgumentException;
use Throwable;

/**
 * Whether a manual deploy went live while a newer green commit existed, so it pins an older commit
 * on purpose ([Pause](/reference/gateway-recovery#pause)). The answer is stored on the release
 * record as the `newest_green` phase: `superseded` with the newer commit, `newest` when no newer
 * green commit existed, or `unknown` when GitHub could not answer. `superseded` and `unknown` pause
 * automatic releases: a pin is never undone on a guess. A manual deploy of the newest green commit
 * hands back to automation.
 */
final readonly class GatewayReleaseSupersession
{
    public function __construct(
        private GatewayReleaseSource $source,
        private GreenCommitResolver $resolver,
    ) {}

    /** @return array{outcome: string, sha?: string, error?: string} */
    public function check(string $sha): array
    {
        try {
            $green = $this->resolver->resolve(
                $this->source->repository(),
                $this->source->branch,
                $this->source->checkName,
                $sha,
                GatewayRelease::failedShas(),
            );
        } catch (GitHubApiException|InvalidArgumentException $exception) {
            return ['outcome' => 'unknown', 'error' => $exception->getMessage()];
        } catch (Throwable $exception) {
            report($exception);

            return ['outcome' => 'unknown', 'error' => 'The newest green commit could not be read.'];
        }

        return $green instanceof GreenCommit ? ['outcome' => 'superseded', 'sha' => $green->sha] : ['outcome' => 'newest'];
    }

    /**
     * Whether a verified record may pin an older commit: a newer green commit existed, or GitHub
     * could not tell. Failing closed costs one resume after a GitHub outage.
     */
    public static function superseded(GatewayRelease $record): bool
    {
        $phase = $record->phases['newest_green'] ?? null;

        return is_array($phase) && in_array($phase['outcome'] ?? null, ['superseded', 'unknown'], true);
    }
}
