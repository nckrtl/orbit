<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseServer;
use App\Models\Node;
use Predis\Client;

/**
 * Runs PredisValkeyServerAdmin against a real Valkey. It is skipped unless ORBIT_TEST_VALKEY_HOST,
 * ORBIT_TEST_VALKEY_PORT, and ORBIT_TEST_VALKEY_PASSWORD name a throwaway Valkey that started from an ACL file with
 * only `user default on #<sha256 of the password> ~* &* +@all`, as a Valkey Database server does:
 *
 *     valkey-server --appendonly yes --dir /data --aclfile /data/users.acl
 *
 * The tests add and delete users named `orbit-test-*`.
 */
function predis_valkey_server(?string $password = null, ?int $port = null): DatabaseServer
{
    $server = new DatabaseServer([
        'slug' => 'test-valkey',
        'driver' => 'redis',
        'port' => $port ?? (int) getenv('ORBIT_TEST_VALKEY_PORT'),
        'root_password' => $password ?? (string) getenv('ORBIT_TEST_VALKEY_PASSWORD'),
    ]);
    $server->setRelation('node', new Node(['name' => 'valkey-test', 'wireguard_ip' => (string) getenv('ORBIT_TEST_VALKEY_HOST')]));

    return $server;
}

function predis_valkey_client(string $username, string $password): Client
{
    return new Client([
        'host' => (string) getenv('ORBIT_TEST_VALKEY_HOST'),
        'port' => (int) getenv('ORBIT_TEST_VALKEY_PORT'),
        'username' => $username,
        'password' => $password,
        'timeout' => 5.0,
    ]);
}

