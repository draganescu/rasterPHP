<?php
// #Records: types a model declares, stored and shown by the CMS
//
// A collection declared by markup (render.cms.news) is content: editors write
// it and visitors read it. A type declared by a model is a record: bookings,
// orders, applications. The model says what a record holds and what makes it
// valid; the CMS stores it, lists it where a view renders it, and lets editors
// and agents change it through the same editor and MCP tools as any item.
//
//   class reservation {
//       static function types() {
//           return array('reservation' => array(
//               'fields'   => array('name' => '', 'date' => '', 'guests' => 0, 'status' => 'new'),
//               'create'   => 'visitor',              // who may send the form: visitor, member, editor
//               'readonly' => array('status'),         // shown to editors, changed only by the model
//               'actions'  => array('confirm' => 'editor'),
//           ));
//       }
//       static function check($type, $after, $before) { ... return array('fully_booked'); }
//       static function confirm($item, $input) { return cms_records::update('reservation', $item['id'], array('status' => 'confirmed')); }
//       function book() { return cms_records::submit('reservation', 'booked'); }
//   }
//
// Every write, from a form, the editor, MCP or the model's own code, goes
// through cms_store::save_item, which asks the model's check() first. Hooks are
// static so /api can never call them.
class cms_records {

	static $cache = null;
	// names a type declaration understands
	static $keys = array('fields', 'public', 'owner', 'create', 'readonly', 'hidden', 'actions', 'html');
	// the model methods with a meaning of their own, never actions
	static $hooks = array('types', 'check', 'schema', 'listens');
	// events held back until the transaction they happened in commits
	static $depth = 0;
	static $queued = array();

	// ##Declarations

	// every declared type: name => declaration, with the model that owns it
	static function types() {
		if (self::$cache !== null) return self::$cache;
		self::$cache = array();
		$root = APPBASE.config::get('models_path', 'models');
		foreach (glob($root.'/*', GLOB_ONLYDIR) ?: array() as $dir) {
			$folder = basename($dir);
			$file = "$dir/$folder.php";
			if (!is_file($file) || strpos(file_get_contents($file), 'function types(') === false) continue;
			// models/the_x/the_x.php overrides x, and its types belong to x
			$model = strpos($folder, 'the_') === 0 ? substr($folder, 4) : $folder;
			try {
				if (!class_exists($folder)) require_once $file;
				// only a static types() declares records; a model may well have
				// an ordinary method with that name
				if (!class_exists($folder) || !method_exists($folder, 'types') || !(new ReflectionMethod($folder, 'types'))->isStatic()) continue;
				$declared = call_user_func(array($folder, 'types'));
			} catch (Throwable $e) {
				log::warning("records: $folder::types(): ".$e->getMessage());
				continue;
			}
			foreach ((array)$declared as $name => $declaration) {
				// letters and digits only: the table is <name>data, and the name
				// must come back from it
				if (!is_string($name) || !preg_match('/^[a-z][a-z0-9]*$/', $name) || !is_array($declaration)) continue;
				self::$cache[$name] = self::normalize($name, $declaration, $model, $folder);
			}
		}
		ksort(self::$cache);
		return self::$cache;
	}

	static function forget() {
		self::$cache = null;
	}

	protected static function normalize($name, $d, $model, $class) {
		$fields = isset($d['fields']) && is_array($d['fields']) ? $d['fields'] : array();
		foreach (array_merge(cms_store::$system_fields, array('owner', 'created_at')) as $system) unset($fields[$system]);
		$lists = array();
		foreach ($fields as $field => $default) {
			if (is_array($default)) $lists[] = $field;
		}
		$owner = !empty($d['owner']);
		$actions = array();
		foreach ((isset($d['actions']) && is_array($d['actions']) ? $d['actions'] : array()) as $action => $role) {
			if (is_int($action)) { $action = $role; $role = 'editor'; }
			// actions are for staff: the in-page editor is only theirs
			$actions[$action] = $role === 'admin' ? 'admin' : 'editor';
		}
		return array(
			'name' => $name,
			'type' => cms::collection_type($name),
			'model' => $model,
			'class' => $class,
			'fields' => $fields,
			'lists' => $lists,
			'public' => !empty($d['public']),
			'owner' => $owner,
			'create' => isset($d['create']) && in_array($d['create'], array('visitor', 'member', 'editor'), true) ? $d['create'] : 'editor',
			'readonly' => array_values(array_intersect((array)(isset($d['readonly']) ? $d['readonly'] : array()), array_keys($fields))),
			'hidden' => array_values(array_intersect((array)(isset($d['hidden']) ? $d['hidden'] : array()), array_keys($fields))),
			'actions' => $actions,
			// fields printed as HTML; every other field prints escaped, since
			// records hold what visitors typed
			'html' => array_values(array_intersect((array)(isset($d['html']) ? $d['html'] : array()), array_keys($fields))),
			'unknown' => array_values(array_diff(array_keys($d), self::$keys)),
		);
	}

