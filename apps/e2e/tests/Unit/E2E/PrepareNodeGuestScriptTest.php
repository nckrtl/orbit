<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

describe('prepare node guest script', function () {
    it('replaces orbit SSH authorization with the validated gateway public key', function () {
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/prepare-node.sh');
        $root = temporaryPath('orbit-authorize-gateway-', 4);
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
        $root = temporaryPath('orbit-reject-gateway-key-', 4);
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
    it('moves the orbit account to uid and gid 1000 and re-owns stale files', function () {
        $root = sys_get_temp_dir().'/orbit-align-identity-'.bin2hex(random_bytes(4));
        mkdir("{$root}/bin", 0o700, true);
        $shim = "#!/usr/bin/env bash\nprintf '%s\\n' \"\$(basename \"\$0\") \$*\" >> '{$root}/commands'\n";
        foreach (['userdel', 'groupmod', 'usermod', 'find', 'systemctl'] as $command) {
            file_put_contents("{$root}/bin/{$command}", $shim);
            chmod("{$root}/bin/{$command}", 0o700);
        }
        file_put_contents("{$root}/bin/id", <<<'BASH'
            #!/usr/bin/env bash
            case "$*" in
              '-u orbit') printf '%s\n' "${ALIGN_ORBIT_UID:-1002}" ;;
              '-g orbit') printf '%s\n' "${ALIGN_ORBIT_GID:-1002}" ;;
              '-u ubuntu') [[ "${ALIGN_UBUNTU_PRESENT:-1}" == 1 ]] && printf '1000\n' ;;
              *) exit 1 ;;
            esac
            BASH);
        file_put_contents("{$root}/bin/getent", "#!/usr/bin/env bash\nexit 2\n");
        chmod("{$root}/bin/id", 0o700);
        chmod("{$root}/bin/getent", 0o700);
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/prepare-node.sh');
        file_put_contents("{$root}/prepare-node.sh", $source);
        $environment = ['PATH' => "{$root}/bin:".getenv('PATH')];

        try {
            $process = new Process(['bash', "{$root}/prepare-node.sh", 'align-identity'], env: $environment);
            expect($process->run())
                ->toBe(0, $process->getErrorOutput())
                ->and(array_values(preg_grep('/^(userdel|groupmod|usermod|systemctl) /', file(
                    "{$root}/commands",
                    FILE_IGNORE_NEW_LINES,
                ))))
                ->toBe([
                    'userdel --remove ubuntu',
                    'groupmod --gid 1000 orbit',
                    'usermod --uid 1000 --gid 1000 orbit',
                    'systemctl is-active --quiet php8.5-fpm',
                    'systemctl restart php8.5-fpm',
                ]);
            expect(file_get_contents("{$root}/commands"))
                ->toContain('find / /run -xdev ( -uid 1002 -o -gid 1002 ) -exec python3 -c')
                ->toContain(
                    'os.lchown(path, 1000 if st.st_uid == old_uid else -1, 1000 if st.st_gid == old_gid else -1)',
                )
                ->toContain(' 1002 1002 {} +');

            unlink("{$root}/commands");
            $process = new Process(
                ['bash', "{$root}/prepare-node.sh", 'align-identity'],
                env: [...$environment, 'ALIGN_ORBIT_UID' => '1000', 'ALIGN_ORBIT_GID' => '1000'],
            );
            expect($process->run())
                ->toBe(0)
                ->and(array_values(preg_grep(
                    '/^(userdel|groupmod|usermod|systemctl) /',
                    file("{$root}/commands", FILE_IGNORE_NEW_LINES),
                )))
                ->toBe([])
                ->and(file_get_contents("{$root}/commands"))
                ->toContain('find /home/orbit ( -nouser -o -nogroup ) -exec python3 -c');
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($root);
        }
    });

    it('does not expose an ssh-pins mode', function () {
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/prepare-node.sh');
        expect($source)->not->toContain('ssh-pins');
    });
});
