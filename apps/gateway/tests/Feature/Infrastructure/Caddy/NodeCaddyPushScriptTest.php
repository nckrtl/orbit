<?php

declare(strict_types=1);

use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\NodeCaddyfile;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddyPushScript;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Models\Node;
use Tests\Support\NodeCaddyPushHarness;

beforeEach(function (): void {
    $this->harness = new NodeCaddyPushHarness(packageDefault: "# The Caddyfile is an easy way to configure your Caddy web server.\n:80 {\n    root * /usr/share/caddy\n    file_server\n}\n");
});

afterEach(function (): void {
    $this->harness->cleanup();
});

describe('a first build', function (): void {
    it('writes one versioned file, validates it as caddy, points the live link at it, and reloads', function (): void {
        $caddyfile = node_caddy_push_file('shop.test');

        $result = $this->harness->push($caddyfile);

        expect($result['exit'])->toBe(0, $result['stderr'])
            ->and($result['stdout'])->toBe("orbit-caddy-build-result=published\n")
            ->and(readlink($this->harness->path('Caddyfile')))->toBe($this->harness->path("orbit-versions/{$caddyfile->version}/Caddyfile"))
            ->and(file_get_contents($this->harness->path('Caddyfile')))->toBe($caddyfile->content)
            ->and($this->harness->directories("orbit-versions/{$caddyfile->version}"))->toBe([])
            ->and($this->harness->validations())->toBe(['user=caddy', 'validate '.$this->harness->path("orbit-versions/.{$caddyfile->version}.candidate/Caddyfile")])
            ->and($this->harness->serviceCalls())->toBe(['enable --quiet caddy', 'reload-or-restart caddy'])
            ->and(is_dir($this->harness->path('orbit-backups')))->toBeFalse();
    });

    it('replaces the unmodified package default without a backup', function (): void {
        $this->harness->write('Caddyfile', "# The Caddyfile is an easy way to configure your Caddy web server.\n:80 {\n    root * /usr/share/caddy\n    file_server\n}\n");

        $result = $this->harness->push(node_caddy_push_file('shop.test'));

        expect($result['exit'])->toBe(0, $result['stderr'])
            ->and(is_link($this->harness->path('Caddyfile')))->toBeTrue()
            ->and(is_dir($this->harness->path('orbit-backups')))->toBeFalse();
    });

    it('backs up a foreign regular Caddyfile and replaces it', function (): void {
        $this->harness->write('Caddyfile', "hand.example.com {\n    respond \"hand placed\"\n}\n");

        $result = $this->harness->push(node_caddy_push_file('shop.test'));
        $backups = $this->harness->directories('orbit-backups');

        expect($result['exit'])->toBe(0, $result['stderr'])
            ->and($backups)->toHaveCount(1)
            ->and($backups[0])->toMatch('/\A\d{8}T\d{6}Z\z/')
            ->and(file_get_contents($this->harness->path("orbit-backups/{$backups[0]}/Caddyfile")))->toBe("hand.example.com {\n    respond \"hand placed\"\n}\n")
            ->and($result['stderr'])->toContain('Backed up '.$this->harness->path('Caddyfile').' to ');
    });

    it('backs up the whole version directory of the old fragment layout, unmanaged fragment included', function (): void {
        $this->harness->write('orbit-versions/0123456789abcdef/Caddyfile', "{\n    auto_https disable_certs\n}\nimport /etc/caddy/orbit-versions/0123456789abcdef/fragments/*.caddy\n");
        $this->harness->write('orbit-versions/0123456789abcdef/fragments/app-dev.caddy', "https://shop.test {\n    bind 0.0.0.0\n}\n");
        $this->harness->write('orbit-versions/0123456789abcdef/fragments/00-unmanaged.caddy', "hand.example.com {\n    respond hi\n}\n");
        $this->harness->link($this->harness->path('orbit-versions/0123456789abcdef/Caddyfile'));

        $result = $this->harness->push(node_caddy_push_file('shop.test'));
        [$backup] = $this->harness->directories('orbit-backups');

        expect($result['exit'])->toBe(0, $result['stderr'])
            ->and($this->harness->directories("orbit-backups/{$backup}/0123456789abcdef"))->toBe(['fragments'])
            ->and(file_get_contents($this->harness->path("orbit-backups/{$backup}/0123456789abcdef/fragments/00-unmanaged.caddy")))->toBe("hand.example.com {\n    respond hi\n}\n")
            ->and(file_get_contents($this->harness->path("orbit-backups/{$backup}/0123456789abcdef/fragments/app-dev.caddy")))->toContain('https://shop.test');
    });

    it('backs up the file behind a symlink outside Orbit versions', function (): void {
        $this->harness->write('sites/main.caddy', "other.example.com {\n    respond hi\n}\n");
        $this->harness->link($this->harness->path('sites/main.caddy'));

        $result = $this->harness->push(node_caddy_push_file('shop.test'));
        [$backup] = $this->harness->directories('orbit-backups');

        expect($result['exit'])->toBe(0, $result['stderr'])
            ->and(file_get_contents($this->harness->path("orbit-backups/{$backup}/Caddyfile")))->toBe("other.example.com {\n    respond hi\n}\n")
            ->and(file_exists($this->harness->path('sites/main.caddy')))->toBeTrue();
    });
});

