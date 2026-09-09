<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * The proof-only fixture inventory one proof staged on every role: each file
 * beside the plan under `.loop/proof/` at the candidate commit, installed
 * root-owned at the fixed guest directory, plus the digest every role reported
 * after installation. A plan references a fixture by its guest path on any node.
 *
 * @mago-expect lint:cyclomatic-complexity,kan-defect Every fixture rule is checked fail-closed in one value.
 */
final readonly class ProofFixtures
{
    public const string GUEST_DIRECTORY = '/var/lib/orbit-e2e/proof';

    public const string HOST_DIRECTORY = '.loop/proof';

    private const array MODES = ['644', '755'];

    private const array KEYS = ['guest_directory', 'files', 'digest', 'roles'];

    /**
     * @param array<string, array{mode:string, sha256:string}> $files Keyed by file name, sorted.
     * @param array<string, string> $roles The digest each role observed, in profile role order.
     */
    public function __construct(
        public array $files,
        public string $digest,
        public array $roles,
    ) {
        $names = array_keys($files);
        $sorted = $names;
        sort($sorted, SORT_STRING);
        if ($names !== $sorted) {
            throw new InvalidArgumentException('The proof fixture inventory must be sorted by file name.');
        }
        foreach ($files as $name => $file) {
            if (
                ! self::isFixturePath((string) $name)
                || ! in_array($file['mode'], self::MODES, true)
                || preg_match('/\A[0-9a-f]{64}\z/D', $file['sha256']) !== 1
            ) {
                throw new InvalidArgumentException("Proof fixture [{$name}] has an invalid name, mode, or digest.");
            }
        }
        $roleKeys = array_keys($roles);
        if (
            preg_match('/\A[0-9a-f]{64}\z/D', $digest) !== 1
            || array_slice($roleKeys, 0, count(TopologyProfile::ROLES)) !== TopologyProfile::ROLES
            || count($roleKeys) !== count(array_unique($roleKeys))
        ) {
            throw new InvalidArgumentException('The proof fixture digest or role inventory is invalid.');
        }
        foreach ($roles as $role => $observed) {
            TopologyNode::assertKey($role);
            if ($observed !== $digest) {
                throw new InvalidArgumentException("Role [{$role}] did not observe the staged proof fixture digest.");
            }
        }
    }

    /** A flat, shell-safe file name; the guest path is `GUEST_DIRECTORY/<name>`. */
    public static function isFixtureName(string $name): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9._-]{0,127}\z/D', $name) === 1 && ! str_contains($name, '..');
    }

    /** A fixture path is one flat, safe name. */
    public static function isFixturePath(string $path): bool
    {
        return self::isFixtureName($path);
    }

    /** The active issue's fixture directory, relative to the repository root. */
    public static function hostDirectory(): string
    {
        return self::HOST_DIRECTORY;
    }

    public static function guestPath(string $name): string
    {
        if (! self::isFixturePath($name)) {
            throw new InvalidArgumentException('The proof fixture path is invalid.');
        }

        return self::GUEST_DIRECTORY.'/'.$name;
    }

    /**
     * The inventory text both sides hash: one `name<TAB>mode<TAB>sha256` line per
     * file in name order, exactly what the guest prints after installation.
     *
     * @param array<string, array{mode:string, sha256:string}> $files
     */
    public static function inventoryText(array $files): string
    {
        $lines = '';
        foreach ($files as $name => $file) {
            $lines .= "{$name}\t{$file['mode']}\t{$file['sha256']}\n";
        }

        return $lines;
    }

    /** @param array<string, array{mode:string, sha256:string}> $files */
    public static function digestOf(array $files): string
    {
        return hash('sha256', self::inventoryText($files));
    }

    /** @return array{guest_directory:string, files:array<string, array{mode:string, sha256:string}>, digest:string, roles:array<string, string>} */
    public function toArray(): array
    {
        return [
            'guest_directory' => self::GUEST_DIRECTORY,
            'files' => $this->files,
            'digest' => $this->digest,
            'roles' => $this->roles,
        ];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (
            array_keys($value) !== self::KEYS
            || $value['guest_directory'] !== self::GUEST_DIRECTORY
            || ! is_array($value['files'])
            || ! is_string($value['digest'])
            || ! is_array($value['roles'])
        ) {
            throw new InvalidArgumentException('The proof fixture schema is invalid.');
        }
        $files = [];
        /** @mago-expect analysis:mixed-assignment Each file entry is validated before it joins the inventory. */
        foreach ($value['files'] as $name => $file) {
            if (
                ! is_string($name)
                || ! is_array($file)
                || array_keys($file) !== ['mode', 'sha256']
                || ! is_string($file['mode'])
                || ! is_string($file['sha256'])
            ) {
                throw new InvalidArgumentException('The proof fixture schema is invalid.');
            }
            $files[$name] = ['mode' => $file['mode'], 'sha256' => $file['sha256']];
        }
        $roles = [];
        /** @mago-expect analysis:mixed-assignment Each role digest is validated before use. */
        foreach ($value['roles'] as $role => $digest) {
            if (! is_string($role) || ! is_string($digest)) {
                throw new InvalidArgumentException('The proof fixture schema is invalid.');
            }
            $roles[$role] = $digest;
        }

        return new self($files, $value['digest'], $roles);
    }
}
