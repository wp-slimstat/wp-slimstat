#!/bin/sh
# A python3 that runs the export reader with its ORDER BY made inert, and everything else
# untouched — so `python3 --version` still answers and CONTROL 3 still passes.
#
# Needs SLIMSTAT_REAL_PYTHON3 (absolute) and SLIMSTAT_INERT_READER (absolute).
set -u

case "${1:-}" in
    *read_export_cli.py)
        shift
        exec "$SLIMSTAT_REAL_PYTHON3" "$SLIMSTAT_INERT_READER" "$@"
        ;;
esac

exec "$SLIMSTAT_REAL_PYTHON3" "$@"
