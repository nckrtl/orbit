<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

final readonly class InstanceEnvironmentSynchronizationSnapshot
{
    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $ownedKeys  Keys Orbit may remove from the workload file.
     */
    public function __construct(
        #[\SensitiveParameter]
        private array $values,
        private array $ownedKeys = [],
    ) {}

    /** @return array<string, string> */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * The keys of a workload file that this synchronization would drop without Orbit owning them.
     *
     * @param  list<string>  $fileKeys
     * @return list<string>
     */
    public function unownedKeysIn(array $fileKeys): array
    {
        $unowned = array_values(array_diff($fileKeys, array_keys($this->values), $this->ownedKeys));
        sort($unowned, SORT_STRING);

        return $unowned;
    }

    public function keyCount(): int
    {
        return count($this->values);
    }

    /** @return array{key_count: int} */
    public function __debugInfo(): array
    {
        return ['key_count' => $this->keyCount()];
    }
}
