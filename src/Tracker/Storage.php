<?php

namespace SlimStat\Tracker;

use SlimStat\Utils\Query;

class Storage
{
	public static function insertRow($data = [], $table = '')
	{
		if (empty($data) || empty($table)) {
			return -1;
		}

		foreach ($data as $key => $value) {
			$data[$key] = 'resource' == $key ? sanitize_url($value) : sanitize_text_field($value);
		}

		return Query::insert($table)
			->ignore()
			->values($data)
			->execute();
	}

	public static function updateRow($data = [])
	{
		if (empty($data) || empty($data['id'])) {
			return false;
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
			return $id;
		}

		$query->execute();
		return $id;
	}
}
