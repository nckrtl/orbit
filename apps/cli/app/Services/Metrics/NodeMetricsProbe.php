<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use Symfony\Component\Process\Process;

/**
 * Reads one synchronous metrics snapshot from this Node's own `/proc`
 * filesystem and `df`. Runs on the managed Node itself, invoked over SSH by
 * the Gateway as `orbit internal:node-metrics`. Per-core busy ratios need two
 * `/proc/stat` samples, so this takes one short internal sample window and
 * stays well under one second end to end.
 */
final class NodeMetricsProbe
{
    private const int SAMPLE_MICROSECONDS = 150_000;

    /** @return array<string, mixed> */
    public function capture(): array
    {
        $first = $this->readCpuTimes();
        usleep(self::SAMPLE_MICROSECONDS);
        $second = $this->readCpuTimes();

        return [
            'cores' => $this->coreBusyRatios($first, $second),
            'memory' => $this->memory(),
            'swap' => $this->swap(),
            'load' => $this->load(),
            'uptime_seconds' => $this->uptimeSeconds(),
            'pressure' => [
                'cpu' => $this->pressureSomeAvg10('/proc/pressure/cpu'),
                'memory' => $this->pressureSomeAvg10('/proc/pressure/memory'),
                'io' => $this->pressureSomeAvg10('/proc/pressure/io'),
            ],
            'disks' => $this->disks(),
        ];
    }

    /**
     * @return list<array{user: int, nice: int, system: int, idle: int, iowait: int, irq: int, softirq: int, steal: int}>
     */
    private function readCpuTimes(): array
    {
        $contents = @file_get_contents('/proc/stat');

        if (! is_string($contents)) {
            return [];
        }

        $cores = [];

        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/\Acpu(\d+)\s+(.*)\z/', $line, $matches) !== 1) {
                continue;
            }

            $fields = array_map(intval(...), preg_split('/\s+/', trim($matches[2])) ?: []);
            $fields = array_pad($fields, 8, 0);

            $cores[(int) $matches[1]] = [
                'user' => $fields[0],
                'nice' => $fields[1],
                'system' => $fields[2],
                'idle' => $fields[3],
                'iowait' => $fields[4],
                'irq' => $fields[5],
                'softirq' => $fields[6],
                'steal' => $fields[7],
            ];
        }

        ksort($cores);

        return array_values($cores);
    }

    /**
     * @param  list<array{user: int, nice: int, system: int, idle: int, iowait: int, irq: int, softirq: int, steal: int}>  $first
     * @param  list<array{user: int, nice: int, system: int, idle: int, iowait: int, irq: int, softirq: int, steal: int}>  $second
     * @return list<float>
     */
    private function coreBusyRatios(array $first, array $second): array
    {
        $ratios = [];

        foreach ($second as $index => $sample) {
            $previous = $first[$index] ?? null;

            if ($previous === null) {
                $ratios[] = 0.0;

                continue;
            }

            $idleDelta = ($sample['idle'] + $sample['iowait']) - ($previous['idle'] + $previous['iowait']);
            $totalDelta = array_sum($sample) - array_sum($previous);

            $ratios[] = $totalDelta > 0 ? max(0.0, min(1.0, 1 - ($idleDelta / $totalDelta))) : 0.0;
        }

        return $ratios;
    }

    /** @return array{used: int, total: int} */
    private function memory(): array
    {
        $values = $this->keyedKilobytes('/proc/meminfo');
        $total = $values['MemTotal'] ?? 0;
        $available = $values['MemAvailable'] ?? $values['MemFree'] ?? 0;

        return ['used' => max(0, $total - $available), 'total' => $total];
    }

    /** @return array{used: int, total: int} */
    private function swap(): array
    {
        $values = $this->keyedKilobytes('/proc/meminfo');
        $total = $values['SwapTotal'] ?? 0;
        $free = $values['SwapFree'] ?? 0;

        return ['used' => max(0, $total - $free), 'total' => $total];
    }

    /** @return array<string, int> Kibibyte values converted to bytes, keyed by /proc/meminfo field name. */
    private function keyedKilobytes(string $path): array
    {
        $contents = @file_get_contents($path);

        if (! is_string($contents)) {
            return [];
        }

        $values = [];

        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/\A(\w+):\s+(\d+)\s*kB\z/', $line, $matches) !== 1) {
                continue;
            }

            $values[$matches[1]] = ((int) $matches[2]) * 1024;
        }

        return $values;
    }

    /** @return array{one: float, five: float, fifteen: float} */
    private function load(): array
    {
        $contents = @file_get_contents('/proc/loadavg');

        if (! is_string($contents) || preg_match('/\A(\S+)\s+(\S+)\s+(\S+)/', $contents, $matches) !== 1) {
            return ['one' => 0.0, 'five' => 0.0, 'fifteen' => 0.0];
        }

        return [
            'one' => (float) $matches[1],
            'five' => (float) $matches[2],
            'fifteen' => (float) $matches[3],
        ];
    }

    private function uptimeSeconds(): int
    {
        $contents = @file_get_contents('/proc/uptime');

        if (! is_string($contents) || preg_match('/\A(\S+)/', $contents, $matches) !== 1) {
            return 0;
        }

        return (int) round((float) $matches[1]);
    }

    /** @return array{some_avg10: float} */
    private function pressureSomeAvg10(string $path): array
    {
        $contents = @file_get_contents($path);

        if (! is_string($contents)) {
            return ['some_avg10' => 0.0];
        }

        foreach (explode("\n", $contents) as $line) {
            if (str_starts_with($line, 'some ') && preg_match('/avg10=(\S+)/', $line, $matches) === 1) {
                return ['some_avg10' => (float) $matches[1]];
            }
        }

        return ['some_avg10' => 0.0];
    }

    /** @return list<array{mount: string, used: int, total: int}> */
    private function disks(): array
    {
        $process = new Process(['df', '-kP', '-x', 'tmpfs', '-x', 'devtmpfs', '-x', 'squashfs', '-x', 'overlay']);
        $process->setTimeout(5);

        try {
            $process->run();
        } catch (\Throwable) {
            return [];
        }

        if (! $process->isSuccessful()) {
            return [];
        }

        $disks = [];
        $lines = explode("\n", trim($process->getOutput()));

        foreach (array_slice($lines, 1) as $line) {
            $columns = preg_split('/\s+/', trim($line));

            if (! is_array($columns) || count($columns) < 6) {
                continue;
            }

            $totalKb = (int) $columns[1];
            $usedKb = (int) $columns[2];
            $mount = implode(' ', array_slice($columns, 5));

            $disks[] = ['mount' => $mount, 'used' => $usedKb * 1024, 'total' => $totalKb * 1024];
        }

        return $disks;
    }
}
