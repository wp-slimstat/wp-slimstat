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

		$data = array_filter($data);

		$table_name = \wp_slimstat::$wpdb->prefix . 'slim_stats';
		$query = Query::update($table_name)->ignore()->where('id', '=', $id);

		if (!empty($data['notes']) && is_array($data['notes'])) {
			$notes_to_append = '[' . implode('][', $data['notes']) . ']';
			$query->setRaw('notes', "CONCAT(IFNULL(notes, ''), %s)", [$notes_to_append]);
			unset($data['notes']);
		}

		if ($data !== []) {
			$query->set($data);
		}

		$query->execute();
		return $id;
	}
}