describe('a later build', function (): void {
    it('changes nothing when the render matches the live version', function (): void {
        $caddyfile = node_caddy_push_file('shop.test');
        $this->harness->push($caddyfile);

        $result = $this->harness->push($caddyfile);

        expect($result['exit'])->toBe(0, $result['stderr'])
            ->and($result['stdout'])->toBe("orbit-caddy-build-result=unchanged\n")
            ->and($this->harness->serviceCalls())->toBe([])
            ->and($this->harness->validations())->toBe([]);
    });

    it('backs up and replaces a live version that was edited by hand after its build', function (): void {
        $caddyfile = node_caddy_push_file('shop.test');
        $this->harness->push($caddyfile);
        $live = $this->harness->path("orbit-versions/{$caddyfile->version}/Caddyfile");
        file_put_contents($live, $caddyfile->content."hand.example.com {\n    respond hi\n}\n");

        $result = $this->harness->push($caddyfile);
        [$backup] = $this->harness->directories('orbit-backups');

        expect($result['exit'])->toBe(0, $result['stderr'])
            ->and($result['stdout'])->toBe("orbit-caddy-build-result=published\n")
            ->and(file_get_contents($this->harness->path('Caddyfile')))->toBe($caddyfile->content)
            ->and(file_get_contents($this->harness->path("orbit-backups/{$backup}/{$caddyfile->version}/Caddyfile")))->toContain('hand.example.com')
            ->and($this->harness->directories('orbit-versions'))->toBe([$caddyfile->version]);
    });

    it('backs up a hand-edited live version before a build with a different version replaces it', function (): void {
        $first = node_caddy_push_file('shop.test');
        $this->harness->push($first);
        file_put_contents($this->harness->path("orbit-versions/{$first->version}/Caddyfile"), $first->content."hand.example.com {\n    respond hi\n}\n");
        $next = node_caddy_push_file('other.test');

        $result = $this->harness->push($next);
        [$backup] = $this->harness->directories('orbit-backups');

        expect($result['exit'])->toBe(0, $result['stderr'])
            ->and(file_get_contents($this->harness->path('Caddyfile')))->toBe($next->content)
            ->and(file_get_contents($this->harness->path("orbit-backups/{$backup}/{$first->version}/Caddyfile")))->toContain('hand.example.com');
    });

    it('backs up a hand-edited old version before prune removes it, and keeps an intact one without a backup', function (): void {
        $edited = node_caddy_push_file('edited.test');
        $intact = node_caddy_push_file('intact.test');
        $this->harness->write("orbit-versions/{$edited->version}/Caddyfile", $edited->content."# edited\n");
        $this->harness->write("orbit-versions/{$intact->version}/Caddyfile", $intact->content);
        touch($this->harness->path("orbit-versions/{$edited->version}"), time() - 3600);
        touch($this->harness->path("orbit-versions/{$intact->version}"), time() - 3500);
        foreach (range(1, 9) as $age) {
            $name = sprintf('%016x', $age);
            $this->harness->write("orbit-versions/{$name}/Caddyfile", "# old {$age}\n");
            touch($this->harness->path("orbit-versions/{$name}"), time() - ($age * 60));
        }
        $live = node_caddy_push_file('shop.test');

        $result = $this->harness->push($live);
        $backups = $this->harness->directories('orbit-backups');

        expect($result['exit'])->toBe(0, $result['stderr'])
            ->and($this->harness->directories('orbit-versions'))->not->toContain($edited->version, $intact->version)
            ->and($backups)->toHaveCount(1)
            ->and(file_get_contents($this->harness->path("orbit-backups/{$backups[0]}/{$edited->version}/Caddyfile")))->toEndWith("# edited\n");
    });

    it('restores a hand-edited live version when Caddy fails to reload its replacement', function (): void {
        $caddyfile = node_caddy_push_file('shop.test');
        $this->harness->push($caddyfile);
        $live = $this->harness->path("orbit-versions/{$caddyfile->version}/Caddyfile");
        file_put_contents($live, $caddyfile->content."# edited\n");

        $result = $this->harness->push($caddyfile, ['HARNESS_FAIL_RELOAD' => '1']);

        expect($result['exit'])->not->toBe(0)
            ->and(file_get_contents($this->harness->path('Caddyfile')))->toBe($caddyfile->content."# edited\n")
            ->and($this->harness->directories('orbit-versions'))->toBe([$caddyfile->version]);
    });

    it('does not back up an earlier build, and keeps every existing backup', function (): void {
        $this->harness->write('orbit-backups/20260101T000000Z/Caddyfile', "kept\n");
        $this->harness->push(node_caddy_push_file('shop.test'));

        $result = $this->harness->push(node_caddy_push_file('other.test'));

        expect($result['exit'])->toBe(0, $result['stderr'])
            ->and($this->harness->directories('orbit-backups'))->toBe(['20260101T000000Z'])
            ->and(file_get_contents($this->harness->path('orbit-backups/20260101T000000Z/Caddyfile')))->toBe("kept\n");
    });

    it('keeps the live version and the nine newest others and removes the retired staged directory', function (): void {
        foreach (range(1, 12) as $age) {
            $name = sprintf('%016x', $age);
            $this->harness->write("orbit-versions/{$name}/Caddyfile", "# old {$age}\n");
            touch($this->harness->path("orbit-versions/{$name}"), time() - ($age * 60));
        }
        $this->harness->write('orbit-versions/staged/route-1-ingress.caddy', "staged\n");
        $this->harness->link($this->harness->path('orbit-versions/000000000000000c/Caddyfile'));
        $caddyfile = node_caddy_push_file('shop.test');

        $result = $this->harness->push($caddyfile);
        $expected = [...array_map(static fn (int $age): string => sprintf('%016x', $age), range(1, 9)), $caddyfile->version];
        sort($expected);

        expect($result['exit'])->toBe(0, $result['stderr'])
            ->and($this->harness->directories('orbit-versions'))->toBe($expected)
            ->and($this->harness->directories('orbit-backups'))->toHaveCount(1);
    });
});

