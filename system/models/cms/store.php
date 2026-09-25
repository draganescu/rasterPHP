<?php
// #CMS content store
//
// Reads and writes CMS content. Shared by the in-page editor, the MCP
// endpoint and the command line so all three follow the same rules:
// - page fields are versioned: every save stores a new revision of the page
// - only fields that exist in the templates can be written; to add a field,
//   add an annotation to a view (the markup is the schema)
class cms_store {

	// columns RedBean or Raster manage, never edited as content
	static $system_fields = array('id', 'slug', 'updated_at', 'enabled');

	static function connect() {
		database::instance('cms');
		if (!database::configured()) {
			throw new RuntimeException('No database connection for the '.config::get('environment').' environment (see application/config/db/)');
		}
	}

	static function table_exists($type) {
		try {
			return in_array($type, R::inspect());
		} catch (Exception $e) {
			return false;
		}
	}

	static function columns($type) {
		if (!self::table_exists($type)) return array();
		try {
			return R::inspect($type);
		} catch (Exception $e) {
			return array();
		}
	}

	static function latest($type) {
		if (!self::table_exists($type)) return null;
		return R::findOne($type, ' ORDER BY id DESC ');
	}

	static function clean_value($value) {
		if (is_bool($value)) return $value ? '1' : '0';
		if ($value === null) return '';
		if (!is_scalar($value)) throw new InvalidArgumentException('Field values must be strings');
		return (string)$value;
	}

	// ##Pages

	static function page_values($type) {
		$page = self::latest($type);
		if (!$page) return null;
		$values = $page->export();
		foreach (self::$system_fields as $field) unset($values[$field]);
		return array(
			'revision' => (int)$page->id,
			'updated_at' => $page->updated_at,
			'fields' => $values,
		);
	}

	// Stores a new revision of the page with the given fields changed.
	// $allowed lists the fields the templates define; others are rejected.
	static function update_page($type, $slug, $values, $allowed) {
		foreach ($values as $field => $value) {
			if (!in_array($field, $allowed, true)) {
				throw new InvalidArgumentException("Unknown field '$field'. Fields come from the templates; known fields: ".implode(', ', $allowed));
			}
		}
		$latest = self::latest($type);
		if ($latest) {
			$page = R::duplicate($latest);
		} else {
			$page = R::dispense($type);
			$page->slug = $slug;
		}
		foreach ($values as $field => $value) {
			$page->$field = self::clean_value($value);
		}
		$page->updated_at = R::isoDateTime();
		R::store($page);
		return self::page_values($type);
	}

	static function page_history($type, $limit = 20) {
		if (!self::table_exists($type)) return array();
		$revisions = R::find($type, ' ORDER BY id DESC LIMIT '.max(1, (int)$limit));
		$history = array();
		foreach ($revisions as $revision) {
			$values = $revision->export();
			foreach (self::$system_fields as $field) unset($values[$field]);
			$history[] = array('revision' => (int)$revision->id, 'updated_at' => $revision->updated_at, 'fields' => $values);
		}
		return $history;
	}

	// ##Collections

	static function export_item($bean) {
		$item = $bean->export();
		$item['id'] = (int)$item['id'];
		return $item;
	}

	static function list_items($type, $limit = 50, $offset = 0) {
		if (!self::table_exists($type)) return array('total' => 0, 'items' => array());
		$items = R::find($type, ' ORDER BY id ASC LIMIT '.max(1, (int)$limit).' OFFSET '.max(0, (int)$offset));
		return array(
			'total' => (int)R::count($type),
			'items' => array_values(array_map(array('cms_store', 'export_item'), $items)),
		);
	}

	static function get_item($type, $id) {
		if (!self::table_exists($type)) return null;
		$bean = R::findOne($type, ' id = ? ', array((int)$id));
		return $bean ? self::export_item($bean) : null;
	}

	static function save_item($type, $id, $values, $allowed) {
		foreach ($values as $field => $value) {
			if (!in_array($field, $allowed, true)) {
				throw new InvalidArgumentException("Unknown field '$field'. Fields come from the templates; known fields: ".implode(', ', $allowed));
			}
		}
		if ($id) {
			$bean = R::findOne($type, ' id = ? ', array((int)$id));
			if (!$bean) throw new InvalidArgumentException("No item $id");
		} else {
			$bean = R::dispense($type);
			$bean->enabled = '1';
		}
		foreach ($values as $field => $value) {
			$bean->$field = self::clean_value($value);
		}
		$bean->updated_at = R::isoDateTime();
		R::store($bean);
		return self::export_item($bean);
	}

	static function delete_item($type, $id) {
		$bean = self::table_exists($type) ? R::findOne($type, ' id = ? ', array((int)$id)) : null;
		if (!$bean) return false;
		R::trash($bean);
		return true;
	}

	// ##Users

	static function create_user($username, $password) {
		$user = R::findOne('usersdata', ' username = ? ', array($username));
		if (!$user) {
			$user = R::dispense('usersdata');
			$user->username = $username;
		}
		$user->password = password_hash($password, PASSWORD_DEFAULT);
		R::store($user);
		return (int)$user->id;
	}

	static function check_login($username, $password) {
		if (!self::table_exists('usersdata')) return false;
		$user = R::findOne('usersdata', ' username = ? ', array((string)$username));
		if (!$user || !is_string($password) || $password === '') return false;
		$hash = (string)$user->password;
		if (preg_match('/^[a-f0-9]{32}$/', $hash)) {
			// accounts from older Raster versions used md5; upgrade on login
			if (!hash_equals($hash, md5($password))) return false;
			$user->password = password_hash($password, PASSWORD_DEFAULT);
			R::store($user);
			return (int)$user->id;
		}
		return password_verify($password, $hash) ? (int)$user->id : false;
	}

	static function has_users() {
		return self::table_exists('usersdata') && R::count('usersdata') > 0;
	}
}
