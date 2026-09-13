<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

final readonly class VpnDnsmasqBackendListen
{
    public const string Address = '127.0.0.54';

    public static function apply(string $configuration): string
    {
        $lines = preg_split('/\R/', $configuration) ?: [];
        $kept = [];
        $hasListen = false;
        $hasBind = false;

        foreach ($lines as $line) {
            if (str_starts_with($line, 'interface=') || $line === 'bind-dynamic') {
                continue;
            }

            if (str_starts_with($line, 'listen-address=')) {
                $kept[] = 'listen-address='.self::Address;
                $hasListen = true;

                continue;
            }

            if ($line === 'bind-interfaces') {
                $hasBind = true;
            }

            $kept[] = $line;
        }

        $insert = [];
        if (! $hasListen) {
            $insert[] = 'listen-address='.self::Address;
        }
        if (! $hasBind) {
            $insert[] = 'bind-interfaces';
        }

        if ($insert !== []) {
            if ($kept !== [] && str_starts_with($kept[0], '#')) {
                array_splice($kept, 1, 0, $insert);
            } else {
                $kept = [...$insert, ...$kept];
            }
        }

        while ($kept !== [] && $kept[array_key_last($kept)] === '') {
            array_pop($kept);
        }

        return implode("\n", $kept)."\n";
    }
}
