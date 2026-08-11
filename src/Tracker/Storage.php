<?php

namespace SlimStat\Tracker;

use SlimStat\Utils\Query;

class Storage
{
	/**
	 * Store one row.
	 *
	 * @return WriteResult Never a bare int: see WriteResult for why 0 and false could not
	 *                     be told apart, and what that cost (C30).
	 */
	public static function insertRow($data = [], $table = '')
	{
		if (empty($data) || empty($table)) {
			return WriteResult::ignored();
		}

		foreach ($data as $key => $value) {
			$data[$key] = 'resource' == $key ? sanitize_url($value) : sanitize_text_field($value);
		}

		// vid_hash travels through $stat as 32 hex chars — the one spelling that survives
		// sanitize_text_field() above, filters, and log lines — and is packed to the raw
		// 16 bytes BINARY(16) stores only here, at the terminal, AFTER sanitization.
		// Anything that is not exactly 32 hex chars is dropped rather than stored: a
		// mangled identity matching nobody is strictly worse than no identity.
		if (isset($data['vid_hash'])) {
			if (preg_match('/^[0-9a-f]{32}$/i', (string) $data['vid_hash'])) {
				$data['vid_hash'] = hex2bin((string) $data['vid_hash']);
			} else {
				unset($data['vid_hash']);
			}
		}

		$result = self::write($table, $data);

		// S6 — the window where the code is v6 and the schema is still v5.
		//
		// init_environment() uses CREATE TABLE IF NOT EXISTS, which can create a missing
		// TABLE and can never add a missing COLUMN. So an INSERT naming a column this
		// schema does not have yet failed, retried identically, and the pageview was
		// DROPPED — with the only trace overwritten by the next hit. The window is
		// unbounded: auto-updates and WP-CLI produce no admin request, so a site whose
		// owner does not log in for a week serves traffic in it for a week.
		//
		// Decision P1: store the INTERSECTION of wanted and present columns. Losing a
		// field is better than losing the pageview.
		//
		// Reactive, never preemptive: the tracker budget is denominated in queries and
		// wp_options writes, and neither may move on the path that always runs.
		if ($result->isFailed() && self::isUnknownColumnError($result->error())) {
			$present  = self::presentColumns($table);
			$writable = array_intersect_key($data, array_flip($present));

			// Empty means every wanted column is absent, or the probe could not read the
			// table — nothing to retry with. Equal counts mean nothing was dropped, so the
			// retry would be the identical failing statement. array_intersect_key can only
			// shrink, hence `<`.
			if ($writable !== [] && count($writable) < count($data)) {
				self::recordColumnDegradation($table, array_diff(array_keys($data), $present));
				$result = self::write($table, $writable);
			}
		}

		return $result;
	}

	/**
	 * One INSERT, classified.
	 *
	 * The error is read from $wpdb rather than inferred from the return value, and that is
	 * load-bearing — C30 is worse than "0 reads as success".
	 *
	 * `Query::execute()` returns `insert_id ?: $result`, and $wpdb->insert_id KEEPS THE
	 * PREVIOUS SUCCESSFUL INSERT'S ID when a later statement fails (wpdb returns false
	 * without touching it, and MySQL does not reset LAST_INSERT_ID on a failed statement).
	 * So on the tracking path — where a pageview insert is followed by an event insert on
	 * the same connection — a FAILED event write returns `100 ?: false` = 100, the
	 * PAGEVIEW's id, and reads as a stored event. Caught by the regression test for this
	 * seam, not by reading the code.
	 *
	 * wpdb::query() calls flush() first, which resets last_error to '', so a non-empty
	 * last_error after the call belongs to this statement and no earlier one.
	 */
	private static function write($table, array $data)
	{
		$outcome = Query::insert($table)
			->ignore()
			->values($data)
			->execute();

		$error = (string) $GLOBALS['wpdb']->last_error;

		if (false === $outcome || '' !== $error) {
			return WriteResult::failed($error);
		}

		return $outcome > 0 ? WriteResult::stored($outcome) : WriteResult::ignored();
	}

	/**
	 * Is this error "the column is not there", as opposed to a deadlock or a full disk?
	 *
	 * Matched on the text because wpdb exposes no error code — the same reason
	 * Processor::repairSchemaOnce() matches ER_NO_SUCH_TABLE by text. Narrow on purpose:
	 * retrying a deadlock with fewer columns fixes nothing and doubles the write.
	 */
	private static function isUnknownColumnError($error)
	{
		return (bool) preg_match('/unknown column|1054/i', (string) $error);
	}

