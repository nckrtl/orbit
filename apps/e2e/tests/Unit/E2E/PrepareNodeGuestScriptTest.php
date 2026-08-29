<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

describe('prepare node guest script', function () {
    it('replaces orbit SSH authorization with the validated gateway public key', function () {
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/prepare-node.sh');
        $root = sys_get_temp_dir().'/orbit-authorize-gateway-'.bin2hex(random_bytes(4));
        mkdir("{$root}/bin", 0o700, true);
        mkdir("{$root}/home/orbit/.ssh", 0o700, true);
        file_put_contents("{$root}/home/orbit/.ssh/authorized_keys", "stale key\n");
        file_put_contents("{$root}/bin/chown", "#!/usr/bin/env bash\nexit 0\n");
        chmod("{$root}/bin/chown", 0o700);

        $script = str_replace('/home/orbit', "{$root}/home/orbit", $source);
        $path = "{$root}/prepare-node.sh";
        file_put_contents($path, $script);
        chmod($path, 0o700);
        $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOJgN5jVtcfw7oASD2F6If4O5mQ/HZBqbrw4QC9PcHEO';
        $process = new Process(['bash', $path, 'gateway-authorize', $key], env: [
            'PATH' => "{$root}/bin:".getenv('PATH'),
        ]);

        expect($process->run())
            ->toBe(0)
            ->and(file_get_contents("{$root}/home/orbit/.ssh/authorized_keys"))
            ->toBe($key."\n");
    });

    it('rejects an invalid gateway public key without changing authorization', function () {
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/prepare-node.sh');
        $root = sys_get_temp_dir().'/orbit-reject-gateway-key-'.bin2hex(random_bytes(4));
        mkdir("{$root}/home/orbit/.ssh", 0o700, true);
        file_put_contents("{$root}/home/orbit/.ssh/authorized_keys", "existing key\n");
        $path = "{$root}/prepare-node.sh";
        file_put_contents($path, str_replace('/home/orbit', "{$root}/home/orbit", $source));
        chmod($path, 0o700);
        $process = new Process(['bash', $path, 'gateway-authorize', 'not-a-key']);

        expect($process->run())
            ->toBe(64)
            ->and(file_get_contents("{$root}/home/orbit/.ssh/authorized_keys"))
            ->toBe("existing key\n");
    });

    /* ssh host pinning was removed; KnownHostsStore is authoritative. */
    it('does not expose an ssh-pins mode', function () {
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/prepare-node.sh');
        expect($source)->not->toContain('ssh-pins');
    });
});
