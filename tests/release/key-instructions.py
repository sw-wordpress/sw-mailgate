#!/usr/bin/env python3
"""Exercise all three documented blocks with disposable keys and a local gh double."""
import os
from pathlib import Path
import re
import subprocess
import tempfile

root = Path(__file__).resolve().parents[2]
blocks = re.findall(r'```bash\n(.*?)```', (root / 'docs/GITHUB-UPDATES-BETRIEB.md').read_text(), re.S)[:3]
assert len(blocks) == 3
for shell in ['bash', 'zsh']:
    with tempfile.TemporaryDirectory(prefix='swmg-key-doc-') as directory:
        work = Path(directory)
        plugin = work / 'plugin'
        target = plugin / 'src/Update/Config/trusted-keys.php'
        target.parent.mkdir(parents=True)
        target.write_text('<?php return array("existing" => base64_encode(str_repeat("a",32)));')
        subprocess.run(['git', 'init', '-q', str(plugin)], check=True)
        keys = work / 'private-keys'
        commands = [b.replace('$HOME/.local/share/sw-mailgate-release-keys', str(keys)).replace('/var/www/html/wordpress_dev/wp-content/plugins/sw-mailgate', str(plugin)) for b in blocks]
        # No GitHub call: assert stdin, repository, environment and public key ID via the double.
        mock = work / 'gh'
        mock.write_text('''#!/usr/bin/env python3
import os, sys
from pathlib import Path
args = sys.argv[1:]
assert args == ['auth', 'status'] or ('sw-wordpress/sw-mailgate' in args and 'plugin-releases' in args)
if args[:2] == ['secret', 'set']:
    assert args[2] == 'SW_PLUGIN_RELEASE_SIGNING_KEY'
    assert sys.stdin.read() == Path(os.environ['SWMG_DOC_PRIVATE_FILE']).read_text()
if args[:2] == ['variable', 'set']:
    assert args[2] == 'SW_PLUGIN_RELEASE_KEY_ID'
    assert args[-1] == 'sw-mailgate-2026-11'
print('Mock GitHub operation verified.')
''')
        mock.chmod(0o700)
        env = os.environ | {'PATH': str(work) + ':' + os.environ['PATH'], 'SWMG_DOC_PRIVATE_FILE': str(keys / 'signing-key.base64')}
        def run(command, succeeds=True):
            result = subprocess.run([shell, '-c', command], env=env, capture_output=True, text=True)
            assert (result.returncode == 0) == succeeds, result.stderr
            if keys.exists():
                secret = (keys / 'signing-key.base64').read_text().strip()
                assert secret not in result.stdout + result.stderr
        run(commands[0])
        assert keys.stat().st_mode & 0o777 == 0o700
        for p in keys.iterdir():
            assert p.stat().st_mode & 0o777 == 0o600
        original = (keys / 'signing-key.base64').read_bytes()
        run(commands[0], False)
        assert (keys / 'signing-key.base64').read_bytes() == original
        run(commands[1])
        ring = target.read_bytes()
        assert b'existing' in ring and b'sw-mailgate-2026-11' in ring
        assert original.strip() not in ring
        run(commands[1])
        assert target.read_bytes() == ring
        target.write_text('<?php return array("sw-mailgate-2026-11" => base64_encode(str_repeat("x",32)));')
        conflict = target.read_bytes()
        run(commands[1], False)
        assert target.read_bytes() == conflict
        target.write_bytes(ring)
        run(commands[2])
print('PASS: Bash/Zsh key instructions, modes, overwrite/conflict rejection, key preservation, stdin; no GitHub secrets changed.')
