#!/usr/bin/env python3
"""Behavioral controls for the host DDL observer, independent of Docker."""
import pathlib
import re
import subprocess
import tempfile

harness = pathlib.Path(__file__).parent.resolve() / 'docker'
with tempfile.TemporaryDirectory() as temp:
    root = pathlib.Path(temp)
    for name, state, worker_exit, column_exists, refusal, owner, session_alive, valid in [
        ('interrupted', 'altering table', 137, 0, 'DDL-CLAIM-REFUSED', 42, 1, True),
        ('session-already-ended', 'altering table', 137, 0, '', 42, 0, True),
        ('success-is-not-interruption', 'altering table', 0, 0, 'DDL-CLAIM-REFUSED', 42, 1, False),
        ('no-observation', '', 137, 0, 'DDL-CLAIM-REFUSED', 42, 1, False),
        ('ddl-already-completed', 'altering table', 137, 1, 'DDL-CLAIM-REFUSED', 42, 1, False),
        ('claim-not-refused', 'altering table', 137, 0, '', 42, 1, False),
        ('wrong-lock-owner', 'altering table', 137, 0, 'DDL-CLAIM-REFUSED', 41, 1, False),
    ]:
        art = root / name
        art.mkdir()
        script = r'''
source "$1/interrupt-ddl.sh"
HARNESS_DIR="$1"; ART="$2"; observation="$3"; worker_exit="$4"; column_exists="$5"; refusal="$6"; owner="$7"; CELL=test
session_alive="$8"
log() { return 0; }
dc() {
  if [ "$1" = cp ] && [ "$2" = wp:/tmp/ddl-worker.json ]; then printf '{"pid":123,"connection":42}' >"$3"; fi
  if [ "$1" = exec ] && [ "$5" = -0 ]; then return 1; fi
  return 0
}
wpc() {
  case "${3:-}" in
    lock) printf '{"name":"wpss_migrate_test","owner":%s,"hint":"token"}' "$owner"; return 0;;
    refused) printf '%s\n' "$refusal"; return 0;;
    status) printf 'DDL-NO-STATUS\n'; return 0;;
    *) return "$worker_exit";;
  esac
}
mysql_q() { [ -z "$observation" ] || printf '42\t%s\tALTER TABLE wp_slim_stats ADD COLUMN vid_hash BINARY(16)\n' "$observation"; }
scalar_q() { case "$1" in *COLUMNS*) printf '%s' "$column_exists";; *PROCESSLIST*) printf '%s' "$session_alive";; *IS_FREE_LOCK*) [ "$1" = "SELECT IS_FREE_LOCK('wpss_migrate_test');" ] || return 1; [ "$session_alive" = 0 ] && printf 1 || printf 0;; *) return 1;; esac; }
mysql_exec() { [ "$1" = 'KILL QUERY 42;' ] && session_alive=0; }
interrupt_migration_ddl
'''
        r = subprocess.run(['bash', '-c', script, 'test', str(harness), str(art), state, str(worker_exit), str(column_exists), refusal, str(owner), str(session_alive)], capture_output=True)
        assert (r.returncode == 0) == valid, (name, r.stdout, r.stderr)
print('PASS: observed DDL, killed worker, live-session refusal, lock release and missing status required')

# Execute the actual offered-UA PHP leg with a migration that refuses any call outside
# runOne(). A false batch result is resumable; a null lock refusal must abort the leg.
ua = re.search(r"UA=\$\(wpc eval '(.*?)' 2>/dev/null\)", (harness / 'rehearse-upgrade.sh').read_text(), re.S)
assert ua, 'offered-UA runner missing'
stubs = r'''
namespace SlimStat\Migration {
    class MigrationService { static function analyticsConnection() { return null; } }
    class MigrationManager {
        private $migration;
        public static $owned = false;
        function register($migration) { $this->migration = $migration; }
        function runOne($id) {
            if (getenv('UA_REFUSE') === '1') { return null; }
            self::$owned = true;
            try { return $this->migration->run(); } finally { self::$owned = false; }
        }
        function getRunRefusal() { return 'lock refused'; }
    }
}
namespace SlimStat\Migration\Migrations {
    class AddUserAgentDimension {
        private $passes = 0;
        function __construct($a, $core) {}
        function getId() { return 'add-user-agent-dimension'; }
        function shouldRun() { return $this->passes < 2; }
        function run() {
            if (!\SlimStat\Migration\MigrationManager::$owned) { throw new \RuntimeException('manager bypassed'); }
            return ++$this->passes === 2;
        }
    }
}
'''
for refuse in ('0', '1'):
    code = stubs + "\nnamespace { putenv('UA_REFUSE=" + refuse + "'); $GLOBALS['wpdb'] = null;\n" + ua[1] + '\n}'
    result = subprocess.run(['php', '-r', code], text=True, capture_output=True)
    if refuse == '0':
        assert result.returncode == 0 and re.fullmatch(r'done [0-9.]+ 2', result.stdout), result
    else:
        assert result.returncode != 0 and 'lock refused' in result.stderr + result.stdout, result
print('PASS: offered UA uses runOne, resumes incomplete batches and aborts lock refusal')