describe('a failed build', function (): void {
    it('keeps the live file and writes no backup when validation fails', function (): void {
        $this->harness->write('Caddyfile', "hand.example.com {\n    respond hi\n}\n");

        $result = $this->harness->push(node_caddy_push_file('shop.test'), ['HARNESS_FAIL_VALIDATE' => '1']);

        expect($result['exit'])->not->toBe(0)
            ->and($result['stderr'])->toContain('unrecognized directive: broken')
            ->and($result['stderr'])->not->toContain('"level":"info"')
            ->and($result['stderr'])->not->toContain('not formatted')
            ->and($result['stderr'])->toContain('orbit-caddy-build-stage=validate')
            ->and(is_link($this->harness->path('Caddyfile')))->toBeFalse()
            ->and(file_get_contents($this->harness->path('Caddyfile')))->toBe("hand.example.com {\n    respond hi\n}\n")
            ->and($this->harness->directories('orbit-versions'))->toBe([])
            ->and(is_dir($this->harness->path('orbit-backups')))->toBeFalse()
            ->and($this->harness->serviceCalls())->toBe([]);
    });

    it('restores the previous version and reloads again when Caddy fails to reload', function (): void {
        $previous = node_caddy_push_file('shop.test');
        $this->harness->push($previous);
        $next = node_caddy_push_file('other.test');

        $result = $this->harness->push($next, ['HARNESS_FAIL_RELOAD' => '1']);

        expect($result['exit'])->not->toBe(0)
            ->and($result['stderr'])->toContain('orbit-caddy-build-stage=reload')
            ->and($result['stderr'])->toContain('address already in use')
            ->and(readlink($this->harness->path('Caddyfile')))->toBe($this->harness->path("orbit-versions/{$previous->version}/Caddyfile"))
            ->and($this->harness->directories('orbit-versions'))->toBe([$previous->version])
            ->and($this->harness->serviceCalls())->toBe(['enable --quiet caddy', 'reload-or-restart caddy', 'is-active --quiet caddy', 'reload caddy']);
    });

    it('never restarts a running Caddy when every reload fails', function (): void {
        $previous = node_caddy_push_file('shop.test');
        $this->harness->push($previous);

        $result = $this->harness->push(node_caddy_push_file('other.test'), ['HARNESS_FAIL_RELOAD' => 'always']);

        expect($result['exit'])->not->toBe(0)
            ->and($result['stderr'])->toContain('orbit-caddy-build-stage=reload')
            ->and(readlink($this->harness->path('Caddyfile')))->toBe($this->harness->path("orbit-versions/{$previous->version}/Caddyfile"))
            ->and($this->harness->serviceCalls())->toBe(['enable --quiet caddy', 'reload-or-restart caddy', 'is-active --quiet caddy', 'reload caddy'])
            ->and($this->harness->serviceCalls())->not->toContain('restart caddy');
    });

    it('starts Caddy with the restored file when it is not running after a failed reload', function (): void {
        $previous = node_caddy_push_file('shop.test');
        $this->harness->push($previous);

        $result = $this->harness->push(node_caddy_push_file('other.test'), ['HARNESS_FAIL_RELOAD' => 'always', 'HARNESS_CADDY_INACTIVE' => '1']);

        expect($result['exit'])->not->toBe(0)
            ->and(readlink($this->harness->path('Caddyfile')))->toBe($this->harness->path("orbit-versions/{$previous->version}/Caddyfile"))
            ->and($this->harness->serviceCalls())->toBe(['enable --quiet caddy', 'reload-or-restart caddy', 'is-active --quiet caddy', 'start caddy']);
    });

    it('restores a replaced regular file when Caddy fails to reload', function (): void {
        $this->harness->write('Caddyfile', "hand.example.com {\n    respond hi\n}\n");

        $result = $this->harness->push(node_caddy_push_file('shop.test'), ['HARNESS_FAIL_RELOAD' => '1']);

        expect($result['exit'])->not->toBe(0)
            ->and(is_link($this->harness->path('Caddyfile')))->toBeFalse()
            ->and(file_get_contents($this->harness->path('Caddyfile')))->toBe("hand.example.com {\n    respond hi\n}\n")
            ->and($this->harness->directories('orbit-versions'))->toBe([]);
    });

    it('refuses an address the Node does not have before it writes anything', function (): void {
        $this->harness->write('Caddyfile', "hand.example.com {\n    respond hi\n}\n");

        $result = $this->harness->push(node_caddy_push_file('shop.test'), ['HARNESS_ADDRESSES' => '127.0.0.1 192.168.1.9']);

        expect($result['exit'])->not->toBe(0)
            ->and($result['stderr'])->toContain('The build binds 10.44.0.9, which is not an address on this Node.')
            ->and($result['stderr'])->toContain('orbit-caddy-build-stage=addresses')
            ->and(file_get_contents($this->harness->path('Caddyfile')))->toBe("hand.example.com {\n    respond hi\n}\n")
            ->and($this->harness->directories('orbit-versions'))->toBe([])
            ->and($this->harness->validations())->toBe([])
            ->and($this->harness->serviceCalls())->toBe([]);
    });

    it('refuses a Caddy below the release floor before it writes anything', function (): void {
        $result = $this->harness->push(node_caddy_push_file('shop.test'), ['HARNESS_CADDY_VERSION' => '2.6.2']);

        expect($result['exit'])->not->toBe(0)
            ->and($result['stderr'])->toContain('is below the Orbit release floor 2.9.0')
            ->and($result['stderr'])->toContain('orbit-caddy-build-stage=release')
            ->and(file_exists($this->harness->path('Caddyfile')))->toBeFalse()
            ->and($this->harness->directories('orbit-versions'))->toBe([])
            ->and($this->harness->validations())->toBe([]);
    });

    it('accepts the floor release itself', function (): void {
        $result = $this->harness->push(node_caddy_push_file('shop.test'), ['HARNESS_CADDY_VERSION' => 'v2.9.0 h1:abc']);

        expect($result['exit'])->toBe(0, $result['stderr']);
    });
});

