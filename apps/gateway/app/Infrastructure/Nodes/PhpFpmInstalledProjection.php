<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

final readonly class PhpFpmInstalledProjection
{
    /** The discovery line that names a pool working directory missing on the Node. */
    public const string MissingDirectoryMarker = 'missing-directory';

    /**
     * @param  list<string>  $versions
     * @param  array<string, string>  $configurations
     * @param  list<string>  $missingDirectories
     */
    private function __construct(
        public array $versions,
        public array $configurations,
        public array $missingDirectories = [],
    ) {}

    public static function fromDiscoveryOutput(string $output): self
    {
        $lines = preg_split('/\R/', trim($output));
        $versions = [];
        $configurations = [];
        $missingDirectories = [];

        foreach (is_array($lines) ? $lines : [] as $line) {
            $parts = explode("\t", $line, limit: 2);
            $version = $parts[0];

            if ($version === self::MissingDirectoryMarker && count($parts) === 2 && str_starts_with($parts[1], '/')) {
                $missingDirectories[] = $parts[1];

                continue;
            }

            if (preg_match('/\A[0-9]+\.[0-9]+\z/', $version) !== 1) {
                continue;
            }

            $versions[] = $version;

            if (count($parts) !== 2) {
                continue;
            }

            $configuration = base64_decode($parts[1], strict: true);

            if ($configuration === false) {
                continue;
            }

            $configurations[$version] = $configuration;
        }

        return new self(
            versions: array_values(array_unique($versions)),
            configurations: $configurations,
            missingDirectories: array_values(array_unique($missingDirectories)),
        );
    }

    /** @return list<array{pool: string, version: string}> */
    public function pools(string $pattern): array
    {
        $pools = [];

        foreach ($this->configurations as $version => $configuration) {
            $matches = [];
            preg_match_all($pattern, $configuration, $matches);

            foreach ($matches[1] ?? [] as $pool) {
                $pools[] = ['pool' => $pool, 'version' => $version];
            }
        }

        return $pools;
    }

    /**
     * The installed pools whose `chdir` names a directory that discovery found missing. PHP-FPM refuses to
     * start while any pool names a missing `chdir`.
     *
     * @return list<array{pool: string, version: string, directory: string}>
     */
    public function poolsWithMissingDirectories(): array
    {
        $pools = [];

        foreach ($this->configurations as $version => $configuration) {
            $pool = null;

            foreach (preg_split('/\R/', $configuration) ?: [] as $line) {
                $header = [];

                if (preg_match('/\A\[([^\]]+)\]\z/', $line, $header) === 1) {
                    $pool = $header[1];

                    continue;
                }

                $directory = [];

                if (
                    is_string($pool)
                    && preg_match('/\Achdir = (\/.*)\z/', $line, $directory) === 1
                    && in_array($directory[1], $this->missingDirectories, strict: true)
                ) {
                    $pools[] = ['pool' => $pool, 'version' => (string) $version, 'directory' => $directory[1]];
                }
            }
        }

        return $pools;
    }

    public function previousConfiguration(string $version): string
    {
        return $this->configurations[$version] ?? '';
    }
}
