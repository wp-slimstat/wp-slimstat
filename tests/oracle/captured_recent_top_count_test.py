#!/usr/bin/env python3
"""Hand-derived edge cases for the remaining captured family surfaces."""

from families.count import count_singletons
from families.recent import recent_events
from families.top import top_events, top_outbound


stats = [
    {'id': 1, 'ip': 'a', 'resource': '/a', 'visit_id': 10, 'browser_type': 0,
     'content_type': 'post', 'outbound_resource': 'https://a/;;;https://b/', 'dt_out': 0, 'dt': 10},
    {'id': 2, 'ip': 'b', 'resource': '/A ', 'visit_id': 11, 'browser_type': 0,
     'content_type': 'post', 'outbound_resource': 'https://a/', 'dt_out': 25, 'dt': 20},
    {'id': 3, 'ip': 'c', 'resource': '/c', 'visit_id': 11, 'browser_type': 1,
     'content_type': '404', 'outbound_resource': None, 'dt_out': 0, 'dt': 30},
    {'id': 4, 'ip': None, 'resource': None, 'visit_id': 12, 'browser_type': 0,
     'content_type': 'post', 'outbound_resource': None, 'dt_out': 0, 'dt': 30},
]
events = [
    {'event_id': 2, 'id': 2, 'type': 2, 'event_description': 'second', 'notes': 'event:b', 'position': '2,2', 'dt': 21},
    {'event_id': 1, 'id': 1, 'type': 2, 'event_description': 'first', 'notes': 'event:a', 'position': '1,1', 'dt': 11},
    {'event_id': 3, 'id': 3, 'type': 1, 'event_description': 'click', 'notes': 'type:click', 'position': '3,3', 'dt': 31},
]

assert recent_events(stats, events, 10, 20) == [
    {'dt': '21', 'event_description': 'second', 'event_id': '2', 'id': '2', 'ip': 'b',
     'notes': 'event:b', 'position': '2,2', 'resource': '/A ', 'type': '2'},
    {'dt': '11', 'event_description': 'first', 'event_id': '1', 'id': '1', 'ip': 'a',
     'notes': 'event:a', 'position': '1,1', 'resource': '/a', 'type': '2'},
]
assert top_events(events, 10, 30, 200) == [
    {'counthits': 1, 'notes': 'event:a'}, {'counthits': 1, 'notes': 'event:b'}]
assert top_outbound(stats, 10, 30, 2) == [
    {'counthits': 2, 'dt': 25, 'outbound_resource': 'https://a/'},
    {'counthits': 1, 'dt': 10, 'outbound_resource': 'https://b/'},
]

assert count_singletons(stats, 'visit_id', 'id', [('visit_id', 'gt', 0),
    ('browser_type', 'ne', 1)], 10, 30) == 3
assert count_singletons(stats, 'resource', 'visit_id', [('visit_id', 'gt', 0),
    ('content_type', 'ne', '404')], 10, 30, 'ascii_ci') == 1

try:
    count_singletons([{'resource': '/café', 'visit_id': 1, 'dt': 10}], 'resource',
                     'visit_id', [], 10, 10, 'ascii_ci')
    raise AssertionError('non-ASCII grouped collation was guessed')
except ValueError:
    pass

try:
    count_singletons(stats, 'visit_id', 'id', [('visit_id', 'wat', 0)], 10, 30)
    raise AssertionError('unknown singleton predicate passed')
except ValueError:
    pass

print('PASS: event, outbound and singleton models preserve joins, windows, NULLs, limits and collation refusal')
