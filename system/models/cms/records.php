<?php
// #Records: types a model declares, stored and shown by the CMS
//
// A collection declared by markup (render.cms.news) is content: editors write
// it and visitors read it. A type declared by a model is a record: bookings,
// orders, applications. The model says what a record holds and what makes it
// valid; the CMS stores it, lists it where a view renders it, and lets editors
// and agents change it through the same editor and MCP tools as any item.
//
//   class inquiry {
//       static function types() {
//           return array('inquiry' => array(
//               'fields'   => array('name' => '', 'date' => '', 'people' => 0, 'status' => 'new'),
//               'types'    => array('date' => 'date'),     // the rest come from the defaults: 0 is an int
//               'create'   => 'visitor',              // who may send the form: visitor, member, editor
//               'readonly' => array('status'),         // shown to editors, changed only by the model
//               'actions'  => array('answer' => 'editor'),
//           ));
//       }
//       static function check($type, $after, $before) { ... return array('too_few'); }
//       static function answer($item, $input) { return cms_records::update('inquiry', $item['id'], array('status' => 'answered')); }
//       function form() { return cms_records::submit('inquiry', 'sent'); }
//   }
//
// Bookings are the bundled booking model's (system/models/booking).
//
// Every write, from a form, the editor, MCP or the model's own code, goes
// through cms_store::save_item, which asks the model's check() first. Hooks are
// static so /api can never call them.
class cms_records {

	static $cache = null;
	// bundled models whose types are left out, with the type and the model
	// that took the name: booking => array('type' => 'booking', 'by' => 'salon')
	static $left_out = array();
	// names a type declaration understands
	static $keys = array('fields', 'types', 'public', 'owner', 'create', 'staff_add', 'readonly', 'hidden', 'actions', 'html', 'spam');
	// the model methods with a meaning of their own, never actions
	static $hooks = array('types', 'check', 'fill', 'schema', 'listens', 'api', 'requires');
	// who is writing while fill() and check() run: visitor (a form), editor
	// (the in-page editor, MCP) or model (the model's own code); see writer()
	static $writer = null;
	// events held back until the transaction they happened in commits
	static $depth = 0;
	static $queued = array();
	// content changed in the transaction: the page cache is thrown away and
	// content_changed sent once, after the commit (util::content_changed)
	static $changed = false;

	// ##Declarations

	// every declared type: name => declaration, with the model that owns it
	static function types() {
		if (self::$cache !== null) return self::$cache;
		self::$cache = array();
		self::$left_out = array();
		$own = array();
		$bundled = array();
		foreach (controller::models() as $model => $found) {
			if (empty($found['declares']['types'])) continue;
			// a bundled model's types count where a view uses the model
			// (render.booking.form); the site's own always count, and so do
			// the ones a site's the_<name> override declares itself
			if (!$found['bundled'] || ($found['parent'] && strpos((string)file_get_contents($found['file']), 'function types(') !== false)) $own[] = $model;
			elseif (controller::uses($model)) $bundled[] = $model;
		}
		// the site's types first: a bundled model one of whose names (its own,
		// or a type's) the site already took declares none of its types, so
		// a site's booking type keeps working as it was
		foreach (array_merge($own, $bundled) as $model) {
			$read = self::declared($model);
			if (!$read) continue;
			list($class, $declared) = $read;
			if (in_array($model, $bundled, true)) {
				$taken = array_values(array_intersect(array_merge(array($model), array_keys($declared)), array_keys(self::$cache)));
				if ($taken) {
					self::$left_out[$model] = array('type' => $taken[0], 'by' => self::$cache[$taken[0]]['model']);
					continue;
				}
			}
			foreach ($declared as $name => $declaration) self::$cache[$name] = self::normalize($name, $declaration, $model, $class);
		}
		ksort(self::$cache);
		return self::$cache;
	}

