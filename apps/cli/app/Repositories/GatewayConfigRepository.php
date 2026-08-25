<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Data\GatewayProfile;
use App\Exceptions\GatewayConfigException;
use JsonException;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class GatewayConfigRepository
{
    public function __construct(
        private string $path,
    ) {}

    public function add(GatewayProfile $profile): void
    {
        $config = $this->read();
        $config['gateways'][$profile->name] = $profile->toArray();

        if ($config['active_gateway'] === null) {
            $config['active_gateway'] = $profile->name;
        }

        $this->write($config);
    }

    public function use(string $name): void
    {
        $config = $this->read();

        if (! array_key_exists($name, $config['gateways'])) {
            throw new GatewayConfigException("Gateway profile [{$name}] does not exist.");
        }

        $config['active_gateway'] = $name;

        $this->write($config);
    }

    public function active(): ?GatewayProfile
    {
        $config = $this->read();
        $name = $config['active_gateway'];

        if (! is_string($name)) {
            return null;
        }

        return $this->profile($name, $config['gateways'][$name] ?? null);
    }

    public function find(string $name): ?GatewayProfile
    {
        $config = $this->read();

        return $this->profile($name, $config['gateways'][$name] ?? null);
    }

    /**
     * @mago-expect analysis:mixed-assignment JSON decoding starts at an untyped boundary.
     *
     * @return array{active_gateway: ?string, gateways: array<string, array<string, mixed>>}
     */
    private function read(): array
    {
        if (! is_file($this->path)) {
            return [
                'active_gateway' => null,
                'gateways' => [],
            ];
        }

        try {
            $decoded = json_decode(
                json: (string) file_get_contents($this->path),
                associative: true,
                depth: 512,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new GatewayConfigException('Orbit gateway configuration is not valid JSON.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new GatewayConfigException('Orbit gateway configuration must contain a JSON object.');
        }

        $activeGateway = is_string($decoded['active_gateway'] ?? null)
            ? $decoded['active_gateway']
            : null;
        $gateways = $this->gatewayProfiles($decoded['gateways'] ?? null);

        return [
            'active_gateway' => $activeGateway,
            'gateways' => $gateways,
        ];
    }

    /** @param array{active_gateway: ?string, gateways: array<string, array<string, mixed>>} $config */
    private function write(array $config): void
    {
        $directory = dirname($this->path);

        if (
            ! is_dir($directory)
            && ! mkdir(directory: $directory, permissions: 0o700, recursive: true)
            && ! is_dir($directory)
        ) {
            throw new GatewayConfigException("Could not create Orbit directory [{$directory}].");
        }

        $temporaryPath = $this->path.'.tmp';

        try {
            $json = json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

            if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
                throw new GatewayConfigException("Could not write Orbit configuration [{$temporaryPath}].");
            }

            chmod(filename: $temporaryPath, permissions: 0o600);

            if (! rename($temporaryPath, $this->path)) {
                throw new GatewayConfigException("Could not install Orbit configuration [{$this->path}].");
            }
        } catch (JsonException $exception) {
            throw new GatewayConfigException('Could not encode Orbit gateway configuration.', previous: $exception);
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function profile(string $name, mixed $data): ?GatewayProfile
    {
        if (! is_array($data)) {
            return null;
        }

        return GatewayProfile::fromArray($name, $this->stringKeyedArray($data));
    }

    /**
     * @mago-expect analysis:mixed-assignment JSON profile values are validated below.
     *
     * @return array<string, array<string, mixed>>
     */
    private function gatewayProfiles(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $profiles = [];

        foreach ($value as $name => $profile) {
            if (! is_string($name) || ! is_array($profile)) {
                continue;
            }

            $profiles[$name] = $this->stringKeyedArray($profile);
        }

        return $profiles;
    }

    /**
     * @mago-expect analysis:mixed-assignment JSON values remain mixed by design.
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $value): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }

            $result[$key] = $item;
        }

        return $result;
    }
}
