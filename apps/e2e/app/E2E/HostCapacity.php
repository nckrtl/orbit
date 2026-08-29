<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use RuntimeException;

/**
 * Retain one exact, single-host capacity ledger until exact topology release.
 *
 * @mago-expect lint:cyclomatic-complexity,kan-defect Capacity checks fail closed for each host resource constraint.
 */
final readonly class HostCapacity
{
    private const string LEDGER = 'capacity/incus.json';
    private const int STANDBY_SLOTS = 3;

    public function __construct(
        private AtomicJsonStore $state,
        private StatePaths $paths,
        private OperationId $commandOperation,
        private int $maxVms,
        private ?IncusHost $host = null,
    ) {
        if ($maxVms < (self::STANDBY_SLOTS + count(TopologyProfile::ROLES))) {
            throw new RuntimeException('Incus host capacity cannot fit the standby and one feature topology.');
        }
    }

    public function reserve(string $issue, OperationId $acquisitionOperation): int
    {
        new TopologyTarget($issue);
        $lock = $this->lock();
        try {
            $ledger = $this->ledger();
            $existing = $ledger['reservations'][$issue] ?? null;
            if ($existing !== null) {
                if (($existing['operation_id'] ?? null) !== $acquisitionOperation->value) {
                    throw new RuntimeException('Incus host capacity is retained by another acquisition operation.');
                }

                if (! isset($existing['network_slot'])) {
                    $existing['network_slot'] = $this->nextNetworkSlot($ledger);
                    $ledger['reservations'][$issue] = $existing;
                    $this->state->write(self::LEDGER, $ledger);
                }

                return $existing['network_slot'];
            }
            $slots = count(TopologyProfile::ROLES);
            $used = self::STANDBY_SLOTS + array_sum(array_column($ledger['reservations'], 'slots'));
            if (($used + $slots) > $this->maxVms) {
                throw new RuntimeException('Incus host capacity is exhausted.');
            }
            $networkSlot = $this->nextNetworkSlot($ledger);
            $ledger['reservations'][$issue] = [
                'operation_id' => $acquisitionOperation->value,
                'slots' => $slots,
                'network_slot' => $networkSlot,
            ];
            ksort($ledger['reservations']);
            $this->state->write(self::LEDGER, $ledger);

            return $networkSlot;
        } finally {
            $lock->release();
        }
    }

    public function release(string $issue, OperationId $acquisitionOperation): void
    {
        new TopologyTarget($issue);
        $lock = $this->lock();
        try {
            $ledger = $this->ledger();
            $existing = $ledger['reservations'][$issue] ?? null;
            if ($existing === null) {
                return;
            }
            if (($existing['operation_id'] ?? null) !== $acquisitionOperation->value) {
                throw new RuntimeException('Incus host capacity reservation ownership does not match.');
            }
            unset($ledger['reservations'][$issue]);
            $this->state->write(self::LEDGER, $ledger);
        } finally {
            $lock->release();
        }
    }

    private function lock(): OperationLock
    {
        $lock = new OperationLock($this->paths);
        if (! $lock->acquire('incus-capacity', $this->commandOperation)) {
            throw new RuntimeException('The Incus host capacity ledger is locked.');
        }

        return $lock;
    }

    /** @return array{schema: int, reservations: array<string, array{operation_id: string, slots: int, network_slot?: int}>} */
    private function ledger(): array
    {
        $ledger = $this->state->read(self::LEDGER) ?? ['schema' => 1, 'reservations' => []];
        if (
            ! is_array($ledger)
            || array_keys($ledger) !== ['schema', 'reservations']
            || $ledger['schema'] !== 1
            || ! is_array($ledger['reservations'])
        ) {
            throw new RuntimeException('The Incus host capacity ledger is invalid.');
        }
        /** @var array{schema: int, reservations: array<string, array{operation_id: string, slots: int, network_slot?: int}>} $ledger */
        assert(is_array($ledger['reservations']));
        $networkSlots = [];
        foreach ($ledger['reservations'] as $issue => $reservation) {
            if (
                ! is_string($issue)
                || ! is_array($reservation)
                || ! in_array(
                    array_keys($reservation),
                    [['operation_id', 'slots'], ['operation_id', 'slots', 'network_slot']],
                    true,
                )
                || ! is_string($reservation['operation_id'])
                || preg_match('/\A[a-f0-9]{32}\z/D', $reservation['operation_id']) !== 1
                || $reservation['slots'] !== count(TopologyProfile::ROLES)
                || isset($reservation['network_slot'])
                && (! is_int($reservation['network_slot'])
                || $reservation['network_slot'] < 2
                || $reservation['network_slot'] > 200)
            ) {
                throw new RuntimeException('The Incus host capacity ledger is invalid.');
            }
            if (isset($reservation['network_slot'])) {
                if (isset($networkSlots[$reservation['network_slot']])) {
                    throw new RuntimeException('The Incus host capacity ledger is invalid.');
                }
                $networkSlots[$reservation['network_slot']] = true;
            }
            try {
                new TopologyTarget($issue);
            } catch (\InvalidArgumentException $exception) {
                throw new RuntimeException('The Incus host capacity ledger is invalid.', 0, $exception);
            }
        }

        /** @var array{schema: int, reservations: array<string, array{operation_id: string, slots: int, network_slot?: int}>} $ledger */
        return $ledger;
    }

    /** @param array{schema:int,reservations:array<string,array{operation_id:string,slots:int,network_slot?:int}>} $ledger */
    private function nextNetworkSlot(array $ledger): int
    {
        $used = [1];
        foreach ($ledger['reservations'] as $reservation) {
            if (isset($reservation['network_slot'])) {
                $used[] = $reservation['network_slot'];
            }
        }
        $occupied = $this->occupiedNetworkSlots();
        for ($slot = 2; $slot <= 200; $slot++) {
            if (! in_array($slot, $used, true) && ! isset($occupied[$slot])) {
                return $slot;
            }
        }
        throw new RuntimeException('Incus network slots are exhausted.');
    }

    /** @return array<int, true> */
    private function occupiedNetworkSlots(): array
    {
        if ($this->host === null) {
            return [];
        }

        $occupied = [];
        foreach ($this->host->networks() as $network) {
            $address = $network->config['ipv4.address'] ?? null;
            if ($address === null) {
                continue;
            }
            if (preg_match('/\A10\.232\.([0-9]{1,3})\.(?:0|1)\/24\z/D', $address, $match) !== 1) {
                if (str_starts_with($address, '10.232.')) {
                    throw new RuntimeException(
                        'Incus network inventory contains malformed deterministic IPv4 address.',
                    );
                }
                continue;
            }
            $slot = (int) $match[1];
            if ($slot >= 1 && $slot <= 200) {
                $occupied[$slot] = true;
            }
        }

        return $occupied;
    }
}
