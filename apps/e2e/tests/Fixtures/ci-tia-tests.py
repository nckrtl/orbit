"""Exercise bin/ci-tia against disposable Git repositories and the real Pest graph classes."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import threading
from urllib.parse import parse_qs, urlparse
import unittest

REPOSITORY = Path(sys.argv.pop(1)).resolve()
HELPER = REPOSITORY / 'bin/ci-tia'
PROJECT = 'apps/gateway'
PEST = '''<?php

pest()->tia()->watch([
    'resources/tasks/check' => 'tests/Feature/Tasks/CheckTest.php',
    'resources/scripts/*.py' => 'tests/Feature/ScriptTest.php',
]);
'''
FINGERPRINT = r'''
require 'vendor/autoload.php';
echo json_encode(Pest\Plugins\Tia\Fingerprint::compute(getcwd()), JSON_THROW_ON_ERROR);
'''


class ActionsApi(BaseHTTPRequestHandler):
    """Answer the workflow-run listing with the newest completed run per event that a test sets."""
    runs = {}
    requests = []

    def do_GET(self):
        url = urlparse(self.path)
        query = parse_qs(url.query)
        type(self).requests.append((url.path, query, self.headers.get('Authorization')))
        run = type(self).runs.get(query['event'][0])
        if run == 'error':
            self.send_error(500)
            return
        body = json.dumps({'workflow_runs': [run] if run else []}).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass


def git(cwd, *args):
    return subprocess.run(['git', '-C', str(cwd), *args], check=True, capture_output=True, text=True).stdout.strip()


class CiTiaTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='orbit-ci-tia-')
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.project = self.root / PROJECT
        git(self.root, 'init', '-q', '-b', 'main')
        git(self.root, 'config', 'user.name', 'Orbit')
        git(self.root, 'config', 'user.email', 'orbit@example.test')
        self.write('.gitignore', '/apps/gateway/vendor\n/apps/gateway/.orbit-tia/\n')
        self.write(f'{PROJECT}/composer.lock', '{}')
        self.write(f'{PROJECT}/tests/Pest.php', PEST)
        self.write(f'{PROJECT}/tests/ExampleTest.php', '<?php')
        self.write(f'{PROJECT}/app/Covered.php', '<?php')
        self.write(f'{PROJECT}/app/Uncovered.php', '<?php')
        self.write(f'{PROJECT}/resources/compute/script.py', 'print(1)')
        # The helper loads Pest from the project, as CI does after composer install.
        (self.project / 'vendor').symlink_to(REPOSITORY / 'apps/e2e/vendor')
        self.base = self.commit('base')
        self.write_graph(self.base)
        self.output = self.root / 'github-output'

    def write(self, path, content):
        target = self.root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content)

    def commit(self, message):
        git(self.root, 'add', '-A')
        git(self.root, 'commit', '-q', '-m', message)
        return git(self.root, 'rev-parse', 'HEAD')

    def fingerprint(self):
        return json.loads(subprocess.run(['php', '-r', FINGERPRINT], cwd=self.project, check=True,
                                         capture_output=True, text=True).stdout)

    def write_graph(self, sha, fingerprint=None, results=None):
        graph = {
            'schema': 1,
            'fingerprint': fingerprint or self.fingerprint(),
            'files': ['tests/ExampleTest.php', 'app/Covered.php'],
            'edges': {'tests/ExampleTest.php': [0, 1]},
            'baselines': {'main': {'sha': sha, 'tree': [], 'results': results if results is not None else {
                'Tests\\ExampleTest::it_works': {'status': 0, 'message': '', 'time': 0.1, 'assertions': 1,
                                                 'file': 'tests/ExampleTest.php'},
            }}},
        }
        self.write(f'{PROJECT}/.orbit-tia/graph.json', json.dumps(graph))

    def graph_sha(self):
        return json.loads((self.project / '.orbit-tia/graph.json').read_text())['baselines']['main']['sha']

    def run_helper(self, *args, environment=None):
        environment = {**os.environ, 'GITHUB_OUTPUT': str(self.output), **(environment or {})}
        return subprocess.run([str(HELPER), *args], cwd=self.project, env=environment, capture_output=True, text=True)

    def plan(self, event='push', key='prefix-main-sha-1-1', *extra, environment=None):
        self.output.write_text('')
        result = self.run_helper('plan', '--event', event, '--prefix', 'prefix', '--restored-key', key, *extra,
                                 environment=environment)
        self.assertEqual(0, result.returncode, result.stderr)
        outputs = dict(line.split('=', 1) for line in self.output.read_text().splitlines())
        return outputs['mode'], outputs['base'], result.stdout

    def change(self, path, content='changed'):
        self.write(path, content)
        return self.commit(f'change {path}')

    def test_selects_affected_tests_since_the_graph_commit(self):
        self.change(f'{PROJECT}/app/Covered.php', '<?php // first')
        self.change(f'{PROJECT}/app/New.php', '<?php')
        self.change(f'{PROJECT}/tests/OtherTest.php', '<?php')
        self.change(f'{PROJECT}/resources/tasks/check', 'watched')
        self.change(f'{PROJECT}/resources/scripts/probe.py', 'watched')
        self.change('docs/reference/page.md')
        self.change('apps/cli/app/Command.php', '<?php')

        mode, base, stdout = self.plan()

        self.assertEqual(('affected', self.base), (mode, base))
        self.assertIn(f'testing the changes since {self.base}', stdout)

    def test_runs_the_full_suite_for_changes_pest_cannot_see(self):
        for path in (f'{PROJECT}/resources/compute/script.py', f'{PROJECT}/app/Uncovered.php',
                     f'{PROJECT}/tests/Support/Helper.php', f'{PROJECT}/tests/Fixtures/data.json',
                     f'{PROJECT}/resources/scripts/nested/probe.py', 'docs/openapi.json',
                     'packages/php-sdk/fixtures/response.json', 'bin/tool', '.github/workflows/ci.yml',
                     'composer.json'):
            with self.subTest(path=path):
                git(self.root, 'reset', '-q', '--hard', self.base)
                self.change(path)

                mode, base, stdout = self.plan()

                self.assertEqual(('full', ''), (mode, base))
                self.assertIn(f'test-impact analysis cannot see {path}', stdout)

    def test_runs_the_full_suite_without_a_usable_main_graph(self):
        self.change(f'{PROJECT}/app/Covered.php', '<?php // first')
        cases = {
            'a schedule run refreshes': lambda: self.plan(event='schedule'),
            'a workflow_dispatch run refreshes': lambda: self.plan(event='workflow_dispatch'),
            'no main graph with these test inputs was restored (none)': lambda: self.plan(key=''),
            'no main graph with these test inputs was restored (other-main-': lambda: self.plan(key='other-main-x'),
            'no main graph with these test inputs was restored (prefix-feature-': lambda: self.plan(key='prefix-feature-x'),
        }
        for reason, plan in cases.items():
            with self.subTest(reason=reason):
                self.assertEqual('full', plan()[0])
                self.assertIn(reason, plan()[2])

        self.write_graph(self.base, fingerprint={'structural': {'schema': 0}, 'environmental': {}})
        self.assertIn('other dependencies or another PHP version', self.plan()[2])
        self.write_graph(self.base, results={})
        self.assertIn('the graph has no results', self.plan()[2])
        self.write_graph(self.base, results={'x': {'status': 0, 'file': 'tests/OtherTest.php'}})
        self.assertIn('no result for 1 test files, such as tests/ExampleTest.php', self.plan()[2])
        self.write_graph('f' * 40)
        self.assertIn(f'the graph commit {"f" * 40} is not an ancestor', self.plan()[2])
        (self.project / '.orbit-tia/graph.json').write_text('{')
        self.assertIn('the graph is missing or unreadable', self.plan()[2])

    def test_runs_the_full_suite_until_a_full_run_on_main_passes_again(self):
        server = ThreadingHTTPServer(('127.0.0.1', 0), ActionsApi)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        ActionsApi.requests = []
        environment = {'GITHUB_API_URL': f'http://127.0.0.1:{server.server_address[1]}', 'GITHUB_TOKEN': 'token'}
        self.change(f'{PROJECT}/app/Covered.php', '<?php // first')

        def plan(nightly, manual):
            ActionsApi.runs = {'schedule': nightly, 'workflow_dispatch': manual}
            return self.plan('push', 'prefix-main-x', '--repository', 'acme/orbit', environment=environment)

        def run(conclusion, created):
            return {'conclusion': conclusion, 'created_at': created, 'html_url': f'https://example.test/{created}'}

        failed = plan(run('failure', '2026-10-08T03:17:00Z'), run('success', '2026-10-07T12:00:00Z'))
        self.assertEqual('full', failed[0])
        self.assertIn('the newest full run on main did not pass (https://example.test/2026-10-08T03:17:00Z)', failed[2])
        self.assertEqual('full', plan(None, run('timed_out', '2026-10-08T03:17:00Z'))[0])
        self.assertIn('cannot be read', plan('error', None)[2])
        self.assertEqual('affected', plan(run('failure', '2026-10-08T03:17:00Z'), run('success', '2026-10-08T09:00:00Z'))[0])
        self.assertEqual('affected', plan(run('success', '2026-10-08T03:17:00Z'), None)[0])
        self.assertEqual('affected', plan(None, None)[0])
        self.assertEqual(('/repos/acme/orbit/actions/workflows/ci.yml/runs', 'Bearer token'),
                         (ActionsApi.requests[0][0], ActionsApi.requests[0][2]))
        self.assertEqual({'branch': ['main'], 'event': ['schedule'], 'status': ['completed'], 'per_page': ['1']},
                         ActionsApi.requests[0][1])

    def test_records_the_tested_commit_when_no_test_was_affected(self):
        head = self.change('docs/reference/page.md')
        log = self.root / 'pest.log'
        log.write_text('  INFO  No affected tests found.\n')

        result = self.run_helper('finish', '--mode', 'affected', '--base', self.base, '--log', str(log))

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(head, self.graph_sha())
        self.assertIn(f'The graph describes {head}.', result.stdout)
        # Pest reads the graph it wrote back.
        self.assertEqual(('affected', head), self.plan()[:2])

    def test_refuses_a_graph_that_does_not_describe_the_tested_commit(self):
        head = self.change(f'{PROJECT}/app/Covered.php', '<?php // first')
        log = self.root / 'pest.log'
        log.write_text('Tests: 1 passed\n')
        refusals = {
            'a run that tested something': ('affected', self.base, str(log)),
            'a full run': ('full', '', os.devnull),
            'another base': ('affected', 'e' * 40, str(log)),
        }
        for name, (mode, base, log_path) in refusals.items():
            with self.subTest(name=name):
                result = self.run_helper('finish', '--mode', mode, '--base', base, '--log', log_path)

                self.assertEqual(1, result.returncode)
                self.assertIn(f'The graph records {self.base}, not the tested commit {head}.', result.stderr)
                self.assertEqual(self.base, self.graph_sha())

        self.write_graph(head, results={'x': {'status': 0, 'file': 'tests/OtherTest.php'}})
        result = self.run_helper('finish', '--mode', 'full')
        self.assertEqual(1, result.returncode)
        self.assertIn('The graph is not complete: the graph has no result for 1 test files', result.stderr)

        self.write_graph(head)
        result = self.run_helper('finish', '--mode', 'full')
        self.assertEqual(0, result.returncode, result.stderr)


if __name__ == '__main__':
    unittest.main(verbosity=2)