	// the declaration of a collection, or null when markup declares it
	static function info($collection) {
		$types = self::types();
		$collection = (string)$collection;
		return isset($types[$collection]) ? $types[$collection] : null;
	}

	// the declaration whose table this is (reservationdata), or null
	static function for_table($table) {
		foreach (self::types() as $info) {
			if ($info['type'] === $table) return $info;
		}
		return null;
	}

	static function is_private($collection) {
		$info = self::info($collection);
		return $info && !$info['public'];
	}

	// the fields a person may write through the editor, MCP or a form:
	// declared fields minus readonly and hidden
	static function writable($info) {
		return array_values(array_diff(array_keys($info['fields']), $info['readonly'], $info['hidden']));
	}

	// every column the table has: declared fields, then Raster's own
	static function columns($info) {
		$columns = $info['fields'];
		foreach ($info['lists'] as $list) $columns[$list] = '[]';
		$columns += array('slug' => '', 'enabled' => '1', 'published_at' => '', 'updated_at' => '', 'created_at' => '');
		if ($info['owner']) $columns['owner'] = 0;
		return $columns;
	}

	// a fluid database gets the table and every column on first use, the way
	// schema --apply does in production: one row with every column, removed
	static function ensure($info, $even_frozen = false) {
		if (database::$frozen && !$even_frozen) return;
		$type = $info['type'];
		$have = cms_store::columns($type);
		$want = self::columns($info);
		if ($have && !array_diff_key($want, $have)) return;
		$bean = R::dispense($type);
		// a column takes the kind of its first value: numbers for integer
		// defaults, text for the rest (so "24.00" stays "24.00")
		foreach ($want as $column => $default) $bean->$column = is_int($default) ? $default : 'raster text';
		R::store($bean);
		R::trash($bean);
	}

	// ##Reading

	// one stored row as the model and the templates see it: lists decoded
	static function decode($info, $item) {
		foreach ($info['lists'] as $list) {
			if (!array_key_exists($list, $item)) continue;
			$value = is_string($item[$list]) ? json_decode($item[$list], true) : $item[$list];
			$item[$list] = is_array($value) ? $value : array();
		}
		if (isset($item['owner'])) $item['owner'] = (int)$item['owner'];
		return $item;
	}

	// Rows for a template: what visitors typed prints as text, not markup.
	// Top level fields are escaped by the template (raster_escape lists
	// them), so the editor still gets the real values; lists inside a row are
	// escaped here, for HTML views.
	static function for_template($info, $row) {
		$escape = array();
		foreach ($row as $field => $value) {
			if (in_array($field, $info['html'], true)) continue;
			if (is_string($value)) $escape[] = $field;
			elseif (is_array($value) && config::get('format', 'html') === 'html') $row[$field] = self::escape_list($value);
		}
		$row['raster_escape'] = $escape;
		return $row;
	}

	protected static function escape_list($value) {
		foreach ($value as $key => $inner) {
			if (is_string($inner)) $value[$key] = htmlspecialchars($inner, ENT_QUOTES, 'UTF-8');
			elseif (is_array($inner)) $value[$key] = self::escape_list($inner);
		}
		return $value;
	}

	// what editors and agents see: without hidden fields
	static function shown($info, $item) {
		foreach ($info['hidden'] as $field) unset($item[$field]);
		return $item;
	}

