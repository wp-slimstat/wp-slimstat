<?php

namespace SlimStat\Tracker;

class Storage
{
	public static function insertRow($data = [], $table = '')
	{
		if (empty($data) || empty($table)) {
			return -1;
		}

		$data_keys = [];
		foreach (array_keys($data) as $a_key) {
			$data_keys[] = sanitize_key($a_key);
		}

		foreach ($data as $key => $value) {
			$data[$key] = 'resource' == $key ? sanitize_url($value) : sanitize_text_field($value);
		}

		\wp_slimstat::$wpdb->query(\wp_slimstat::$wpdb->prepare(
			"INSERT IGNORE INTO {$table} (" . implode(', ', $data_keys) . ') VALUES (' . substr(str_repeat('%s,', count($data)), 0, -1) . ')',
			$data
		));

		return intval(\wp_slimstat::$wpdb->insert_id);
	}

	public static function updateRow($data = [])
	{
		if (empty($data) || empty($data['id'])) {
			return false;
		}

		$id = abs(intval($data['id']));
		unset($data['id']);

		$data = array_filter($data);

		// Sanitize column names to prevent SQL injection
		$sanitized_columns = [];
		foreach (array_keys($data) as $column) {
			$sanitized_columns[] = sanitize_key($column);
		}

		$notes = '';
		if (!empty($data['notes']) && is_array($data['notes'])) {
			$notes = (count($data) > 1 ? ',' : '') . "notes=CONCAT( IFNULL( notes, '' ), '[" . esc_sql(implode('][', $data['notes'])) . "]' )";
			unset($data['notes']);
		}

		// Use consistent database prefix and parameterized query for ID
		$table_name = \wp_slimstat::$wpdb->prefix . 'slim_stats';
		$prepared_query = \wp_slimstat::$wpdb->prepare(
			"UPDATE IGNORE {$table_name} SET " . implode('=%s,', $sanitized_columns) . "=%s WHERE id = %d",
			array_merge(array_values($data), [$id])
		);

		if ('' !== $notes && '0' !== $notes) {
			$prepared_query = str_replace('WHERE id =', $notes . ' WHERE id =', $prepared_query);
		}

		\wp_slimstat::$wpdb->query($prepared_query);
		return $id;
	}
}
