"""Join fresh-process report cells without normalizing or discarding their values."""
import json
from pathlib import Path
import sys

CELLS = ('historical-unfiltered', 'historical-filtered', 'straddling-unfiltered', 'straddling-filtered')


def merge(snapshots):
    if len(snapshots) != len(CELLS):
        raise ValueError('Four independent report cells are required')
    first = snapshots[0]
    result = dict(first, cells={}, cell_contexts={}, capture_times={})
    coverage = dict.fromkeys(('total', 'errored', 'empty', 'render_only', 'value_compared'), 0)
    for snapshot in snapshots:
        for field in ('fingerprint_hash', 'stats_rows', 'anchor_date', 'report_ids', 'requested_live_window_end'):
            if field not in snapshot or snapshot[field] != first[field]:
                raise ValueError('Report capture context differs: ' + field)
        cells = snapshot['cells']
        if len(cells) != 1:
            raise ValueError('Each capture must contain one fresh-process cell')
        cell = next(iter(cells))
        if cell not in CELLS or cell in result['cells'] or set(cells[cell]) != set(first['report_ids']):
            raise ValueError('Missing, duplicate or unexpected report population')
        if cell.startswith('straddling') and snapshot['live_window_end'] != first['requested_live_window_end']:
            raise ValueError('Pinned report end differs')
        result['cells'].update(cells)
        result['cell_contexts'][cell] = snapshot['cell_contexts'][cell]
        result['capture_times'][cell] = {key: snapshot[key] for key in ('captured_at', 'captured_day')}
        for key in coverage:
            coverage[key] += snapshot['coverage'][key]
    if set(result['cells']) != set(CELLS):
        raise ValueError('Incomplete report cells')
    result['live_window_end'] = first['requested_live_window_end']
    result['coverage'] = dict(coverage, value_compared_proportion=round(coverage['value_compared'] / coverage['total'], 4))
    return result


if __name__ == '__main__':
    result = merge([json.loads(Path(path).read_text()) for path in sys.argv[2:]])
    with Path(sys.argv[1]).open('x') as output:
        output.write(json.dumps(result, ensure_ascii=False) + '\n')
