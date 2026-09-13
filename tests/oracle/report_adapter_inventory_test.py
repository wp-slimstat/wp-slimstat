#!/usr/bin/env python3
import json
from pathlib import Path

root = Path(__file__).resolve().parent
inventory = json.loads((root / 'report_adapter_inventory.json').read_text())
expected = json.loads((root / 'expected_population.json').read_text())
ids = inventory['report_ids']
captured = set(inventory['legacy_capture_by_report'])
modeled = set(inventory['modeled'])
summary = inventory['summary']
assert len(ids) == len(set(ids)) == summary['reports'] == 67
assert modeled <= captured <= set(ids)
assert summary == {'reports': 67, 'modeled': len(modeled),
                   'captured_unmodeled': len(captured - modeled),
                   'uncaptured': len(set(ids) - captured)}
surface_adapters = {row['key']: row['adapter'] for row in expected['surfaces']}
for report, surfaces in inventory['modeled'].items():
    assert all(surface_adapters[surface] is not None for surface in surfaces), report

print('PASS: 67-report adapter inventory is complete and partitioned')
