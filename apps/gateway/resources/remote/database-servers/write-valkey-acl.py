# Program: database-servers.write-valkey-acl
# Uses: lib/program.py
# Outcomes: written failed
# Timeout: 300
# Input: {"volume": Docker volume name, "image": "valkey/valkey:<tag>", "acl": the ACL file}
# Result: no data. failed logs the check or the docker error.
# Writes /data/users.acl into the Docker volume of a Valkey Database server, before Valkey starts from it (ADR 0221). A
# short-lived container without a network mounts the volume and reads the file from standard input, so the file never
# reaches an argument list, and replaces the old file in one rename. The file is one line: `default` with the SHA-256
# hash of the admin password and every right, or nopass during explicit adoption of an open server.
import re
import subprocess

VOLUME = re.compile(r'[A-Za-z0-9][A-Za-z0-9_.-]{1,254}')
IMAGE = re.compile(r'valkey/valkey:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}')
ACL = re.compile(r'user default on (?:#[0-9a-f]{64}|nopass) ~\* &\* \+@all\n')
WRITE = 'umask 077 && cat > /data/users.acl.orbit-new && mv -f /data/users.acl.orbit-new /data/users.acl'


def main(payload):
    volume, image, acl = payload.get('volume'), payload.get('image'), payload.get('acl')
    require(isinstance(volume, str) and VOLUME.fullmatch(volume) is not None, 'failed',
            'the volume is not a Docker volume name')
    require(isinstance(image, str) and IMAGE.fullmatch(image) is not None, 'failed',
            'the image is not valkey/valkey:<tag>')
    require(isinstance(acl, str) and ACL.fullmatch(acl) is not None, 'failed',
            'the ACL file is not one supported default line')
    completed = subprocess.run(
        ['sudo', '-n', 'docker', 'run', '--rm', '-i', '--network', 'none',
         '--mount', f'type=volume,source={volume},target=/data', '--entrypoint', 'sh', image, '-c', WRITE],
        input=acl, capture_output=True, text=True, check=False,
    )
    if completed.returncode != 0:
        log(f'docker run exited {completed.returncode}')
        return 'failed', {}
    return 'written', {}


run_program(main)
