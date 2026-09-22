"""Real subprocess/file regressions; fake executables model framework reports."""
import importlib.util
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True

spec = importlib.util.spec_from_file_location('task_check', sys.argv[1])
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)
sys.argv = [sys.argv[0]]
PROJECT = 'apps/gateway'
REFERENCE = {'criterion_id': 'criterion', 'project': PROJECT, 'path': 'tests/ScenarioTest.php', 'test': 'it observes the result'}


class TaskCheckTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='orbit-check-test-')
        self.root = Path(self.temp.name) / 'checkout'
        self.runtime = Path(self.temp.name) / 'runtime'
        environment = patch.dict(runner.os.environ, {'ORBIT_HOME': str(self.runtime)})
        environment.start()
        self.addCleanup(environment.stop)
        self.project = self.root / PROJECT
        (self.project / 'tests').mkdir(parents=True)
        (self.project / 'tests/ScenarioTest.php').write_text('<?php // expected observation\n')
        (self.project / 'check').write_text('#!/bin/sh\necho "composer check: exit code 0"\n')
        (self.project / 'check').chmod(0o755)
        (self.root / '.gitignore').write_text('vendor/\n')
        subprocess.run(['git', 'init', '--quiet', str(self.root)], check=True)
        subprocess.run(['git', '-C', str(self.root), 'add', '.'], check=True)
        subprocess.run(['git', '-C', str(self.root), '-c', 'user.name=Tests', '-c', 'user.email=tests@example.test',
                        'commit', '--quiet', '-m', 'fixture'], check=True)
        vendor = self.project / 'vendor/bin'
        vendor.mkdir(parents=True)
        pest = vendor / 'pest'
        pest.write_text('''#!/usr/bin/env python3
import sys, xml.etree.ElementTree as ET
root=ET.Element('testsuite')
ET.SubElement(root,'testcase',{'name':'it observes the result','file':'tests/ScenarioTest.php::it observes the result','assertions':'1'})
ET.ElementTree(root).write(sys.argv[sys.argv.index('--log-junit')+1])
''')
        pest.chmod(0o755)

    def tearDown(self):
        self.temp.cleanup()

    def identity(self):
        return runner.identity(self.root, (PROJECT,))

    def run_checks(self, references=None, **kwargs):
        result = runner.run(self.root, references or [REFERENCE], (PROJECT,), (('./check',),), **kwargs)
        return result

    def test_dirty_files_and_test_evidence_pass_without_changing_the_index(self):
        (self.project / 'uncommitted.php').write_text('<?php // new source')
        before = (self.root / '.git/index').read_bytes()
        result = self.run_checks()
        self.assertTrue(result['passed'])
        self.assertEqual('passed', result['evidence']['criterion']['result'])
        self.assertEqual(before, (self.root / '.git/index').read_bytes())
        directory = Path(result['log_directory'])
        self.assertTrue(directory.is_relative_to(self.runtime))
        self.assertEqual(0o700, directory.stat().st_mode & 0o777)

    def test_printed_success_cannot_override_a_failed_process(self):
        (self.project / 'check').write_text('#!/bin/sh\necho "exit code 0 passed"\nexit 9\n')
        result = self.run_checks()
        self.assertFalse(result['passed'])
        self.assertEqual(9, result['checks'][0]['exit_code'])
        self.assertEqual({}, result['evidence'])

    def test_shell_edits_untracked_files_modes_and_deletions_change_identity(self):
        for mutation in ('content', 'untracked', 'mode', 'delete'):
            before = self.identity()
            path = self.project / 'tests/ScenarioTest.php'
            if mutation == 'content':
                path.write_text('<?php // edited with a shell')
            elif mutation == 'untracked':
                (self.project / 'tests/NewTest.php').write_text('<?php')
            elif mutation == 'mode':
                path.chmod(0o755)
            else:
                path.unlink()
            self.assertNotEqual(before['digest'], self.identity()['digest'], mutation)

    def test_branch_change_invalidates_even_identical_source(self):
        before = self.identity()
        subprocess.run(['git', '-C', str(self.root), 'checkout', '--quiet', '-b', 'another-task'], check=True)
        self.assertNotEqual(before['digest'], self.identity()['digest'])

    def test_internal_symlink_target_change_invalidates(self):
        link = self.project / 'alias.php'
        link.symlink_to('tests/ScenarioTest.php')
        before = self.identity()
        link.unlink()
        link.symlink_to('check')
        self.assertNotEqual(before['digest'], self.identity()['digest'])

    def test_external_symlinks_are_refused(self):
        (self.project / 'outside').symlink_to('/etc/passwd')
        with self.assertRaises(ValueError):
            self.identity()

    def test_missing_or_unrelated_test_cannot_supply_evidence(self):
        result = self.run_checks([{**REFERENCE, 'test': 'it does something unrelated'}])
        self.assertTrue(result['passed'])
        self.assertEqual({}, result['evidence'])

    def test_source_edits_during_execution_invalidate_the_result(self):
        original = runner.execute
        def mutate(*args):
            result = original(*args)
            (self.project / 'tests/ScenarioTest.php').write_text('<?php // changed during checks')
            return result
        runner.execute = mutate
        try:
            result = self.run_checks()
            self.assertFalse(result['unchanged'])
            self.assertFalse(result['passed'])
        finally:
            runner.execute = original

    def test_reverted_workspace_edits_do_not_change_the_tested_snapshot(self):
        original = runner.execute
        def mutate_then_restore(command, cwd, log, remaining):
            path = self.project / 'tests/ScenarioTest.php'
            content = path.read_bytes()
            path.write_text('<?php // temporary edit')
            self.assertEqual(content, (cwd / 'tests/ScenarioTest.php').read_bytes())
            try:
                return original(command, cwd, log, remaining)
            finally:
                path.write_bytes(content)
        runner.execute = mutate_then_restore
        try:
            self.assertTrue(self.run_checks()['passed'])
        finally:
            runner.execute = original

    def test_interrupted_process_does_not_pass(self):
        (self.project / 'check').write_text('#!/bin/sh\nsleep 5\n')
        result = self.run_checks(seconds=0.2)
        self.assertFalse(result['passed'])
        self.assertEqual(124, result['checks'][0]['exit_code'])

    def test_skipped_report_is_not_evidence(self):
        report = self.root / 'report.xml'
        report.write_text('<testsuite><testcase name="it observes the result" file="tests/ScenarioTest.php::it observes the result" assertions="1"><skipped/></testcase></testsuite>')
        self.assertEqual({}, runner.evidence_from_report(self.root, PROJECT, report, [REFERENCE]))

    def test_malformed_report_is_not_repaired_into_success(self):
        report = self.root / 'report.xml'
        report.write_bytes(b'<testsuite>\xff</testsuite>')
        with self.assertRaises(runner.ET.ParseError):
            runner.evidence_from_report(self.root, PROJECT, report, [REFERENCE])


if __name__ == '__main__':
    unittest.main()
