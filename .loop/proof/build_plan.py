#!/usr/bin/env python3
import json

CANDIDATE = 'd8a1883bc8cb7e7784aec97504c120cf22770b13'
VENV_PY = '/home/orbit/.local/state/orbit-cli-ux/ORB-361/venv/bin/python'
STATE = '/home/orbit/.local/state/orbit-cli-ux/ORB-361/proof'
PROOF_DIR = '/var/lib/orbit-e2e/proof'


def live(stage_id, timeout, node='gateway'):
    return {
        'id': stage_id,
        'node': node,
        'argv': [
            VENV_PY, PROOF_DIR + '/orb361-live.py',
            '--candidate', CANDIDATE,
            '--state', STATE,
            '--stage', stage_id,
        ],
        'timeout_seconds': timeout,
    }


def bash(action_id, node, script, timeout):
    return {'id': action_id, 'node': node, 'argv': ['bash', PROOF_DIR + '/' + script], 'timeout_seconds': timeout}


plan = {
    'mutates': True,
    'setup': [
        {'id': 'prepare-recorder-gateway', 'node': 'gateway',
         'argv': ['bash', PROOF_DIR + '/orb361-setup.sh'], 'timeout_seconds': 240},
    ],
    'acceptance': [
        live('doctor-baseline', 300),
        live('doctor-drift-prepare', 180),
        bash('apply-doctor-drift', 'app-dev', 'orb361-doctor-drift.sh', 60),
        live('doctor-drift-report', 240),
        bash('restore-doctor-drift', 'app-dev', 'orb361-doctor-drift-restore.sh', 60),
        bash('apply-doctor-unverifiable', 'app-prod', 'orb361-doctor-unverifiable.sh', 60),
        live('doctor-unverifiable-report', 240),
        bash('restore-doctor-unverifiable', 'app-prod', 'orb361-doctor-unverifiable-restore.sh', 60),
        live('doctor-clean-recheck', 240),
        live('deploy-prepare', 300),
        live('deploy-success', 600),
        live('deploy-chatty', 180),
        live('deploy-interrupt', 180),
        live('deploy-silent-interrupt-decorated', 90),
        live('deploy-silent-interrupt-pipe', 90),
        live('deploy-silent-interrupt-json', 90),
        live('deploy-failure', 120),
        live('deploy-after-activation-failure', 90),
        live('deploy-operation-failure', 90),
        live('rollback-explicit', 240),
        live('rollback-json', 240),
        live('rollback-pipe', 180),
        live('rollback-plain', 240),
        live('rollback-activation-failure', 180),
        live('rollback-no-release', 180),
        live('rollback-invalid', 180),
        live('rollback-missing-instance', 180),
        live('deploy-missing-instance', 180),
        live('json-parity', 600),
        live('resulting-state', 180),
        live('coverage-report', 120),
        {
            'id': 'export-recordings', 'node': 'gateway',
            'argv': [
                VENV_PY, PROOF_DIR + '/orb361-export.py',
                '--state', STATE,
                '--output', STATE + '/export',
            ],
            'timeout_seconds': 240,
        },
    ],
    'inputs': [
        'apps/cli',
        'packages/php-sdk',
        '.agents/skills/verifying-cli-output',
        'docs/reference/cli-ux.md',
        'docs/reference/deployments.md',
        'docs/cli/doctor.mdx',
        'docs/cli/instance.mdx',
        'docs/solutions/doctor-incus-proof.md',
    ],
}

with open('ORB-361.json', 'w') as f:
    json.dump(plan, f, indent=2)
    f.write('\n')

print('wrote ORB-361.json with', len(plan['setup']), 'setup and', len(plan['acceptance']), 'acceptance actions')