	// array(class, name => declaration) of a model's types(), or null
	protected static function declared($model) {
		try {
			// models/the_x/the_x.php overrides x, and its types belong to x
			$class = controller::model_class($model);
			// only a static types() declares records; a model may well have
			// an ordinary method with that name
			if (!$class || !method_exists($class, 'types') || !(new ReflectionMethod($class, 'types'))->isStatic()) return null;
			$declared = call_user_func(array($class, 'types'));
		} catch (Throwable $e) {
			log::warning("records: $model::types(): ".$e->getMessage());
			return null;
		}
		$valid = array();
		foreach ((array)$declared as $name => $declaration) {
			// letters and digits only: the table is <name>data, and the name
			// must come back from it
			if (is_string($name) && preg_match('/^[a-z][a-z0-9]*$/', $name) && is_array($declaration)) $valid[$name] = $declaration;
		}
		return array($class, $valid);
	}

	// the types are read again, and the models they come from looked up again
	static function forget() {
		self::$cache = null;
		controller::forget_models();
	}

	protected static function normalize($name, $d, $model, $class) {
		$fields = isset($d['fields']) && is_array($d['fields']) ? $d['fields'] : array();
		foreach (array_merge(cms_store::$system_fields, array('owner', 'created_at')) as $system) unset($fields[$system]);
		$lists = array();
		// each field's type: the one 'types' names, else its default's (0 is an
		// int, '' text); lists are stored as JSON text
		$named = isset($d['types']) && is_array($d['types']) ? $d['types'] : array();
		$types = array();
		foreach ($fields as $field => $default) {
			if (is_array($default)) $lists[] = $field;
			$type = isset($named[$field]) ? $named[$field] : null;
			$types[$field] = is_array($default) ? 'text' : (in_array($type, cms_types::$types, true) ? $type : cms_types::of_default($default));
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
			'types' => $types,
			'lists' => $lists,
			'public' => !empty($d['public']),
			'owner' => $owner,
			'create' => isset($d['create']) && in_array($d['create'], array('visitor', 'member', 'editor'), true) ? $d['create'] : 'editor',
			// staff add these in the page too (a booking taken over the phone),
			// though visitors make them with a form
			'staff_add' => !empty($d['staff_add']),
			'readonly' => array_values(array_intersect((array)(isset($d['readonly']) ? $d['readonly'] : array()), array_keys($fields))),
			'hidden' => array_values(array_intersect((array)(isset($d['hidden']) ? $d['hidden'] : array()), array_keys($fields))),
			'actions' => $actions,
			// fields printed as HTML; every other field prints escaped, since
			// records hold what visitors typed
			'html' => array_values(array_intersect((array)(isset($d['html']) ? $d['html'] : array()), array_keys($fields))),
			// visitor forms are checked for spam before anything is stored; a
			// type that runs its own checks says 'spam' => false
			'spam' => !isset($d['spam']) || !empty($d['spam']),
			'unknown' => array_values(array_diff(array_keys($d), self::$keys)),
		);
	}

	// the declaration of a collection, or null when markup declares it
	static function info($collection) {
		$types = self::types();
		$collection = (string)$collection;
		return isset($types[$collection]) ? $types[$collection] : null;
	}

	// the declaration whose table this is (bookingdata), or null
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

	// every column the table has, with its type: declared fields, then
	// Raster's own
	static function columns($info) {
		$columns = $info['types'];
		foreach (array('slug', 'enabled', 'published_at', 'updated_at', 'created_at') as $system) $columns[$system] = cms_types::$system[$system];
		if ($info['owner']) $columns['owner'] = 'int';
		return $columns;
	}

	// a fluid database gets the table and every column on first use, the way
	// schema --apply does in production
	static function ensure($info, $even_frozen = false) {
		if (database::$frozen && !$even_frozen) return;
		$want = self::columns($info);
		if (!array_diff_key($want, cms_store::columns($info['type']))) return;
		cms_types::ensure($info['type'], $want);
	}

	// ##Reading

