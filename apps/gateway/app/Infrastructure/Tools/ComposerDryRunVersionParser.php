<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\ToolManagerException;
use Closure;

/** @mago-expect lint:cyclomatic-complexity Exact dry-run parsing rejects every ambiguous Composer result. */
final readonly class ComposerDryRunVersionParser
{
    private const int MAX_OUTPUT_LENGTH = 32_768;

    private const int MAX_VERSION_LENGTH = 255;

    private const string PACKAGE_PATTERN = '[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?/[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?';

    /** @param Closure(): ?string $installedFallback */
    public function parse(string $output, string $package, Closure $installedFallback): string
    {
        if ($output === '' || strlen($output) > self::MAX_OUTPUT_LENGTH) {
            throw $this->malformedOutput();
        }

        $lines = preg_split('/\R/', $output);

        if (! is_array($lines)) {
            throw $this->malformedOutput();
        }

        $targetVersions = [];
        $noOperationCount = 0;
        $operationCount = 0;
        $malformedOperation = false;

        foreach ($lines as $line) {
            if ($line === 'Nothing to install, update or remove') {
                $noOperationCount++;

                continue;
            }

            $operation = $this->parseOperation($line);

            if ($operation === null) {
                if (preg_match('/\A\s*-\s+(?:Locking|Installing|Upgrading|Downgrading)(?:\s|\z)/D', $line) === 1) {
                    $malformedOperation = true;
                }

                continue;
            }

            $operationCount++;

            if ($operation['package'] !== $package) {
                continue;
            }

            $targetVersions[] = $operation['version'];
        }

        if ($malformedOperation) {
            throw $this->malformedOutput();
        }

        if (count($targetVersions) === 1 && $noOperationCount === 0) {
            $version = $targetVersions[0];

            if (! $this->isSafeVersion($version)) {
                throw $this->malformedOutput();
            }

            return $version;
        }

        if ($targetVersions !== [] || $noOperationCount !== 1 || $operationCount !== 0) {
            throw $this->malformedOutput();
        }

        $fallback = $installedFallback();

        if (! is_string($fallback) || ! $this->isSafeVersion($fallback)) {
            throw new ToolManagerException(
                step: 'candidate-version',
                message: 'The Composer dry run did not provide a verified installed version.',
            );
        }

        return $fallback;
    }

    /** @return array{package: string, version: string}|null */
    private function parseOperation(string $line): ?array
    {
        $matches = [];
        $simplePattern = '~\A\s*-\s+(?:Locking|Installing)\s+('.self::PACKAGE_PATTERN.')\s+\(([^\s()]+)\)\s*\z~D';

        if (preg_match($simplePattern, $line, $matches) === 1) {
            return ['package' => $matches[1], 'version' => $matches[2]];
        }

        $transitionPattern =
            '~\A\s*-\s+(?:Upgrading|Downgrading)\s+('.self::PACKAGE_PATTERN.')\s+\([^\s()]+\s+=>\s+([^\s()]+)\)\s*\z~D';

        if (preg_match($transitionPattern, $line, $matches) === 1) {
            return ['package' => $matches[1], 'version' => $matches[2]];
        }

        return null;
    }

    private function isSafeVersion(string $version): bool
    {
        return (
            $version !== ''
            && strlen($version) <= self::MAX_VERSION_LENGTH
            && preg_match('/[\x00-\x20\x7F]/', $version) !== 1
        );
    }

    private function malformedOutput(): ToolManagerException
    {
        return new ToolManagerException(
            step: 'candidate-version',
            message: 'The Composer dry run returned ambiguous or malformed output.',
        );
    }
}
