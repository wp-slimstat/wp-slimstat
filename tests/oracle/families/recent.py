"""Independent normalization for recent-row report answers."""


def recent_rows(rows):
    def value(item):
        if isinstance(item, bytes):
            return item.decode('utf-8', errors='replace')
        return item if item is None else str(item)

    return [{key: value(item) for key, item in sorted(row.items())} for row in rows]
