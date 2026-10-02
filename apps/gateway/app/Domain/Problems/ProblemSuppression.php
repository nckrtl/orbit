<?php

declare(strict_types=1);

namespace App\Domain\Problems;

use App\Models\ProblemFingerprint;

/**
 * Exact fingerprints and source-path prefixes the outer loop neither counts nor files.
 * An empty prefix matches nothing. A prefix matches a source path, not the fingerprint text.
 */
final readonly class ProblemSuppression
{
    /** @param array<string, mixed> $observation */
    public function suppressesSignal(string $fingerprint, array $observation): bool
    {
        if ($this->listsFingerprint($fingerprint)) {
            return true;
        }

        return array_any(
            $this->signalPaths($observation),
            fn (string $path): bool => $this->matchesPrefix($path),
        );
    }

    public function blocksFiling(ProblemFingerprint $row): bool
    {
        return $this->isSuppressed($row) || $this->isUnrecoverable($row);
    }

    /**
     * A shortened log key has no frame path. When a real prefix is configured and the row has no
     * stored source path, the filer cannot tell whether that prefix applies, so it leaves the row unchanged.
     * An empty prefix does not count as configured.
     */
    public function isUnrecoverable(ProblemFingerprint $row): bool
    {
        if ($row->source !== ProblemSource::Log || $this->prefixes() === []) {
            return false;
        }

        if ($this->listsFingerprint($row->fingerprint) || $this->storedSourcePath($row) !== null) {
            return false;
        }

        return $this->isShortenedLog($row->fingerprint);
    }

    /**
     * The app frame path is the text before the first colon. The function may contain colons.
     */
    public function logFramePath(string $frame): ?string
    {
        $colon = strpos($frame, ':');

        if ($colon === false || $colon < 1) {
            return null;
        }

        return substr($frame, 0, $colon);
    }

    private function isSuppressed(ProblemFingerprint $row): bool
    {
        if ($this->listsFingerprint($row->fingerprint)) {
            return true;
        }

        $sourcePath = $this->storedSourcePath($row);

        if ($sourcePath !== null && $this->matchesPrefix($sourcePath)) {
            return true;
        }

        if ($row->source === ProblemSource::Activity) {
            foreach ($this->strings($row->evidence['paths'] ?? null) as $path) {
                if ($this->matchesPrefix($path)) {
                    return true;
                }
            }
        }

        if ($row->source === ProblemSource::Log && $sourcePath === null) {
            $framePath = $this->framePathFromFingerprint($row->fingerprint);

            if ($framePath !== null && $this->matchesPrefix($framePath)) {
                return true;
            }
        }

        return false;
    }

    private function listsFingerprint(string $fingerprint): bool
    {
        return in_array($fingerprint, $this->fingerprints(), true);
    }

    private function matchesPrefix(string $path): bool
    {
        return array_any(
            $this->prefixes(),
            static fn (string $prefix): bool => str_starts_with($path, $prefix),
        );
    }

    /** @return list<string> */
    private function fingerprints(): array
    {
        return $this->configuredStrings('orbit.problems.suppressed_fingerprints');
    }

    /** @return list<string> */
    private function prefixes(): array
    {
        return $this->configuredStrings('orbit.problems.suppressed_path_prefixes');
    }

    /** @return list<string> */
    private function configuredStrings(string $key): array
    {
        $value = config($key);

        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @param  array<string, mixed>  $observation
     * @return list<string>
     */
    private function signalPaths(array $observation): array
    {
        $paths = [];
        $sourcePath = $observation['source_path'] ?? null;

        if (is_string($sourcePath) && $sourcePath !== '') {
            $paths[] = $sourcePath;
        }

        foreach ($this->strings($observation['paths'] ?? null) as $path) {
            $paths[] = $path;
        }

        return $paths;
    }

    private function storedSourcePath(ProblemFingerprint $row): ?string
    {
        $value = $row->evidence['source_path'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function framePathFromFingerprint(string $fingerprint): ?string
    {
        $prefix = ProblemSource::Log->value.'|';

        if (! str_starts_with($fingerprint, $prefix)) {
            return null;
        }

        $pieces = explode('|', substr($fingerprint, strlen($prefix)), 2);

        if (count($pieces) !== 2 || $pieces[0] === '' || $pieces[1] === '') {
            return null;
        }

        return $this->logFramePath($pieces[1]);
    }

    private function isShortenedLog(string $fingerprint): bool
    {
        return preg_match('/\Alog#[0-9a-f]{12}\z/', $fingerprint) === 1;
    }

    /** @return list<string> */
    private function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $strings[] = $value;
            }
        }

        return $strings;
    }
}
