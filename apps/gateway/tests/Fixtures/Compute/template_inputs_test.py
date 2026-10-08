import copy
import hashlib
import io
import json
from pathlib import Path
import runpy
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

path = Path(sys.argv.pop(1))
module = runpy.run_path(str(path))
verify, inspect_archive = module['verify'], module['inspect_archive']


def archive(entries):
    output = io.BytesIO()
    with tarfile.open(fileobj=output, mode='w:gz') as target:
        for name, kind, value, *mode in entries:
            member = tarfile.TarInfo(name)
            member.type = kind
            member.mode = mode[0] if mode else (0o755 if kind == tarfile.DIRTYPE else 0o644)
            if kind == tarfile.REGTYPE:
                member.size = len(value)
                target.addfile(member, io.BytesIO(value))
            else:
                member.linkname = value
                target.addfile(member)
    output.seek(0)
    return output


class InputsTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.request = {'root': str(self.root), 'packages': [self.file('php_1%3a2.deb', b'package')],
                        'composer': self.file('composer.phar', b'composer'),
                        'source': self.file('source.tar.gz', archive([('file', tarfile.REGTYPE, b'source')]).getvalue()),
                        'tools': self.file('tools.tar.gz', archive([('usr/local/bin/bun', tarfile.REGTYPE, b'binary')]).getvalue())}

    def file(self, name, contents):
        (self.root / name).write_bytes(contents)
        return {'file': name, 'sha256': hashlib.sha256(contents).hexdigest()}

    def test_closed_input_set_is_verified_without_extracting(self):
        before = sorted(p.name for p in self.root.iterdir())
        report = verify(self.request)
        self.assertTrue(report['verified'])
        self.assertEqual(report['packages'], 1)
        self.assertEqual(len(report['inputs']), 4)
        self.assertEqual(sorted(p.name for p in self.root.iterdir()), before)

    def test_tampering_is_refused(self):
        (self.root / 'composer.phar').write_bytes(b'changed')
        with self.assertRaises(ValueError):
            verify(self.request)

    def test_schema_traversal_duplicates_and_apt_colons_are_refused(self):
        mutations = [lambda r: r.update(extra=True), lambda r: r['composer'].update(extra=True),
                     lambda r: r['composer'].update(file='../composer.phar'),
                     lambda r: r['packages'].append(copy.deepcopy(r['packages'][0])),
                     lambda r: r['packages'][0].update(file='php_1:2.deb')]
        for mutate in mutations:
            with self.subTest(mutate=mutate):
                request = copy.deepcopy(self.request)
                mutate(request)
                with self.assertRaises(ValueError):
                    verify(request)

    def test_symlink_inputs_and_directories_are_refused(self):
        (self.root / 'composer.phar').unlink()
        (self.root / 'composer.phar').symlink_to('php_1%3a2.deb')
        with self.assertRaises(ValueError):
            verify(self.request)
        (self.root / 'composer.phar').unlink()
        (self.root / 'composer.phar').mkdir()
        with self.assertRaises(ValueError):
            verify(self.request)

    def test_internal_relative_links_and_backward_hardlinks_are_accepted(self):
        stream = archive([('pkg/file', tarfile.REGTYPE, b'x'), ('copy', tarfile.LNKTYPE, 'pkg/file'),
                          ('bin/tool', tarfile.SYMTYPE, '../pkg/file')])
        self.assertEqual(inspect_archive(stream, 'source'), {'members': 3, 'bytes': 1, 'hardlinks': 1, 'symlinks': 1})

    def test_unsafe_archive_entries_are_refused(self):
        cases = [[('privileged', tarfile.REGTYPE, b'x', 0o4755)], [('../outside', tarfile.REGTYPE, b'x')], [('/absolute', tarfile.REGTYPE, b'x')],
                 [('device', tarfile.CHRTYPE, '')], [('pipe', tarfile.FIFOTYPE, '')],
                 [('link', tarfile.SYMTYPE, '/outside')], [('link', tarfile.SYMTYPE, '../outside')],
                 [('first', tarfile.LNKTYPE, 'later'), ('later', tarfile.REGTYPE, b'x')],
                 [('same', tarfile.REGTYPE, b'a'), ('./same', tarfile.REGTYPE, b'b')],
                 [('parent', tarfile.SYMTYPE, 'other'), ('parent/file', tarfile.REGTYPE, b'x')],
                 [('parent/file', tarfile.REGTYPE, b'x'), ('parent', tarfile.SYMTYPE, 'other')],
                 [('link', tarfile.SYMTYPE, 'later/../outside'), ('later', tarfile.SYMTYPE, '.')],
                 [('one', tarfile.SYMTYPE, 'two'), ('two', tarfile.SYMTYPE, 'one')]]
        for entries in cases:
            with self.subTest(entries=entries), self.assertRaises(ValueError):
                inspect_archive(archive(entries), 'source')

    def test_tool_archive_cannot_install_arbitrary_host_paths(self):
        for entries in [[('etc/sudoers', tarfile.REGTYPE, b'x')],
                        [('opt/orbit-image/node/link', tarfile.SYMTYPE, '../../../etc')]]:
            with self.subTest(entries=entries), self.assertRaises(ValueError):
                inspect_archive(archive(entries), 'tools')

    def test_pinned_pnpm_tree_is_allowed_without_allowing_links_outside_tool_trees(self):
        report = inspect_archive(archive([('opt/orbit-image/pnpm/bin/pnpm.cjs', tarfile.REGTYPE, b'pnpm')]), 'tools')
        self.assertEqual(1, report['members'])
        with self.assertRaises(ValueError):
            inspect_archive(archive([('opt/orbit-image/pnpm/link', tarfile.SYMTYPE, '../../../etc')]), 'tools')

    def test_archive_limits_and_empty_archives_are_refused(self):
        with self.assertRaises(ValueError):
            inspect_archive(archive([]), 'source')
        with patch.dict(inspect_archive.__globals__, MAX_MEMBERS=1), self.assertRaises(ValueError):
            inspect_archive(archive([('one', tarfile.REGTYPE, b'a'), ('two', tarfile.REGTYPE, b'b')]), 'source')
        with patch.dict(inspect_archive.__globals__, MAX_BYTES=1), self.assertRaises(ValueError):
            inspect_archive(archive([('large', tarfile.REGTYPE, b'ab')]), 'source')

    def test_corrupt_archives_have_fixed_failure_output(self):
        self.request['source'] = self.file('source.tar.gz', b'not an archive')
        result = subprocess.run([sys.executable, str(path), '--check'], input=json.dumps(self.request), capture_output=True, text=True)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(json.loads(result.stdout), {'error': 'sandbox_template_inputs_invalid'})
        self.assertEqual(result.stderr, '')

    def test_failure_output_does_not_disclose_inputs(self):
        self.request['root'] = '/private-secret-path'
        result = subprocess.run([sys.executable, str(path), '--check'], input=json.dumps(self.request), capture_output=True, text=True)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(json.loads(result.stdout), {'error': 'sandbox_template_inputs_invalid'})
        self.assertEqual(result.stderr, '')


if __name__ == '__main__':
    unittest.main()
