<?php

declare(strict_types=1);

namespace Tests\Support;

/** Evaluate the real fixture ACLs for Caddy when this worker cannot sudo to that account. */
final readonly class CaddyAclTestIdentity
{
    public static function script(): string
    {
        return <<<'PYTHON'
            import grp, os, pathlib, pwd, stat, subprocess, sys
            account = pwd.getpwnam('caddy')
            groups = {account.pw_gid} | {group.gr_gid for group in grp.getgrall() if account.pw_name in group.gr_mem}
            def bits(value): return sum(bit for letter, bit in (('r', 4), ('w', 2), ('x', 1)) if letter in value)
            def permits(path, requested):
                meta = os.stat(path)
                text = subprocess.check_output(['getfacl', '-cpn', '--', str(path)], text=True)
                entries = {line.split('#')[0].strip().rsplit(':', 1)[0]: bits(line.split('#')[0].strip().rsplit(':', 1)[1]) for line in text.splitlines() if line and not line.startswith(('default:', '#'))}
                mask = entries.get('mask:', 7)
                if meta.st_uid == account.pw_uid: allowed = entries['user:']
                elif 'user:' + str(account.pw_uid) in entries: allowed = entries['user:' + str(account.pw_uid)] & mask
                else:
                    matched = ([entries['group:']] if meta.st_gid in groups else []) + [value for key, value in entries.items() if key.startswith('group:') and key != 'group:' and int(key.split(':')[1]) in groups]
                    allowed = 0
                    if matched:
                        for value in matched: allowed |= value
                        allowed &= mask
                    else: allowed = entries['other:']
                return allowed & requested == requested
            program, target = sys.argv[1:]
            requested = 5 if 'os.R_OK' in program else 1
            path = pathlib.Path(target)
            try:
                result = all(permits(parent, 1) for parent in reversed(path.parents)) and permits(path, requested)
            except (OSError, KeyError): result = False
            raise SystemExit(0 if result else 1)
            PYTHON;
    }
}
