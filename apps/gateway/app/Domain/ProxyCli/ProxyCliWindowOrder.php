<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

final readonly class ProxyCliWindowOrder
{
    /**
     * management.html#/quota lists the longer window first. Duration labels never use Primary or Secondary.
     *
     * @param  list<ProxyCliWindow>  $windows
     * @return list<ProxyCliWindow>
     */
    public function sort(array $windows): array
    {
        $sorted = $windows;
        usort($sorted, function (ProxyCliWindow $left, ProxyCliWindow $right): int {
            $rank = $this->rank($left->label) <=> $this->rank($right->label);

            return $rank !== 0 ? $rank : strcmp($left->label, $right->label);
        });

        return array_values($sorted);
    }

    public function durationLabel(int $seconds): string
    {
        if ($seconds % 86_400 === 0) {
            return ($seconds / 86_400).'d';
        }

        if ($seconds % 3_600 === 0) {
            return ($seconds / 3_600).'h';
        }

        if ($seconds % 60 === 0) {
            return ($seconds / 60).'m';
        }

        return $seconds.'s';
    }

    private function rank(string $label): int
    {
        if (str_ends_with($label, 'd') || str_ends_with($label, 'w')) {
            return 0;
        }

        if (str_ends_with($label, 'h') || str_ends_with($label, 'm') || str_ends_with($label, 's')) {
            return 1;
        }

        return 2;
    }
}