	// one stored row as the model and the templates see it: lists decoded
	static function decode($info, $item) {
		foreach ($info['lists'] as $list) {
			if (!array_key_exists($list, $item)) continue;
			$value = is_string($item[$list]) ? json_decode($item[$list], true) : $item[$list];
			$item[$list] = is_array($value) ? $value : array();
		}
		return $item;
	}

	// Rows for a template: what visitors typed prints as text, not markup.
	// Top level fields are escaped by the template (raster_escape lists
	// them), so the editor still gets the real values; lists inside a row are
	// escaped here, for HTML views.
	static function for_template($info, $row) {
		// text, except numbers: the template prints those with as many
		// decimals as its mock-up (24.00, not 24)
		foreach ($row as $field => $value) {
			if (!is_float($value) && !is_array($value)) $row[$field] = cms_types::show($value);
		}
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

	// whether the editor leaves a field of a record's row alone: readonly,
	// hidden, Raster's own, or not stored at all (a value a model view adds)
	static function locked($info, $field) {
		if (!$info) return false;
		if (in_array($field, array_merge($info['readonly'], $info['hidden'], array('owner', 'created_at')), true)) return true;
		return !array_key_exists($field, $info['fields']) && !in_array($field, array('slug', 'enabled', 'published_at'), true);
	}

	// Records a model read itself (find, get), ready for its template, so a
	// view the model builds (an agenda by day, a week grid, records of several
	// types) stays editable in the page:
	//
	//   function agenda() {
	//       $days = array();
	//       foreach (cms_records::find('booking', array(), 'date,time') as $b) $days[$b['date']][] = $b;
	//       $rows = array();
	//       foreach ($days as $day => $bookings) $rows[] = array('day' => $day, 'bookings' => cms_records::listed('booking', $bookings));
	//       return $rows;
	//   }
	//
	// Each row prints as text, as in render.cms.<type>, and says which record
	// it is, so staff edit it and run its actions where the page shows it.
	// The model chose the rows: it decides what shows.
	static function listed($collection, $rows) {
		$info = self::required($collection);
		$listed = array();
		foreach ((array)$rows as $row) {
			if (!is_array($row) || !isset($row['id'])) continue;
			$row = self::for_template($info, self::decode($info, $row));
			$row['raster_record'] = $info['name'];
			$row['raster_detail_link'] = config::get('link_uri').$info['name'].'/'.$info['name'].'_item/'.(!empty($row['slug']) ? rawurlencode($row['slug']) : $row['id']);
			$listed[] = $row;
		}
		return $listed;
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

	// owner=me: the logged in person's id as a string, or null when nobody
	// is or the type has no owner
	static function owner_me($info) {
		if (!$info['owner']) return null;
		$user = authentication::user();
		return $user && $user['id'] ? (string)$user['id'] : null;
	}

	static function get($collection, $id) {
		$info = self::required($collection);
		cms_store::connect();
		$bean = cms_store::table_exists($info['type']) ? R::findOne($info['type'], ' id = ? ', array((int)$id)) : null;
		return $bean ? self::decode($info, cms_store::export_item($bean)) : null;
	}

	// records for model code, oldest first, all of them unless a limit is
	// given. $filters is field => value, or list options as render.cms
	// takes them: 'date>=today&status!=cancelled&order=date,time&limit=7'.
	// Values are values: a ?x (the URL's, in a template) throws. owner=me
	// is the logged in person's records, none when nobody is.
	static function find($collection, $filters = array(), $order = 'oldest', $limit = 0) {
		$info = self::required($collection);
		$list = is_string($filters) ? cms_store::list_options($filters, false) : null;
		cms_store::connect();
		if (!cms_store::table_exists($info['type'])) return array();
		$columns = cms_store::columns($info['type']);
		$sql = ' 1 = 1 ';
		$bindings = array();
		if ($list) {
			foreach ($list['conditions'] as $i => $condition) {
				if (!preg_match('/^[a-z0-9_]+$/', $condition[0]) || !array_key_exists($condition[0], $columns)) throw new InvalidArgumentException("$collection has no field '{$condition[0]}'");
				if ($condition !== array('owner', '=', 'me')) continue;
				$list['conditions'][$i][2] = self::owner_me($info);
				if ($list['conditions'][$i][2] === null) return array();
			}
			list($more, $bindings) = cms_store::conditions_sql($list['conditions'], $columns);
			$sql .= $more;
			if (isset($list['options']['order'])) $order = $list['options']['order'];
			if (isset($list['options']['limit'])) $limit = $list['options']['limit'];
			$filters = array();
		}
		foreach ($filters as $field => $value) {
			if (!preg_match('/^[a-z0-9_]+$/', $field) || !array_key_exists($field, $columns)) throw new InvalidArgumentException("$collection has no field '$field'");
			if ($field === 'owner' && $value === 'me') {
				$value = self::owner_me($info);
				if ($value === null) return array();
			}
			$value = cms_types::clean(cms_types::of_column($columns[$field]), $value, $field);
			$sql .= $value === null ? " AND `$field` IS NULL " : " AND `$field` = :f_$field ";
			if ($value !== null) $bindings[":f_$field"] = $value;
		}
		$sql .= ' ORDER BY '.cms_store::order_sql($order, $columns).((int)$limit > 0 ? ' LIMIT '.(int)$limit : '');
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

	// Runs $work so that every write in it happens, or none does. The
	// database is locked for writing from the start, so two checkouts can't
	// both take the last mug, and two items saved at once can't both take
	// one slug: SQLite with BEGIN IMMEDIATE, MySQL with a named lock taken
	// before the transaction starts (so its reads see what the last holder
	// committed) and released after it ends. Either gives up after 5 seconds.
	// Events wait for the commit: nothing is emailed about a write that was
	// rolled back, and the page cache is thrown away (and content_changed
	// sent) once, when the new rows can be read.
	static function transaction($work) {
		cms_store::connect();
		if (self::$depth > 0) return $work();
		$pdo = R::getDatabaseAdapter()->getDatabase()->getPDO();
		$sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
		$lock = $sqlite ? null : self::lock($pdo);
		try {
			$sqlite ? $pdo->exec('BEGIN IMMEDIATE') : $pdo->beginTransaction();
			self::$depth = 1;
			self::$queued = array();
			self::$changed = false;
			try {
				$result = $work();
				self::finish($pdo, $sqlite, 'COMMIT');
			} catch (Throwable $e) {
				self::$depth = 0;
				self::$queued = array();
				self::$changed = false;
				self::finish($pdo, $sqlite, 'ROLLBACK');
				throw $e;
			}
		} finally {
			// a connection that died has let go of the lock already, and its
			// error is the one to report, not this one
			if ($lock) try { $pdo->query('SELECT RELEASE_LOCK('.$pdo->quote($lock).')'); } catch (PDOException $e) {}
		}
		self::$depth = 0;
		$queued = self::$queued;
		$changed = self::$changed;
		self::$queued = array();
		self::$changed = false;
		if ($changed) util::content_changed();
		foreach ($queued as $event) event::dispatch($event[0], $event[1]);
		return $result;
	}

	// MySQL's lock for writing: one per database, as SQLite's is one per
	// file. A lock name has at most 64 characters.
	protected static function lock($pdo) {
		$name = substr('raster:'.$pdo->query('SELECT DATABASE()')->fetchColumn(), 0, 64);
		if ((int)$pdo->query('SELECT GET_LOCK('.$pdo->quote($name).', 5)')->fetchColumn() !== 1) {
			throw new RuntimeException('database is locked: another write held it for 5 seconds');
		}
		return $name;
	}

	// MySQL commits by itself when the work changes the schema (a new table
	// or column in development), and then there is nothing left to commit or
	// roll back. SQLite changes its schema inside the transaction.
	protected static function finish($pdo, $sqlite, $how) {
		if ($sqlite) return $pdo->exec($how);
		if (!$pdo->inTransaction()) return;
		$how === 'COMMIT' ? $pdo->commit() : $pdo->rollBack();
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

	// The fields the model works out itself, merged into a write before
	// check(): array(field => value), from the model's static fill($type,
	// $after, $before) (a booking's end from its start and service).
	static function fill($info, $after, $before) {
		$class = $info['class'];
		if (!method_exists($class, 'fill') || !(new ReflectionMethod($class, 'fill'))->isStatic()) return array();
		$values = call_user_func(array($class, 'fill'), $info['name'], $after, $before);
		return is_array($values) ? array_intersect_key($values, $info['fields']) : array();
	}

	// who makes the write fill() and check() are asked about: 'visitor',
	// 'editor' or 'model', null outside a write. A check that treats staff
	// differently (notice for guests only) reads it.
	static function writer() {
		return self::$writer;
	}

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
	//   function form() { return cms_records::submit('inquiry', 'sent'); }
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
		// before may_create() refuses, so a bot doesn't learn which types need a login
		if ($info['spam'] && in_array($info['create'], array('visitor', 'member'), true) && ($reasons = $v->suspect(array()))) return $v->hold($reasons);
		if (!self::may_create($info)) {
			$v->raise($info['create'] === 'member' ? 'login_required' : 'not_allowed');
			return template::instance()->form_state();
		}
		$values = array();
		foreach (self::writable($info) as $field) {
			// fields the form doesn't send keep the type's default
			if (in_array($field, $info['lists']) || !array_key_exists($field, $_POST) || is_array($_POST[$field])) continue;
			$values[$field] = trim((string)util::post($field));
			// a ticked box sends its value, whatever it is (on, yes, subscribe)
			if ($info['types'][$field] === 'bool' && $values[$field] !== '') {
				$values[$field] = in_array(strtolower($values[$field]), array('0', 'false', 'no', 'off'), true) ? 0 : 1;
			}
		}
		try {
			cms_store::connect();
			$item = cms_store::save_item($info['type'], 0, $values, self::writable($info), 'visitor');
		} catch (cms_refused $e) {
			foreach ($e->problems as $problem) $v->raise($problem);
			return template::instance()->form_state();
		} catch (cms_type_error $e) {
			// a value the HTML let through that isn't of the field's type
			// (guests=2.5): the alert <field>_invalid
			$v->raise($e->field.'_invalid');
			return template::instance()->form_state();
		}
		if ($done === false) return $item;
		util::done($done === null ? $info['name'] : $done);
		return false;
	}

	// whether the in-page editor offers a card for a new record at the end
	// of a list: for types staff make (create is editor) or also add
	// (staff_add: a booking taken over the phone)
	static function addable($info) {
		return $info['create'] === 'editor' || $info['staff_add'];
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
	//
	// An action is not one transaction: each write is (with its check()),
	// and writes that must go together go in cms_records::transaction(). That
	// leaves room for what can't be rolled back, like asking a payment
	// provider for a refund, to happen outside the lock.
	static function act($collection, $id, $action, $input = array(), $trusted = false) {
		$info = self::required($collection);
		if (!isset($info['actions'][$action])) {
			throw new InvalidArgumentException("$collection has no action '$action'".($info['actions'] ? '; actions: '.implode(', ', array_keys($info['actions'])) : ''));
		}
		if (!$trusted && !authentication::can($info['actions'][$action])) throw new cms_refused(array('not_allowed'));
		if (!self::is_action_method($info['class'], $action)) throw new RuntimeException("{$info['class']}::$action() is missing or not static");
		$input = is_array($input) ? $input : array();
		$item = self::get($collection, $id);
		if (!$item) throw new InvalidArgumentException("No $collection $id");
		$result = call_user_func(array($info['class'], $action), $item, $input);
		$fresh = self::get($collection, $id);
		return $fresh ?: (is_array($result) ? $result : $item);
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
