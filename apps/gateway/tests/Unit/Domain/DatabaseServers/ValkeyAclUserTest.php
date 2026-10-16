<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseServerProcess;
use App\Domain\DatabaseServers\ValkeyAclUser;

describe('ValkeyAclUser', function (): void {
    it('drops the name and the password hashes from an ACL LIST line and keeps the hashes apart', function (): void {
        $hash = hash('sha256', 'queue-secret');
        $user = ValkeyAclUser::fromAclListLine(
            "user gateway-queue on #{$hash} ~orbit-gateway:* ~orbit-gateway-horizon:* resetchannels -@all +@connection +eval -@admin -@dangerous",
        );

        expect($user)->toEqual(new ValkeyAclUser(
            'gateway-queue',
            'on ~orbit-gateway:* ~orbit-gateway-horizon:* resetchannels -@all +@connection +eval -@admin -@dangerous',
            [$hash],
        ))
            ->and($user?->hasOnlyPassword('queue-secret'))->toBeTrue()
            ->and($user?->hasOnlyPassword('other'))->toBeFalse();
    });

    it('reads a user without a password and a user with two', function (): void {
        $first = hash('sha256', 'one');
        $second = hash('sha256', 'two');

        expect(ValkeyAclUser::fromAclListLine('user default on nopass sanitize-payload ~* &* +@all'))
            ->toEqual(new ValkeyAclUser('default', 'on nopass sanitize-payload ~* &* +@all', []))
            ->and(ValkeyAclUser::fromAclListLine("user cache on #{$first} #{$second} ~cache:*  +@read")?->hasOnlyPassword('one'))->toBeFalse()
            ->and(ValkeyAclUser::fromAclListLine("user cache on #{$first} #{$second} ~cache:*  +@read")?->rules)->toBe('on ~cache:* +@read');
    });

    it('refuses a line that is not a user', function (string $line): void {
        expect(ValkeyAclUser::fromAclListLine($line))->toBeNull();
    })->with(['', 'user', 'default on ~* +@all', 'ERR unknown command']);

    it('writes an ACL file with only the default user, the admin password hash, and every right', function (): void {
        $file = DatabaseServerProcess::valkeyAclFile('admin-secret');

        expect($file)->toBe('user default on #'.hash('sha256', 'admin-secret')." ~* &* +@all\n")
            ->and($file)->not->toContain('admin-secret')
            ->and(ValkeyAclUser::fromAclListLine($file)?->hasOnlyPassword('admin-secret'))->toBeTrue();
    });
});
