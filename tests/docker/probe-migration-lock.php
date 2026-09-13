<?php
// Disposable live control for MigrationManager's session lock. Invoked by
// run-migration-lock-controls.sh inside an isolated WordPress cell.
if (!defined('ABSPATH')) { exit(2); }

$mode = $args[0] ?? '';
$id = $args[1] ?? '';
$seconds = isset($args[2]) ? (int) $args[2] : 0;
if (!in_array($mode, ['hold', 'ddl', 'refused', 'status', 'recover'], true)
    || !preg_match('/^a1-[a-z-]+$/', $id)) {
    throw new InvalidArgumentException('Invalid migration-lock control arguments');
}

$db = SlimStat\Migration\MigrationService::analyticsConnection();
$lock = 'wpss_migrate_' . md5($db->dbname . '|' . $GLOBALS['wpdb']->prefix);
$manager = new SlimStat\Migration\MigrationManager();

if ('status' === $mode) {
    if (array_key_exists($id, $manager->getStatus()) || in_array($id, $manager::completedMigrationIds(), true)) {
        throw new RuntimeException('Disconnected migration wrote completion status');
    }
    echo "A1-NO-STATUS\n";
    return;
}

$migration = new class ($db, $mode, $id, $seconds, $lock) implements SlimStat\Migration\MigrationInterface {
    private $db;
    private $mode;
    private $id;
    private $seconds;
    private $lock;

    public function __construct($db, string $mode, string $id, int $seconds, string $lock)
    {
        $this->db = $db;
        $this->mode = $mode;
        $this->id = $id;
        $this->seconds = $seconds;
        $this->lock = $lock;
    }
    public function getName(): string { return 'A1 live control'; }
    public function getId(): string { return $this->id; }
    public function getDescription(): string { return 'Disposable session-lock control'; }
    public function shouldRun(): bool { return true; }
    public function isOptional(): bool { return false; }
    public function getDiagnostics(): array { return []; }
    public function run(): bool
    {
        if ('refused' === $this->mode) {
            throw new RuntimeException('Contended migration body executed');
        }
        file_put_contents('/tmp/a1-owner.json', json_encode([
            'connection' => (int) $this->db->get_var('SELECT CONNECTION_ID()'),
            'lock' => $this->lock,
            'started' => time(),
            'handle' => get_class($this->db),
        ]));
        if ('ddl' === $this->mode) {
            return false !== $this->db->query('ALTER TABLE `wp_a1_lock_control` ADD COLUMN `disconnected` INT NULL');
        }
        return '0' === (string) $this->db->get_var($this->db->prepare('SELECT SLEEP(%d)', $this->seconds));
    }
};
$manager->register($migration);

if ('refused' === $mode) {
    if (null !== $manager->runOne($id) || !$manager->isRunContended()) {
        throw new RuntimeException('Live owner did not cause a contention refusal');
    }
    echo "A1-CLAIM-REFUSED\n";
    return;
}

$result = $manager->runOne($id);
echo 'A1-RESULT:' . json_encode([
    'mode' => $mode,
    'result' => $result,
    'refusal' => $manager->getRunRefusal(),
]) . "\n";
exit(true === $result ? 0 : 1);
