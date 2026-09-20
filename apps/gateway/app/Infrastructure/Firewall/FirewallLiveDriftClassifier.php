<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

final readonly class FirewallLiveDriftClassifier
{
    /**
     * @param  list<UfwRuleShape>  $live
     * @param  list<UfwRuleShape>  $expected
     * @return array{live: list<array{shape: UfwRuleShape, match: 'exact'|'drift'|'unmanaged'}>, missing: list<UfwRuleShape>}
     */
    public function classify(array $live, array $expected): array
    {
        $expectedByComment = [];

        foreach ($expected as $shape) {
            $expectedByComment[$shape->comment][] = $shape;
        }

        $seen = [];
        $classified = [];

        foreach ($live as $observed) {
            $candidates = $expectedByComment[$observed->comment] ?? [];

            if ($candidates === []) {
                $classified[] = ['shape' => $observed, 'match' => 'unmanaged'];

                continue;
            }

            $match = 'drift';

            foreach ($candidates as $expectedShape) {
                if ($expectedShape->matches($observed)) {
                    $match = 'exact';
                    break;
                }
            }

            $classified[] = ['shape' => $observed, 'match' => $match];
            $seen[$observed->comment] = true;
        }

        $missing = [];

        foreach ($expected as $shape) {
            if (! array_key_exists($shape->comment, $seen)) {
                $missing[] = $shape;
            }
        }

        return ['live' => $classified, 'missing' => $missing];
    }
}
