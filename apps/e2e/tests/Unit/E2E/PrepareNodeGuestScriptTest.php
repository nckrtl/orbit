<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

describe('prepare node guest script', function () {
    it('replaces known hosts with the complete scan result atomically', function () {
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/prepare-node.sh');
        expect($source)
            ->toBeString()
            ->toContain('candidate=$(mktemp /home/orbit/.ssh/known_hosts.XXXXXX)')
            ->toContain('cat "$pins" >"$candidate"')
            ->toContain('mv -f "$candidate" /home/orbit/.ssh/known_hosts')
            ->not->toContain('grep -qxF')
            ->not->toContain('>>/home/orbit/.ssh/known_hosts');

        $root = sys_get_temp_dir().'/orbit-prepare-node-'.bin2hex(random_bytes(4));
        mkdir("$root/bin", 0o700, true);
        mkdir("$root/home/orbit/.ssh", 0o700, true);
        file_put_contents("$root/home/orbit/.ssh/known_hosts", "stale.example old-key\n");
        file_put_contents("$root/bin/ssh-keyscan", <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            case "${@: -1}" in
              app-dev) printf '%s\n' 'app-dev hashed-key' ;;
              app-prod) printf '%s\n' 'app-prod hashed-key' ;;
            esac
            BASH);
        file_put_contents("$root/bin/chown", "#!/usr/bin/env bash\nexit 0\n");
        chmod("$root/bin/ssh-keyscan", 0o700);
        chmod("$root/bin/chown", 0o700);

        $script = str_replace('/home/orbit', "$root/home/orbit", $source);
        $path = "$root/prepare-node.sh";
        file_put_contents($path, $script);
        chmod($path, 0o700);
        $process = new Process(['bash', $path, 'ssh-pins', 'app-dev', 'app-prod'], env: [
            'PATH' => "$root/bin:".getenv('PATH'),
        ]);

        expect($process->run())->toBe(0);
        expect(file_get_contents("$root/home/orbit/.ssh/known_hosts"))
            ->toBe("app-dev hashed-key\napp-prod hashed-key\n");
    });
});
