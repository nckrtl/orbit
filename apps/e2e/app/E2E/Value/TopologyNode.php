<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class TopologyNode
{
    /**
     * @param  list<string>  $roles
     */
    public function __construct(
        public string $key,
        public string $image,
        public TopologyNodePurpose $purpose,
        public int $address,
        public bool $checkout,
        public array $roles,
    ) {
        self::assertKey($key);
        if (
            mb_strlen($image) > 63
            || preg_match('/\Aorbit-base(?:-[A-Za-z0-9._-]+)?\z/D', $image) !== 1
        ) {
            throw new InvalidArgumentException('The topology Node base image is invalid.');
        }
        if ($address < 10 || $address > 254) {
            throw new InvalidArgumentException('The topology Node address position is invalid.');
        }
        $this->assertRoles($roles);
    }

    public static function assertKey(string $key): void
    {
        if (preg_match('/\A[a-z][a-z0-9-]{0,22}\z/D', $key) !== 1) {
            throw new InvalidArgumentException('The topology Node key is invalid.');
        }
    }

    public function wireGuardAddress(): string
    {
        return '10.44.0.'.($this->address - 9);
    }

    /** @param array<array-key, string> $roles */
    private function assertRoles(array $roles): void
    {
        if (! array_is_list($roles) || count($roles) !== count(array_unique($roles))) {
            throw new InvalidArgumentException('The topology Node roles must be a unique ordered list.');
        }
        foreach ($roles as $role) {
            if (preg_match('/\A[a-z][a-z0-9-]{0,31}\z/D', $role) !== 1) {
                throw new InvalidArgumentException('The topology Node role is invalid.');
            }
        }
    }
}
