#!/usr/bin/env python3
"""Exercise make release with real Git commits/tags and disposable local remotes."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
ENV = os.environ | {
    'GIT_CONFIG_GLOBAL': os.devnull,
    'GIT_CONFIG_NOSYSTEM': '1',
    'GIT_TERMINAL_PROMPT': '0',
}


def run(directory, *args, succeeds=True):
    result = subprocess.run(args, cwd=directory, env=ENV, capture_output=True, text=True)
    assert (result.returncode == 0) == succeeds, result.stdout + result.stderr
    return result.stdout.strip()


def fixture(directory):
    remote = directory / 'origin.git'
    work = directory / 'plugin'
    run(directory, 'git', 'init', '--bare', '-q', str(remote))
    run(directory, 'git', 'init', '-q', '-b', 'main', str(work))
    run(work, 'git', 'config', 'user.email', 'release-test@example.org')
    run(work, 'git', 'config', 'user.name', 'Release Test')
    (work / 'bin').mkdir()
    for file in ['Makefile', 'bin/release.sh', 'sw-mailgate.php']:
        shutil.copyfile(ROOT / file, work / file)
    run(work, 'git', 'add', '.')
    run(work, 'git', 'commit', '-qm', 'Initial fixture')
    run(work, 'git', 'remote', 'add', 'origin', str(remote))
    run(work, 'git', 'push', '-q', 'origin', 'HEAD:refs/heads/main')
    return work, remote


def snapshot(work, remote):
    return (
        run(work, 'git', 'rev-parse', 'HEAD'),
        run(work, 'git', 'status', '--porcelain', '--untracked-files=all'),
        run(work, 'git', 'show-ref'),
        (work / 'sw-mailgate.php').read_bytes(),
        run(remote, 'git', 'show-ref'),
    )


cases = ['missing', 'invalid', 'downgrade', 'dirty', 'untracked', 'detached',
         'local-tag', 'remote-tag', 'unreachable', 'inconsistent', 'duplicate-header',
         'atomic-rejection', 'release', 'four-part', 'same-version']
for case in cases:
    with tempfile.TemporaryDirectory(prefix='swmg-release-') as directory:
        work, remote = fixture(Path(directory))
        version = '1.2.3'
        plugin = work / 'sw-mailgate.php'
        current = run(work, 'php', '-r',
                      '$s=file_get_contents("sw-mailgate.php"); '
                      'preg_match("/Version:\\s+([0-9.]+)/",$s,$m); echo $m[1];')
        if case == 'missing':
            version = ''
        elif case == 'invalid':
            version = '1.2.3-rc1'
        elif case == 'downgrade':
            version = '0.0.1'
        elif case == 'dirty':
            plugin.write_text(plugin.read_text() + '\n// dirty\n')
        elif case == 'untracked':
            (work / 'untracked.txt').write_text('must not be released')
        elif case == 'detached':
            run(work, 'git', 'checkout', '-q', '--detach')
        elif case in ['local-tag', 'remote-tag']:
            run(work, 'git', 'tag', version)
            if case == 'remote-tag':
                run(work, 'git', 'push', '-q', 'origin', 'refs/tags/' + version)
                run(work, 'git', 'tag', '-d', version)
        elif case == 'unreachable':
            run(work, 'git', 'remote', 'set-url', 'origin', str(Path(directory) / 'missing.git'))
        elif case in ['inconsistent', 'duplicate-header']:
            source = plugin.read_text()
            if case == 'inconsistent':
                source = source.replace("define( 'SW_MAILGATE_VERSION', '" + current,
                                        "define( 'SW_MAILGATE_VERSION', '0.0.1")
            else:
                source += '\n/*\n * Version: ' + current + '\n */\n'
            plugin.write_text(source)
            run(work, 'git', 'add', 'sw-mailgate.php')
            run(work, 'git', 'commit', '-qm', 'Invalid fixture version')
        elif case == 'atomic-rejection':
            hook = remote / 'hooks/update'
            hook.write_text('#!/bin/sh\ncase "$1" in refs/tags/*) exit 1;; esac\n')
            hook.chmod(0o700)
        elif case == 'four-part':
            version = '1.2.3.4'
        elif case == 'same-version':
            version = current

        before = snapshot(work, remote)
        succeeds = case in ['release', 'four-part', 'same-version']
        run(work, 'make', 'release', 'VERSION=' + version, succeeds=succeeds)
        after = snapshot(work, remote)
        if succeeds:
            head = run(work, 'git', 'rev-parse', 'HEAD')
            assert run(remote, 'git', 'rev-parse', 'refs/heads/main') == head
            assert run(remote, 'git', 'rev-parse', 'refs/tags/' + version + '^{}') == head
            assert run(remote, 'git', 'cat-file', '-t', 'refs/tags/' + version) == 'tag'
            assert run(work, 'git', 'status', '--porcelain') == ''
            assert '* Version:           ' + version in plugin.read_text()
            assert "define( 'SW_MAILGATE_VERSION', '" + version + "' );" in plugin.read_text()
            assert (head == before[0]) == (case == 'same-version')
            if case != 'same-version':
                assert run(work, 'git', 'log', '-1', '--format=%s') == 'Release ' + version
                assert run(work, 'git', 'diff-tree', '--no-commit-id', '--name-only', '-r', 'HEAD') == 'sw-mailgate.php'
        elif case == 'atomic-rejection':
            assert after[4] == before[4], 'Rejected atomic push changed remote refs'
            assert run(work, 'git', 'rev-parse', 'refs/tags/' + version + '^{}') == after[0]
        else:
            assert after == before, 'Rejected release changed local files or refs'
        print('PASS:', case)

print('PASS: make release validation, commits, annotated tags and atomic local-remote pushes.')
