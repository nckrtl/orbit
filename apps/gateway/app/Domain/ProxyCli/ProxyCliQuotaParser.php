<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

final readonly class ProxyCliQuotaParser
{
    public function __construct(
        private ProxyCliWindowOrder $order = new ProxyCliWindowOrder,
    ) {}

    /**
     * @return list<ProxyCliWindow>
     */
    public function parse(string $provider, mixed $body): array
    {
        return match ($provider) {
            'claude' => $this->claude($body),
            'codex' => $this->codex($body),
            'grok' => $this->grok($body),
            'kimi' => $this->kimi($body),
            default => [],
        };
    }

    /**
     * @return list<ProxyCliWindow>
     */
    private function claude(mixed $body): array
    {
        $root = is_array($body) ? $body : [];

        return $this->order->sort(array_values(array_filter([
            $this->utilizationWindow($root['seven_day'] ?? null, '7d'),
            $this->utilizationWindow($root['five_hour'] ?? null, '5h'),
        ])));
    }

    /**
     * @return list<ProxyCliWindow>
     */
    private function codex(mixed $body): array
    {
        $rate = is_array($body) && is_array($body['rate_limit'] ?? null) ? $body['rate_limit'] : [];
        $windows = [];

        foreach (['primary_window', 'secondary_window'] as $slot) {
            $window = $this->codexWindow($rate[$slot] ?? null);

            if ($window instanceof ProxyCliWindow) {
                $windows[] = $window;
            }
        }

        return $this->order->sort($windows);
    }

    /**
     * @return list<ProxyCliWindow>
     */
    private function grok(mixed $body): array
    {
        $config = is_array($body) && is_array($body['config'] ?? null) ? $body['config'] : [];
        $period = is_array($config['currentPeriod'] ?? null) ? $config['currentPeriod'] : [];

        if (($period['type'] ?? null) !== 'USAGE_PERIOD_TYPE_WEEKLY') {
            return [];
        }

        $used = $this->number($config['creditUsagePercent'] ?? null);

        if ($used === null) {
            return [];
        }

        return $this->order->sort([
            new ProxyCliWindow('7d', $used, $this->timestamp($period['end'] ?? null)),
        ]);
    }

    /**
     * @return list<ProxyCliWindow>
     */
    private function kimi(mixed $body): array
    {
        $root = is_array($body) ? $body : [];
        $windows = [];
        $limits = is_array($root['limits'] ?? null) ? $root['limits'] : [];

        foreach ($limits as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $detail = is_array($entry['detail'] ?? null) ? $entry['detail'] : $entry;
            $used = $this->kimiPercent($detail);

            if ($used === null) {
                continue;
            }

            $seconds = $this->kimiWindowSeconds($entry['window'] ?? null);
            $windows[] = new ProxyCliWindow(
                $seconds === null ? 'limit' : $this->order->durationLabel($seconds),
                $used,
                $this->timestamp($detail['resetTime'] ?? $detail['reset_at'] ?? null),
            );
        }

        return $this->order->sort($windows);
    }

    private function utilizationWindow(mixed $value, string $label): ?ProxyCliWindow
    {
        if (! is_array($value)) {
            return null;
        }

        $used = $this->number($value['utilization'] ?? null);

        if ($used === null) {
            return null;
        }

        return new ProxyCliWindow($label, $used, $this->timestamp($value['resets_at'] ?? null));
    }

    private function codexWindow(mixed $value): ?ProxyCliWindow
    {
        if (! is_array($value)) {
            return null;
        }

        $used = $this->number($value['used_percent'] ?? null);

        if ($used === null) {
            return null;
        }

        $seconds = $this->intValue($value['limit_window_seconds'] ?? null);
        $resetsAt = $this->timestamp($value['reset_at'] ?? null);

        if ($resetsAt === null) {
            $resetAfter = $this->intValue($value['reset_after_seconds'] ?? null);
            $resetsAt = $resetAfter === null ? null : date(DATE_ATOM, time() + $resetAfter);
        }

        $label = $seconds !== null
            ? $this->order->durationLabel($seconds)
            : $this->codexFallbackLabel($resetsAt);

        return new ProxyCliWindow($label, $used, $resetsAt);
    }

    private function codexFallbackLabel(?string $resetsAt): string
    {
        if ($resetsAt === null) {
            return 'limit';
        }

        $hours = (strtotime($resetsAt) - time()) / 3_600;

        return $hours <= 24 ? '5h' : '7d';
    }

    /**
     * @param  array<array-key, mixed>  $detail
     */
    private function kimiPercent(array $detail): ?float
    {
        $used = $this->number($detail['used'] ?? null);
        $limit = $this->number($detail['limit'] ?? null);

        if ($used !== null && $limit !== null && $limit > 0) {
            return ($used / $limit) * 100;
        }

        $remaining = $this->number($detail['remaining'] ?? null);

        if ($remaining !== null && $limit !== null && $limit > 0) {
            return (($limit - $remaining) / $limit) * 100;
        }

        return null;
    }

    private function kimiWindowSeconds(mixed $value): ?int
    {
        if (! is_array($value)) {
            return null;
        }

        $duration = $this->intValue($value['duration'] ?? null);

        if ($duration === null) {
            return null;
        }

        return match ($value['timeUnit'] ?? null) {
            'TIME_UNIT_MINUTE' => $duration * 60,
            'TIME_UNIT_HOUR' => $duration * 3_600,
            'TIME_UNIT_DAY' => $duration * 86_400,
            'TIME_UNIT_WEEK' => $duration * 604_800,
            default => $duration,
        };
    }

    private function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function intValue(mixed $value): ?int
    {
        $number = $this->number($value);

        return $number === null ? null : (int) $number;
    }

    private function timestamp(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            $parsed = strtotime($value);

            return $parsed === false ? null : date(DATE_ATOM, $parsed);
        }

        $number = $this->number($value);

        if ($number === null) {
            return null;
        }

        $seconds = $number < 1_000_000_000_0 ? (int) $number : (int) ($number / 1000);

        return date(DATE_ATOM, $seconds);
    }
}