	// SQL limiting a private type to what the person asking may read:
	// editors everything, owners their own records, anyone else nothing
	static function visibility_sql($info) {
		if ($info['public'] || cms::loggedin()) return array('', array());
		$user = $info['owner'] ? authentication::user() : null;
		if (!$user) return array(' AND 1 = 0 ', array());
		return array(' AND owner = :raster_owner ', array(':raster_owner' => (int)$user['id']));
	}

	static function get($collection, $id) {
		$info = self::required($collection);
		cms_store::connect();
		$bean = cms_store::table_exists($info['type']) ? R::findOne($info['type'], ' id = ? ', array((int)$id)) : null;
		return $bean ? self::decode($info, cms_store::export_item($bean)) : null;
	}

	// records matching field = value filters, oldest first, for model code
	static function find($collection, $filters = array(), $order = 'oldest', $limit = 1000) {
		$info = self::required($collection);
		cms_store::connect();
		if (!cms_store::table_exists($info['type'])) return array();
		$columns = cms_store::columns($info['type']);
		$sql = ' 1 = 1 ';
		$bindings = array();
		foreach ($filters as $field => $value) {
			if (!preg_match('/^[a-z0-9_]+$/', $field) || !array_key_exists($field, $columns)) throw new InvalidArgumentException("$collection has no field '$field'");
			$sql .= " AND $field = :f_$field ";
			$bindings[":f_$field"] = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
		}
		$sql .= ' ORDER BY '.cms_store::order_sql($order, $columns).' LIMIT '.max(1, (int)$limit);
		$rows = array();
		foreach (R::find($info['type'], $sql, $bindings) as $bean) $rows[] = self::decode($info, cms_store::export_item($bean));
		return $rows;
	}

	// ##Writing, for the model's own code
	//
	// These skip the readonly and hidden rules (the model owns those fields)
	// but still go through check(), the events and the transaction.

	static function create($collection, $values) {
		$info = self::required($collection);
		cms_store::connect();
		return cms_store::save_item($info['type'], 0, $values, array_keys($info['fields']), 'model');
	}

	static function update($collection, $id, $values) {
		$info = self::required($collection);
		cms_store::connect();
		return cms_store::save_item($info['type'], (int)$id, $values, array_keys($info['fields']), 'model');
	}

	static function delete($collection, $id) {
		$info = self::required($collection);
		cms_store::connect();
		return cms_store::delete_item($info['type'], (int)$id);
	}

	// stops a write, an action or a form with named problems. The names are
	// alerts: the template has the words (print.validation.alert('sold_out'))
	static function refuse($problems) {
		throw new cms_refused(is_array($problems) ? $problems : func_get_args());
	}

	// Runs $work so that every write in it happens, or none does. On SQLite
	// the database is locked for writing from the start, so two checkouts
	// can't both take the last mug. Events wait for the commit: nothing is
	// emailed about a write that was rolled back.
	static function transaction($work) {
		cms_store::connect();
		if (self::$depth > 0) return $work();
		$pdo = R::getDatabaseAdapter()->getDatabase()->getPDO();
		$sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
		$sqlite ? $pdo->exec('BEGIN IMMEDIATE') : $pdo->beginTransaction();
		self::$depth = 1;
		self::$queued = array();
		try {
			$result = $work();
			$sqlite ? $pdo->exec('COMMIT') : $pdo->commit();
		} catch (Throwable $e) {
			self::$depth = 0;
			self::$queued = array();
			$sqlite ? $pdo->exec('ROLLBACK') : $pdo->rollBack();
			throw $e;
		}
		self::$depth = 0;
		$queued = self::$queued;
		self::$queued = array();
		foreach ($queued as $event) event::dispatch($event[0], $event[1]);
		return $result;
	}

	// what the store calls instead of event::dispatch
	static function dispatch($event, $payload) {
		if (self::$depth > 0) {
			self::$queued[] = array($event, $payload);
			return true;
		}
		return event::dispatch($event, $payload);
	}

	// ##The check

	// Asks the model whether a write may happen. $after is the whole record
	// as it would be stored (null when deleting), $before as it was (null
	// when creating).
	static function check($info, $after, $before) {
		$class = $info['class'];
		if (!method_exists($class, 'check')) return;
		$problems = call_user_func(array($class, 'check'), $info['name'], $after, $before);
		if ($problems === true || $problems === null || $problems === array() || $problems === '') return;
		if ($problems === false) $problems = array('refused');
		throw new cms_refused((array)$problems);
	}

