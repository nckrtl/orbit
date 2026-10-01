<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\MysqlNames;

describe('MysqlNames', function (): void {
    it('replaces hyphens with underscores', function (): void {
        $names = new MysqlNames;

        expect($names->database('dlf-leden'))->toBe('dlf_leden')
            ->and($names->testDatabase('dlf_leden'))->toBe('dlf_leden_test')
            ->and($names->user('dlf-leden'))->toBe('dlf_leden')
            ->and($names->instanceUser('dlf', 'Feature.X'))->toBe('dlf_feature_x');
    });

    it('ends a name over the MySQL limit in an underscore and an 8-character hash', function (): void {
        $names = new MysqlNames;
        $long = str_repeat('ab', 20);
        $user = $names->user($long);
        $test = $names->testDatabase(str_repeat('d', 64));

        expect($user)->toBe(substr($long, 0, 23).'_'.substr(sha1($long), 0, 8))
            ->and(strlen($user))->toBe(32)
            ->and($names->user($long))->toBe($user)
            ->and($test)->toBe(str_repeat('d', 55).'_'.substr(sha1(str_repeat('d', 64).'_test'), 0, 8))
            ->and(strlen($test))->toBe(64)
            ->and($names->database(str_repeat('e', 64)))->toBe(str_repeat('e', 64));
    });
});
