"""Deterministic S5 lint: oracle families may consume values, never production machinery."""

import ast
import pathlib
import re
import sys

ALLOWED_IMPORT_ROOTS = {
    "collections", "dataclasses", "decimal", "functools", "itertools", "math",
    "statistics", "typing",
}
FORBIDDEN_CALLS = {
    "open": "production-read",
    "exec": "dynamic-execution",
    "eval": "dynamic-execution",
    "compile": "dynamic-execution",
    "__import__": "dynamic-import",
}
FORBIDDEN_METHODS = {
    "execute": "sql-execution",
    "executemany": "sql-execution",
    "cursor": "sql-execution",
    "query": "sql-execution",
    "prepare": "sql-execution",
    "get_results": "plugin-db-call",
    "get_var": "plugin-db-call",
}
STRING_RULES = (
    ("plugin-symbol", re.compile(r"\bwp_slimstat(?:_db|_reports)?\b", re.I)),
    ("production-path", re.compile(r"(?:^|/)(?:src|admin|includes)/|\.php\b", re.I)),
    ("sql-text", re.compile(r"\b(?:SELECT\s+.+\s+FROM|INSERT\s+INTO|UPDATE\s+.+\s+SET|DELETE\s+FROM|GROUP\s+BY|ORDER\s+BY)\b", re.I | re.S)),
    ("capture-output", re.compile(r"(?:arm-[12]|before|after)-(?:answers|caps|timing)|report-answers", re.I)),
)


def dotted_name(node):
    parts = []
    while isinstance(node, ast.Attribute):
        parts.append(node.attr)
        node = node.value
    if isinstance(node, ast.Name):
        parts.append(node.id)
    return ".".join(reversed(parts))


def scan_source(source, label="<memory>"):
    problems = []
    try:
        tree = ast.parse(source, filename=label)
    except SyntaxError as exc:
        return ["syntax:%s:%d:%s" % (label, exc.lineno or 0, exc.msg)]

    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            for alias in node.names:
                root = alias.name.split(".", 1)[0]
                if root not in ALLOWED_IMPORT_ROOTS:
                    problems.append("forbidden-import:%s:%d:%s" % (label, node.lineno, alias.name))
        elif isinstance(node, ast.ImportFrom):
            if node.level:
                problems.append("relative-import:%s:%d" % (label, node.lineno))
            else:
                root = (node.module or "").split(".", 1)[0]
                if root not in ALLOWED_IMPORT_ROOTS:
                    problems.append("forbidden-import:%s:%d:%s" % (label, node.lineno, node.module))
        elif isinstance(node, ast.Call):
            name = dotted_name(node.func)
            leaf = name.rsplit(".", 1)[-1]
            if name in FORBIDDEN_CALLS:
                problems.append("%s:%s:%d:%s" % (FORBIDDEN_CALLS[name], label, node.lineno, name))
            elif leaf in FORBIDDEN_METHODS:
                problems.append("%s:%s:%d:%s" % (FORBIDDEN_METHODS[leaf], label, node.lineno, name))
        elif isinstance(node, ast.Constant) and isinstance(node.value, str):
            for rule, pattern in STRING_RULES:
                if pattern.search(node.value):
                    problems.append("%s:%s:%d" % (rule, label, node.lineno))

    return sorted(set(problems))


def selftest():
    fixtures = {
        "plugin-symbol": "name = 'wp_slimstat_db'\n",
        "production-path": "path = 'src/Modules/Chart.php'\n",
        "sql-text": "statement = 'SELECT resource FROM stats ORDER BY resource'\n",
        "production-read": "data = open('fixture.json').read()\n",
        "dynamic-import": "module = __import__('anything')\n",
        "sql-execution": "connection.execute('x')\n",
        "capture-output": "path = 'arm-1-answers.json'\n",
        "forbidden-import": "import sqlite3\n",
    }
    failures = []
    for expected, source in fixtures.items():
        found = scan_source(source, "selftest-%s" % expected)
        if not any(problem.startswith(expected + ":") for problem in found):
            failures.append("selftest %s did not fire: %r" % (expected, found))
    clean = scan_source("def answer(rows):\n    return list(rows)\n", "selftest-clean")
    if clean:
        failures.append("clean positive control failed: %r" % clean)
    return failures


def main():
    root = pathlib.Path(__file__).resolve().parent
    files = sorted((root / "families").glob("*.py"))
    failures = selftest()
    if not files:
        failures.append("no oracle family sources found")
    for path in files:
        failures.extend(scan_source(path.read_text(), str(path.relative_to(root.parent.parent))))
    if failures:
        print("FAIL: oracle independence (%d problem(s))" % len(failures), file=sys.stderr)
        for problem in failures:
            print("  - " + problem, file=sys.stderr)
        return 1
    print("PASS: oracle independence — %d family source(s), 8 required-red controls and 1 clean control" % len(files))
    return 0


if __name__ == "__main__":
    sys.exit(main())
