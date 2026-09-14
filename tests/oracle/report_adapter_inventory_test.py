#!/usr/bin/env python3
import json
from pathlib import Path

root = Path(__file__).resolve().parent
inventory = json.loads((root / 'report_adapter_inventory.json').read_text())
expected = json.loads((root / 'expected_population.json').read_text())
capture_source = (root.parent / 'docker/report-answers.php').read_text()
ids = inventory['report_ids']
captured = set(inventory['legacy_capture_by_report'])
extended = set(inventory['extended_capture_by_report'])
modeled = set(inventory['modeled'])
summary = inventory['summary']
assert len(ids) == len(set(ids)) == summary['reports'] == 67
captured |= extended
assert modeled <= captured <= set(ids)
assert summary == {'reports': 67, 'modeled': len(modeled),
                   'captured_unmodeled': len(captured - modeled),
                   'uncaptured': len(set(ids) - captured)}
surface_adapters = {row['key']: row['adapter'] for row in expected['surfaces']}
extended_surfaces = {surface for surfaces in inventory['extended_capture_by_report'].values() for surface in surfaces}
assert extended_surfaces | set(inventory['unmapped_nonreport_controls']) == {
    row['key'] for row in expected['surfaces'] if row['source'] == 'extended'}
for report, surfaces in inventory['modeled'].items():
    assert all(surface_adapters[surface] is not None for surface in surfaces), report
for surface in {item for rows in inventory['extended_capture_by_report'].values() for item in rows}:
    assert "'%s'" % surface in capture_source, surface

print('PASS: 67-report adapter inventory is complete and partitioned')