	// ##Forms
	//
	// The body of a form handler that stores what a visitor sent:
	//
	//   function book() { return cms_records::submit('reservation', 'booked'); }
	//
	// It keeps the fields the type lets people write, runs the HTML rules and
	// check(), raises each problem as an alert and shows the form again, or
	// stores the record and redirects with ?done=$done. Pass $done = false to
	// get the stored record back instead (a checkout that goes on to pay).
	static function submit($collection, $done = null) {
		$info = self::required($collection);
		$v = validation::get();
		if (!$v->submitted()) return false;
		if (!$v->valid()) return template::instance()->form_state();
		if (!self::may_create($info)) {
			$v->raise($info['create'] === 'member' ? 'login_required' : 'not_allowed');
			return template::instance()->form_state();
		}
		$values = array();
		foreach (self::writable($info) as $field) {
			// fields the form doesn't send keep the type's default
			if (in_array($field, $info['lists']) || !array_key_exists($field, $_POST) || is_array($_POST[$field])) continue;
			$values[$field] = trim((string)util::post($field));
		}
		try {
			cms_store::connect();
			$item = cms_store::save_item($info['type'], 0, $values, self::writable($info), 'visitor');
		} catch (cms_refused $e) {
			foreach ($e->problems as $problem) $v->raise($problem);
			return template::instance()->form_state();
		}
		if ($done === false) return $item;
		util::done($done === null ? $info['name'] : $done);
		return false;
	}

	static function may_create($info) {
		if ($info['create'] === 'visitor') return true;
		return authentication::can($info['create']);
	}

	// ##Actions
	//
	// What editors do to a record that is more than changing a field: ship an
	// order, refund it, confirm a booking. The type names them with the least
	// role that may run them; the model has a static method of the same name,
	// fn($item, $input), that makes its writes through cms_records and returns
	// the record (or refuses). The editor shows them as buttons, MCP as
	// run_action. $trusted skips the role check (MCP holds the site's token).
	static function act($collection, $id, $action, $input = array(), $trusted = false) {
		$info = self::required($collection);
		if (!isset($info['actions'][$action])) {
			throw new InvalidArgumentException("$collection has no action '$action'".($info['actions'] ? '; actions: '.implode(', ', array_keys($info['actions'])) : ''));
		}
		if (!$trusted && !authentication::can($info['actions'][$action])) throw new cms_refused(array('not_allowed'));
		if (!self::is_action_method($info['class'], $action)) throw new RuntimeException("{$info['class']}::$action() is missing or not static");
		$input = is_array($input) ? $input : array();
		return self::transaction(function () use ($info, $collection, $id, $action, $input) {
			$item = self::get($collection, $id);
			if (!$item) throw new InvalidArgumentException("No $collection $id");
			$result = call_user_func(array($info['class'], $action), $item, $input);
			$fresh = self::get($collection, $id);
			return $fresh ?: (is_array($result) ? $result : $item);
		});
	}

	static function is_action_method($class, $action) {
		if (in_array($action, self::$hooks, true) || !method_exists($class, $action)) return false;
		$method = new ReflectionMethod($class, $action);
		return $method->isStatic() && $method->isPublic();
	}

	// the actions the logged in person may run
	static function allowed_actions($info) {
		$allowed = array();
		foreach ($info['actions'] as $action => $role) {
			if (authentication::can($role)) $allowed[] = $action;
		}
		return $allowed;
	}

	static function required($collection) {
		$info = self::info($collection);
		if (!$info) throw new InvalidArgumentException("No model declares a type '$collection' (static function types())");
		return $info;
	}
}

// problems a check() or an action named, e.g. array('slot_taken')
class cms_refused extends InvalidArgumentException {
	public $problems;
	function __construct($problems) {
		$this->problems = array_values(array_filter(array_map('strval', (array)$problems), 'strlen')) ?: array('refused');
		parent::__construct('Refused: '.implode(', ', $this->problems));
	}
}