describe('PredisValkeyServerAdmin against a real Valkey', function (): void {
    beforeEach(function (): void {
        foreach (['ORBIT_TEST_VALKEY_HOST', 'ORBIT_TEST_VALKEY_PORT', 'ORBIT_TEST_VALKEY_PASSWORD'] as $variable) {
            if (getenv($variable) === false || getenv($variable) === '') {
                $this->markTestSkipped('Set ORBIT_TEST_VALKEY_HOST, ORBIT_TEST_VALKEY_PORT, and ORBIT_TEST_VALKEY_PASSWORD to run against a real Valkey.');
            }
        }

        $this->admin = app(ValkeyServerAdmin::class);
        $this->server = predis_valkey_server();
        $this->password = (string) getenv('ORBIT_TEST_VALKEY_PASSWORD');
    });

    afterEach(function (): void {
        if (isset($this->admin)) {
            $this->admin->deleteUser($this->server, 'orbit-test-queue');
        }
    });

    it('logs in as default only with the admin password and gives a client without one nothing', function (): void {
        expect($this->admin->waitUntilReady($this->server, 5))->toBeTrue()
            ->and($this->admin->acceptsAdminLogin($this->server))->toBeTrue()
            ->and($this->admin->acceptsAdminLogin(predis_valkey_server('wrong-password')))->toBeFalse()
            ->and($this->admin->acceptsAnonymous($this->server))->toBeFalse()
            ->and($this->admin->aclFile($this->server))->toBe('/data/users.acl');
    });

    it('reports default with the admin password and every right', function (): void {
        $default = $this->admin->aclUsers($this->server)['default'];

        expect($default->rules)->toBe('on sanitize-payload ~* &* +@all')
            ->and($default->hasOnlyPassword($this->password))->toBeTrue();
    });

    it('creates a user in Orbit\'s frame, saves it to the ACL file, and deletes it', function (): void {
        $rules = $this->admin->setUser(
            $this->server,
            'orbit-test-queue',
            'queue-secret',
            ['orbit-gateway:*', 'orbit-gateway-horizon:*'],
            ['@connection', '@list', 'eval'],
        );

        expect($rules)->toBe('on sanitize-payload ~orbit-gateway:* ~orbit-gateway-horizon:* resetchannels -@all +@connection +@list +eval -@admin -@dangerous')
            ->and($this->admin->userExists($this->server, 'orbit-test-queue'))->toBeTrue()
            ->and($this->admin->aclUsers($this->server)['orbit-test-queue']->rules)->toBe($rules)
            ->and($this->admin->aclUsers($this->server)['orbit-test-queue']->hasOnlyPassword('queue-secret'))->toBeTrue();

        $default = predis_valkey_client('default', $this->password);

        // ACL LOAD reads the file again, so the user survives only when ACL SAVE wrote it.
        expect((string) $default->executeRaw(['ACL', 'LOAD']))->toBe('OK')
            ->and($this->admin->userExists($this->server, 'orbit-test-queue'))->toBeTrue();

        $default->executeRaw(['DEL', 'orbit-gateway:orbit-test-jobs']);
        $queue = predis_valkey_client('orbit-test-queue', 'queue-secret');

        expect($queue->executeRaw(['RPUSH', 'orbit-gateway:orbit-test-jobs', 'job']))->toBe(1)
            ->and((string) $queue->executeRaw(['RPUSH', 'other:orbit-test-jobs', 'job']))->toStartWith('NOPERM')
            ->and((string) $queue->executeRaw(['DEL', 'orbit-gateway:orbit-test-jobs']))->toStartWith('NOPERM')
            ->and((string) $queue->executeRaw(['KEYS', '*']))->toStartWith('NOPERM')
            ->and((string) $queue->executeRaw(['CONFIG', 'GET', 'aclfile']))->toStartWith('NOPERM')
            ->and((string) $queue->executeRaw(['ACL', 'LIST']))->toStartWith('NOPERM');

        $default->executeRaw(['DEL', 'orbit-gateway:orbit-test-jobs']);
        $this->admin->deleteUser($this->server, 'orbit-test-queue');
        $this->admin->deleteUser($this->server, 'orbit-test-queue');

        expect($this->admin->userExists($this->server, 'orbit-test-queue'))->toBeFalse();
    });

    it('refuses default clients then closes an open server durably without deleting keys or other users', function (): void {
        $default = predis_valkey_client('default', $this->password);
        $default->executeRaw(['ACL', 'SETUSER', 'default', 'nopass']);
        $default->executeRaw(['ACL', 'SAVE']);
        $default->executeRaw(['SET', 'orbit-test-preserved', 'job']);
        try {
            expect(fn () => $this->admin->closeDefaultUser($this->server))->toThrow(ResourceOperationException::class, 'clients still use default');
            expect($this->admin->acceptsAnonymous($this->server))->toBeTrue();
            $default->disconnect();
            $this->admin->closeDefaultUser($this->server);
            expect($this->admin->acceptsAnonymous($this->server))->toBeFalse();
            $this->admin->closeDefaultUser($this->server);
            expect((string) $default->executeRaw(['ACL', 'LOAD']))->toBe('OK')
                ->and($default->executeRaw(['GET', 'orbit-test-preserved']))->toBe('job')
                ->and($this->admin->acceptsAnonymous($this->server))->toBeFalse();
        } finally {
            $default->executeRaw(['ACL', 'SETUSER', 'default', 'resetpass', '#'.hash('sha256', $this->password)]);
            $default->executeRaw(['ACL', 'SAVE']);
            $default->executeRaw(['DEL', 'orbit-test-preserved']);
            $default->disconnect();
        }
    });

    it('restores an open default when ACL SAVE fails and allows a safe retry', function (): void {
        $default = predis_valkey_client('default', $this->password);
        $default->executeRaw(['ACL', 'SETUSER', 'default', 'nopass']);
        $default->executeRaw(['ACL', 'SAVE']);
        // Removing SAVE permission causes a real command failure without filesystem tricks.
        $default->executeRaw(['ACL', 'SETUSER', 'default', '-acl|save']);
        $default->disconnect();
        try {
            expect(fn () => $this->admin->closeDefaultUser($this->server))->toThrow(ResourceOperationException::class);
            expect($this->admin->acceptsAnonymous($this->server))->toBeTrue();
        } finally {
            $default->executeRaw(['ACL', 'SETUSER', 'default', '+@all']);
            $default->disconnect();
            $this->admin->closeDefaultUser($this->server);
        }
        expect($this->admin->acceptsAnonymous($this->server))->toBeFalse();
    });

    it('removes a newly created user when saving it fails', function (): void {
        $default = predis_valkey_client('default', $this->password);
        $default->executeRaw(['ACL', 'SETUSER', 'default', '-acl|save']);
        try {
            expect(fn () => $this->admin->setUser($this->server, 'orbit-test-queue', 'queue-secret', ['queue:*'], ['@list']))->toThrow(ResourceOperationException::class);
            expect($this->admin->userExists($this->server, 'orbit-test-queue'))->toBeFalse();
        } finally {
            $default->executeRaw(['ACL', 'SETUSER', 'default', '+@all']);
            $default->executeRaw(['ACL', 'SAVE']);
            $default->disconnect();
        }
    });

    it('restores a deleted user when saving fails and persists its deletion on retry', function (): void {
        $this->admin->setUser($this->server, 'orbit-test-queue', 'queue-secret', ['queue:*'], ['@list']);
        $default = predis_valkey_client('default', $this->password);
        $default->executeRaw(['ACL', 'SETUSER', 'default', '-acl|save']);
        try {
            expect(fn () => $this->admin->deleteUser($this->server, 'orbit-test-queue'))->toThrow(ResourceOperationException::class);
            expect($this->admin->aclUsers($this->server)['orbit-test-queue']->hasOnlyPassword('queue-secret'))->toBeTrue();
        } finally {
            $default->executeRaw(['ACL', 'SETUSER', 'default', '+@all']);
            $default->executeRaw(['ACL', 'SAVE']);
        }
        $this->admin->deleteUser($this->server, 'orbit-test-queue');
        $default->executeRaw(['ACL', 'LOAD']);
        expect($this->admin->userExists($this->server, 'orbit-test-queue'))->toBeFalse();
        $default->disconnect();
    });

    it('reports the live database count and accepts only indexes below it', function (): void {
        expect($this->admin->databaseCount($this->server))->toBe(16);
        $default = predis_valkey_client('default', $this->password);
        expect((string) $default->executeRaw(['SELECT', '15']))->toBe('OK')
            ->and((string) $default->executeRaw(['SELECT', '16']))->toStartWith('ERR DB index is out of range');
        $default->disconnect();
    });

    it('runs the documented collector ACL through the Predis handshake and cache commands', function (): void {
        $this->admin->setUser($this->server, 'orbit-test-queue', 'queue-secret', ['orbit:proxycli:*'], ['@connection', '@string', '@keyspace', 'eval']);
        $client = predis_valkey_client('orbit-test-queue', 'queue-secret');
        expect((string) $client->executeRaw(['SET', 'orbit:proxycli:test', 'snapshot', 'EX', '60']))->toBe('OK')
            ->and($client->executeRaw(['GET', 'orbit:proxycli:test']))->toBe('snapshot')
            ->and($client->executeRaw(['EVAL', "return redis.call('GET', KEYS[1])", '1', 'orbit:proxycli:test']))->toBe('snapshot')
            ->and((string) $client->executeRaw(['GET', 'other:test']))->toStartWith('NOPERM');
        $client->executeRaw(['DEL', 'orbit:proxycli:test']);
        $client->disconnect();
    });

    it('returns a refused rule as database.acl_rules_invalid with the message of Valkey and no hash', function (): void {
        try {
            $this->admin->setUser($this->server, 'orbit-test-queue', 'queue-secret', ['orbit-gateway:*'], ['nosuchcommand']);
            $this->fail('Valkey accepted an unknown command.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('database.acl_rules_invalid')
                ->and($exception->status)->toBe(422)
                ->and($exception->getMessage())->toBe("Valkey refused a key pattern or a rule: ERR Error in ACL SETUSER modifier '+nosuchcommand': Unknown command or category name in ACL.")
                ->and($exception->getMessage())->not->toContain(hash('sha256', 'queue-secret'));
        }

        expect($this->admin->userExists($this->server, 'orbit-test-queue'))->toBeFalse();
    });

    it('returns a failed connection as database.server_command_failed without output', function (): void {
        $closed = predis_valkey_server(port: 1);

        expect($this->admin->acceptsAdminLogin($closed))->toBeFalse()
            ->and(fn () => $this->admin->aclUsers($closed))->toThrow(ResourceOperationException::class, 'Database server [test-valkey] could not run an admin command.')
            ->and(fn () => $this->admin->aclUsers(predis_valkey_server('wrong-password')))->toThrow(ResourceOperationException::class, 'Database server [test-valkey] could not run an admin command.');
    });
});