describe('an address check', function (): void {
    it('passes without a change when every bound address is on the Node', function (): void {
        $result = $this->harness->checkAddresses(node_caddy_push_file('shop.test'), '127.0.0.1 10.44.0.9');

        expect($result['exit'])->toBe(0, $result['stderr'])
            ->and($this->harness->directories('orbit-versions'))->toBe([])
            ->and($this->harness->serviceCalls())->toBe([]);
    });

    it('names a missing address at stage addresses without root', function (): void {
        $command = $this->harness->script()->addressCheck(node_caddy_push_file('shop.test'));
        $result = $this->harness->checkAddresses(node_caddy_push_file('shop.test'), '127.0.0.1');

        expect($command->arguments[0])->toBe('bash')
            ->and($result['exit'])->not->toBe(0)
            ->and($result['stderr'])->toContain('The build binds 10.44.0.9, which is not an address on this Node.')
            ->and($result['stderr'])->toContain('orbit-caddy-build-stage=addresses')
            ->and($this->harness->directories('orbit-versions'))->toBe([]);
    });
});

it('refuses to push a render with problems or a version that does not match its content', function (): void {
    $broken = new NodeCaddyfile('node', "x\n", NodeCaddyfileRenderer::version("x\n"), [], ['duplicate']);
    $mismatched = new NodeCaddyfile('node', "x\n", str_repeat('0', 32), [], []);

    expect(fn () => new NodeCaddyPushScript()->command($broken))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new NodeCaddyPushScript()->command($mismatched))->toThrow(InvalidArgumentException::class);
});

it('runs as root through the fixed script arguments', function (): void {
    $command = new NodeCaddyPushScript()->command(node_caddy_push_file('shop.test'));

    expect(array_slice($command->arguments, 0, 4))->toBe(['sudo', 'bash', '-seu', '--'])
        ->and(array_slice($command->arguments, 5))->toBe(['/etc/caddy', '/usr/bin/caddy', 'caddy', '/run/lock/orbit/caddy.lock', '2.9.0', NodeCaddyfileRenderer::Marker, '9', '10.44.0.9']);
});

function node_caddy_push_file(string $domain): NodeCaddyfile
{
    $source = new readonly class($domain) implements NodeCaddySiteSource
    {
        public function __construct(private string $domain) {}

        public function sites(Node $node): array
        {
            return [new CaddySite(
                source: 'app-dev',
                name: 'route-1-router',
                listener: CaddyListenerRule::Wildcard,
                hosts: [$this->domain],
                port: 443,
                body: "https://{$this->domain} {\n    bind 0.0.0.0\n    respond ok\n}\n",
            )];
        }
    };

    return new NodeCaddyfileRenderer([$source])->render(new Node(['name' => 'node', 'wireguard_ip' => '10.44.0.9']));
}
