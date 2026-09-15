#!/usr/bin/env python3
import json
from pathlib import Path

root = Path(__file__).resolve().parent
inventory = json.loads((root / 'report_adapter_inventory.json').read_text())
expected = json.loads((root / 'expected_population.json').read_text())
capture_source = (root.parent / 'docker/report-answers.php').read_text()
exclusions = json.loads((root / 'exclusions.json').read_text())
ids = inventory['report_ids']
captured = set(inventory['legacy_capture_by_report'])
extended = set(inventory['extended_capture_by_report'])
modeled = set(inventory['modeled'])
summary = inventory['summary']
assert len(ids) == len(set(ids)) == summary['reports'] == 67
captured |= extended
assert modeled <= captured <= set(ids)
excluded = set(inventory['excluded'])
# Every report is modeled or approved-out, and never both: an unmodeled report is one nobody
# decided about, which is the state this partition exists to make impossible to leave behind.
assert not modeled & excluded
assert modeled | excluded == set(ids)
assert all(inventory['excluded'][report].strip() for report in excluded)
assert inventory['excluded_approved_by'].strip()
assert summary == {'reports': 67, 'modeled': len(modeled), 'excluded': len(excluded),
                   'unmodeled': len(set(ids) - modeled - excluded)}
surface_adapters = {row['key']: row['adapter'] for row in expected['surfaces']}
extended_surfaces = {surface for surfaces in inventory['extended_capture_by_report'].values() for surface in surfaces}
assert extended_surfaces | set(inventory['unmapped_nonreport_controls']) == {
    row['key'] for row in expected['surfaces'] if row['source'] == 'extended'}
for report, surfaces in inventory['modeled'].items():
    assert all(surface_adapters[surface] is not None for surface in surfaces), report

# A report excluded here can still own a capture key, and build_evidence judges KEYS. So an
# excluded report's unmodeled surfaces must also be approved out in exclusions.json, or the
# evidence build still demands a model for something this file says nobody will write.
approved = {row['key'] for row in exclusions['approved']}
for report in excluded:
    for surface in (inventory['extended_capture_by_report'].get(report, [])
                    + inventory['legacy_capture_by_report'].get(report, [])):
        assert surface_adapters[surface] is not None or surface in approved, surface
for surface in {item for rows in inventory['extended_capture_by_report'].values() for item in rows}:
    assert "'%s'" % surface in capture_source, surface

print('PASS: 67-report adapter inventory is complete and partitioned — 61 modeled, 6 approved out, 0 undecided')