	/**
	 * Columns the table actually has, memoised per request.
	 *
	 * Static rather than an option: this is a fact about the schema in front of us right
	 * now, and caching it anywhere durable would survive the migration that fixes it.
	 *
	 * @return string[]
	 */
	private static function presentColumns($table)
	{
		static $columns = [];

		if (!array_key_exists($table, $columns)) {
			// Suppressed like every other fail-allowed probe in the tree (OptionClaim,
			// AbstractIndexMigration, PurgeArchive): wpdb::query() calls print_error()
			// under WP_DEBUG_DISPLAY, which would emit an HTML error block into an
			// anonymous tracking response.
			$db         = $GLOBALS['wpdb'];
			$suppressed = $db->suppress_errors(true);
			$found      = $db->get_col('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
			$db->suppress_errors($suppressed);
			// A probe that could not read the table answers "unknown", not "none" — an
			// empty list here would make the intersection empty and drop the whole row,
			// which is the get_var()-null-conflation family all over again.
			$columns[$table] = is_array($found) ? $found : [];
		}

		return $columns[$table];
	}

	/**
	 * Report the schema gap.
	 *
	 * No local de-dupe: record_degradation() already skips the write when step and message
	 * match inside DEGRADATION_REFRESH, and a static guard here would be worse than
	 * redundant — in a WP-CLI import running past DEGRADATION_TTL it suppresses every later
	 * call, so the record is never re-stamped and the admin notice goes quiet while the
	 * process is still dropping columns.
	 */
	private static function recordColumnDegradation($table, array $missing)
	{
		\wp_slimstat::record_degradation(
			'tracker write dropped columns absent from ' . $table,
			implode(', ', $missing)
		);
	}

	/**
	 * The SECOND write path — every dt_out heartbeat, every `;;;` outbound append, every
	 * `[k:v]` note, every content_type correction.
	 *
	 * C31: this discarded $query->execute() entirely and returned the id it was handed, so
	 * a failure here was not merely unhandled — it was not REPRESENTABLE. That matters
	 * beyond tidiness: slim_visits' exact bounce and duration are computed from dt_out,
	 * which is written minutes later by a different request through this method, so it is
	 * the one column an insert-time dual write can never carry.
	 *
	 * @return WriteResult
	 */
	public static function updateRow($data = [])
	{
		if (empty($data) || empty($data['id'])) {
			return WriteResult::ignored();
		}

		$id = abs(intval($data['id']));
		unset($data['id']);

		// CVE-2026-7634: mirror insertRow()'s sanitization so an UPDATE cannot
		// overwrite the row with raw HTML. Run before array_filter so values that
		// sanitize to '' get dropped along with originals.
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$data[$key] = array_map('sanitize_text_field', $value);
			} elseif ('resource' === $key || 'outbound_resource' === $key) {
				$data[$key] = sanitize_url($value);
			} else {
				$data[$key] = sanitize_text_field($value);
			}
		}

		$data = array_filter($data);

		// An UPDATE never rewrites identity. The row's vid_hash was set at insert from
		// the full client info; re-deriving it here is exactly the retroactive-rewrite
		// path D68's mechanism (c) exposed, so the field is refused wholesale.
		unset($data['vid_hash']);

		$table_name = $GLOBALS['wpdb']->prefix . 'slim_stats';
		$query = Query::update($table_name)->ignore()->where('id', '=', $id);
		$hasUpdates = false;

		if (!empty($data['notes']) && is_array($data['notes'])) {
			$notes_to_append = '[' . implode('][', $data['notes']) . ']';
			$query->setRaw('notes', "CONCAT(IFNULL(notes, ''), %s)", [$notes_to_append]);
			unset($data['notes']);
			$hasUpdates = true;
		}

		if (!empty($data['outbound_resource'])) {
			$url = sanitize_url(wp_unslash($data['outbound_resource']));
			$query->setRaw(
				'outbound_resource',
				"IF(outbound_resource IS NULL OR outbound_resource = '', %s, IF(LENGTH(outbound_resource) + LENGTH(%s) + 3 <= 2048, CONCAT(outbound_resource, ';;;', %s), outbound_resource))",
				[$url, $url, $url]
			);
			unset($data['outbound_resource']);
			$hasUpdates = true;
		}

		if ($data !== []) {
			$query->set($data);
			$hasUpdates = true;
		}

		// If sanitization stripped every field there is nothing to write — skip
		// the execute() to avoid emitting `UPDATE ... SET  WHERE id=X` (invalid SQL).
		if (!$hasUpdates) {
			return WriteResult::ignored($id);
		}

		if (false === $query->execute()) {
			return WriteResult::failed((string) $GLOBALS['wpdb']->last_error);
		}

		// An UPDATE that matched no rows is not a failure — the heartbeat for a row the
		// purge has already removed is the ordinary case — so the caller's id is still the
		// answer. What is now representable, and was not, is the failure above.
		return WriteResult::stored($id);
	}
}
