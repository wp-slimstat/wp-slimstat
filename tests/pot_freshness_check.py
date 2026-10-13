import importlib.util
from pathlib import Path
import shutil
import tempfile

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('pot', root / 'tests/check-pot.py')
pot = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pot)
with tempfile.TemporaryDirectory(prefix='pot-required-red-') as tmp:
    stage = Path(tmp) / 'source'
    shutil.copytree(root, stage, ignore=shutil.ignore_patterns('.git', 'vendor', 'node_modules', '__pycache__'))
    first, second = Path(tmp) / 'first.pot', Path(tmp) / 'second.pot'
    pot.generate(stage, first)
    pot.generate(stage, second)
    assert pot.messages(first) == pot.messages(second), 'generation changed message semantics'
    pot.check(stage)
    domain = 'wp-slimstat-pro' if (root / 'wp-slimstat-pro.php').exists() else 'wp-slimstat'
    (stage / 'planted-stale-string.php').write_text("<?php __('Deliberately planted catalog freshness failure 739251', '" + domain + "');\n")
    try:
        pot.check(stage)
    except ValueError as error:
        assert 'Deliberately planted catalog freshness failure 739251' in str(error)
    else:
        raise AssertionError('stale source accepted')
print('PASS: fresh catalog, repeated generation, deliberately stale source rejected')
