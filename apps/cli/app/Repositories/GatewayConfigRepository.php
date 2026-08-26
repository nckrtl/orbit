<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Data\GatewayProfile;
use App\Exceptions\GatewayConfigException;
use JsonException;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final readonly class GatewayConfigRepository
{
    public function __construct(
        private string $path,
    ) {}

    public function add(GatewayProfile $profile): void
    {
        if (
            ! GatewayProfile::hasValidName($profile->name)
            || ! GatewayProfile::hasSafeUrl($profile->url)
            || ! GatewayProfile::hasValidCaPath($profile->caPath)
        ) {
            throw new GatewayConfigException('Gateway profile is invalid.');
        }

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
        if (! GatewayProfile::hasValidName($name)) {
            return null;
        }

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

        if (is_link($this->path)) {
            throw new GatewayConfigException('Orbit gateway configuration is not private.');
        }

        $permissions = fileperms($this->path);
        $owner = fileowner($this->path);
        $effectiveUserId = function_exists('posix_geteuid') ? posix_geteuid() : null;

        if (
            ! is_int($permissions)
            || ($permissions & 0o077) !== 0
            || ! is_int($owner)
            || ! is_int($effectiveUserId)
            || $owner !== $effectiveUserId
        ) {
            throw new GatewayConfigException('Orbit gateway configuration is not private.');
        }

        $size = filesize($this->path);

        if (! is_int($size) || $size > 1_048_576) {
            throw new GatewayConfigException('Orbit gateway configuration is invalid.');
        }

        $contents = file_get_contents($this->path);

        if (! is_string($contents)) {
            throw new GatewayConfigException('Orbit gateway configuration is invalid.');
        }

        try {
            $decoded = json_decode(
                json: $contents,
                associative: false,
                depth: 512,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new GatewayConfigException('Orbit gateway configuration is invalid.', previous: $exception);
        }

        if (! is_object($decoded)) {
            throw new GatewayConfigException('Orbit gateway configuration is invalid.');
        }

        $values = get_object_vars($decoded);

        if (! array_key_exists('active_gateway', $values) || ! array_key_exists('gateways', $values)) {
            throw new GatewayConfigException('Orbit gateway configuration is invalid.');
        }

        $activeGateway = $values['active_gateway'];

        if (
            $activeGateway !== null
            && (! is_string($activeGateway)
            || ! GatewayProfile::hasValidName($activeGateway))
        ) {
            throw new GatewayConfigException('Orbit gateway configuration is invalid.');
        }

        $gateways = $this->gatewayProfiles($values['gateways']);

        if (is_string($activeGateway) && ! array_key_exists($activeGateway, $gateways)) {
            throw new GatewayConfigException('Orbit gateway configuration is invalid.');
        }

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
            throw new GatewayConfigException('Could not update Orbit gateway configuration.');
        }

        if (! chmod(filename: $directory, permissions: 0o700)) {
            throw new GatewayConfigException('Could not update Orbit gateway configuration.');
        }

        $temporaryPath = $this->path.'.tmp.'.bin2hex(random_bytes(8));

        try {
            $json =
                json_encode(
                    $config,
                    JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ).PHP_EOL;

            if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
                throw new GatewayConfigException('Could not update Orbit gateway configuration.');
            }

            if (! chmod(filename: $temporaryPath, permissions: 0o600)) {
                throw new GatewayConfigException('Could not update Orbit gateway configuration.');
            }

            if (! rename($temporaryPath, $this->path)) {
                throw new GatewayConfigException('Could not update Orbit gateway configuration.');
            }
        } catch (JsonException $exception) {
            throw new GatewayConfigException('Could not update Orbit gateway configuration.', previous: $exception);
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
        if (! is_object($value)) {
            throw new GatewayConfigException('Orbit gateway configuration is invalid.');
        }

        /** @var array<string, array<string, mixed>> $profiles */
        $profiles = [];

        foreach (get_object_vars($value) as $name => $profileData) {
            if (! is_object($profileData)) {
                throw new GatewayConfigException('Orbit gateway configuration is invalid.');
            }

            $profile = GatewayProfile::fromArray(
                (string) $name,
                $this->stringKeyedArray(get_object_vars($profileData)),
            );

            if (! $profile instanceof GatewayProfile) {
                throw new GatewayConfigException('Orbit gateway configuration is invalid.');
            }

            $profiles[$profile->name] = $profile->toArray();
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
