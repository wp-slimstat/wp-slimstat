"""Independent normalization for recent-row report answers."""

def recent_rows(rows):
    def value(item):
        if isinstance(item, bytes):
            return item.decode('utf-8', errors='replace')
        return item if item is None else str(item)

    return [{key: value(item) for key, item in sorted(row.items())} for row in rows]


def recent_events(stats, events, start, end):
    """Join event payloads to facts, applying the report's fact-time window."""
    facts = {row['id']: row for row in stats if start <= row['dt'] <= end}
    rows = []
    for event in events:
        fact, notes = facts.get(event['id']), event['notes']
        if fact is None or notes is None or (len(notes) >= 10 and notes[1:10].lower() == 'ype:click'):
            continue
        rows.append(dict(event, ip=fact['ip'], resource=fact['resource']))
    rows.sort(key=lambda row: row['dt'], reverse=True)
    return recent_rows(rows)
