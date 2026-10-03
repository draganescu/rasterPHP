<?php

require_once BASE.'models/cms/cms.php';

/**
* MCP server
*
* Exposes the CMS content model to agents over the Model Context Protocol.
* The content model comes from the templates, so an agent sees exactly the
* fields a human editor sees, and can only write those.
*
* Two transports:
* - HTTP at /mcp (JSON-RPC over POST). Off unless a token is configured:
*     config::set('mcp_token')->to('...')  or  RASTER_MCP_TOKEN=...
*   Clients send it as "Authorization: Bearer <token>".
* - stdio: `php bin/raster mcp`, for agents running on the same machine.
*/
class mcp
{
	const SERVER_VERSION = '1.0.0';
	static $protocol_versions = array('2025-06-18', '2025-03-26', '2024-11-05');

	// ##HTTP transport, bound to the finding_route event
	public function http()
	{
		$segments = (array)config::get('uri_segments');
		if ($segments[0] !== 'mcp' || count(array_filter($segments)) > 1) return false;
		self::$transport = 'http';

		$token = mcp::token();
		if ($token === '') {
			$this->http_reply(404, array('error' => 'The MCP endpoint is disabled. Set mcp_token in application/config/the_app.php or RASTER_MCP_TOKEN.'));
		}
		$header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? '') : '');
		if (!preg_match('/^Bearer\s+(.+)$/i', trim((string)$header), $m) || !hash_equals($token, trim($m[1]))) {
			header('WWW-Authenticate: Bearer');
			$this->http_reply(401, array('error' => 'Missing or invalid bearer token'));
		}
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			header('Allow: POST');
			$this->http_reply(405, array('error' => 'Use POST'));
		}

		$body = json_decode(file_get_contents('php://input'), true);
		if (!is_array($body)) {
			$this->http_reply(400, $this->error(null, -32700, 'Parse error'));
		}
		// a batch is a list of messages
		if (array_keys($body) === range(0, count($body) - 1)) {
			$responses = array_values(array_filter(array_map(array($this, 'handle'), $body)));
			if (!$responses) $this->http_reply(202, null);
			$this->http_reply(200, $responses);
		}
		$response = $this->handle($body);
		if ($response === null) $this->http_reply(202, null);
		$this->http_reply(200, $response);
	}

	protected function http_reply($status, $payload) {
		http_response_code($status);
		if ($payload !== null) {
			header('Content-Type: application/json');
			echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}
		exit;
	}

	static function token() {
		$token = getenv('RASTER_MCP_TOKEN');
		if (!$token) $token = config::get('mcp_token');
		return is_string($token) ? trim($token) : '';
	}

	// the lint, schema and describe tools, loaded only when MCP answers: this
	// model listens on every request
	static function load_tools() {
		require_once BASE.'tools/inspector.php';
		require_once BASE.'tools/schema.php';
		require_once BASE.'tools/describe.php';
	}

	// ##stdio transport: one JSON-RPC message per line
	public function stdio($in = STDIN, $out = STDOUT) {
		self::$transport = 'stdio';
		while (($line = fgets($in)) !== false) {
			$line = trim($line);
			if ($line === '') continue;
			$message = json_decode($line, true);
			$response = is_array($message) ? $this->handle($message) : $this->error(null, -32700, 'Parse error');
			if ($response !== null) {
				fwrite($out, json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
				fflush($out);
			}
		}
	}

	// ##JSON-RPC
	public function handle($message) {
		mcp::load_tools();
		$id = array_key_exists('id', $message) ? $message['id'] : null;
		$method = isset($message['method']) ? $message['method'] : null;
		$params = isset($message['params']) && is_array($message['params']) ? $message['params'] : array();
		$is_notification = !array_key_exists('id', $message);

		if ($method === null) return $is_notification ? null : $this->error($id, -32600, 'Invalid request');
		if (strpos($method, 'notifications/') === 0) return null;

		switch ($method) {
			case 'initialize':
				$requested = isset($params['protocolVersion']) ? $params['protocolVersion'] : null;
				return $this->result($id, array(
					'protocolVersion' => in_array($requested, self::$protocol_versions) ? $requested : self::$protocol_versions[0],
					'capabilities' => array('tools' => array('listChanged' => false)),
					'serverInfo' => array('name' => 'raster', 'version' => self::SERVER_VERSION),
					'instructions' => 'This is a Raster site: views are plain HTML and the dynamic parts are HTML comments. Call describe first — it returns how URLs reach views, the content model the markup declares, every name a template may call, and whether the templates lint clean. Before writing an annotation, check vocabulary (the models and their signatures) and annotations (the grammar); both are read from the code, so neither can be out of date. To change a template use check_view then write_view, which refuses markup that does not lint, and render_url to see the result. Content edits go through get_page/update_page and the item tools: page edits keep revisions, and fields that are not in the templates cannot be written — to add a field, edit the template. In production pages are cached for visitors who are not logged in: Raster\'s own tools clear that cache, but views, theme files, models, config or the database changed any other way do not, so call clear_cache after such a change. render_url never reads the cache, so a fresh render_url does not mean visitors see the change. The in-page editor only marks what the CMS prints: keep lists staff edit on render.cms.<name>, using its options for filters from the URL (field=?param), dates (date>=today) and sorting (order=date,time); for what those cannot say (totals, grouping by day, several types), the render method of a model returns its records through cms_records::listed($type, $rows), which keeps them editable; after changing such a page, call render_url with as="editor" to see that editing still works.',
				));
			case 'ping':
				return $this->result($id, new stdClass());
			case 'tools/list':
				return $this->result($id, array('tools' => $this->tools()));
			case 'tools/call':
				$name = isset($params['name']) ? $params['name'] : '';
				$arguments = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : array();
				return $this->result($id, $this->call_tool($name, $arguments));
			default:
				return $is_notification ? null : $this->error($id, -32601, "Method not found: $method");
		}
	}

	protected function result($id, $result) {
		return array('jsonrpc' => '2.0', 'id' => $id, 'result' => $result);
	}

	protected function error($id, $code, $message) {
		return array('jsonrpc' => '2.0', 'id' => $id, 'error' => array('code' => $code, 'message' => $message));
	}

	// ##Tools

	protected function tools() {
		$page = array('type' => 'string', 'description' => 'The page: its URL path (/about), view name (about) or table (aboutpage). See site_overview.');
		$collection = array('type' => 'string', 'description' => 'Collection name as used in the templates, e.g. news for render.cms.news');
		$fields = array('type' => 'object', 'description' => 'Field names and their new values (strings; HTML is allowed)', 'additionalProperties' => array('type' => 'string'));
		$id = array('type' => 'integer', 'description' => 'Item id');
		$read_only = array('readOnlyHint' => true, 'openWorldHint' => false);
		$write = array('readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false);
		$tool = function ($name, $description, $properties, $required, $annotations) {
			return array(
				'name' => $name,
				'description' => $description,
				'inputSchema' => array('type' => 'object', 'properties' => $properties ?: new stdClass(), 'required' => $required),
				'annotations' => $annotations,
			);
		};
		$tools = array(
			$tool('site_overview', 'Lists the pages (URL, view, editable fields) and collections (fields, item count) of the site, and which models listen to which events. A short answer for content work; describe is the full one.', array(), array(), $read_only),
			$tool('get_page', 'Current values of the editable fields of a page.', array('page' => $page), array('page'), $read_only),
			$tool('update_page', 'Changes fields of a page. Stores a new revision; nothing is overwritten.', array('page' => $page, 'fields' => $fields), array('page', 'fields'), $write),
			$tool('page_history', 'Previous revisions of a page, newest first.', array('page' => $page, 'limit' => array('type' => 'integer', 'default' => 10)), array('page'), $read_only),
			$tool('list_items', 'Items of a collection.', array('collection' => $collection, 'limit' => array('type' => 'integer', 'default' => 50), 'offset' => array('type' => 'integer', 'default' => 0)), array('collection'), $read_only),
			$tool('get_item', 'One item of a collection.', array('collection' => $collection, 'id' => $id), array('collection', 'id'), $read_only),
			$tool('create_item', 'Adds an item to a collection.', array('collection' => $collection, 'fields' => $fields), array('collection', 'fields'), $write),
			$tool('update_item', 'Changes fields of a collection item.', array('collection' => $collection, 'id' => $id, 'fields' => $fields), array('collection', 'id', 'fields'), $write),
			$tool('run_action', 'Runs an action a record type declares (ship an order, confirm a booking): what editors do to a record beyond changing a field. site_overview lists each type\'s actions. The model may refuse, and says why.',
				array('collection' => $collection, 'id' => $id, 'action' => array('type' => 'string'), 'input' => array('type' => 'object', 'description' => 'Values the action takes, if any', 'additionalProperties' => array('type' => 'string'))), array('collection', 'id', 'action'), $write),
			$tool('delete_item', 'Deletes a collection item.', array('collection' => $collection, 'id' => $id), array('collection', 'id'), array('readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false)),
			$tool('lint_templates', 'Checks every template for annotation errors (unclosed blocks, unknown models, typos) with file:line positions.', array(), array(), $read_only),
			$tool('schema_status', 'Compares the content model in the templates with the database: missing columns, orphaned columns, likely renames.', array(), array(), $read_only),
			// ##Working on the site itself, not only its content
			$tool('describe', 'The whole site in one answer: how URLs reach views, the pages and collections the markup declares, every name a template may call, the settings that change behaviour, and whether the templates lint clean. Ask this first when you meet a Raster site.',
				array('sections' => array('type' => 'array', 'items' => array('type' => 'string', 'enum' => raster_describe::sections()), 'description' => 'Limit the answer to these sections. All of them by default.')), array(), $read_only),
			$tool('vocabulary', 'Every name a template is allowed to call: each model with its methods and their signatures, the named SQL queries, the events and who listens, and the names the CMS keeps for itself. Read from the code, never executed. Use it before writing an annotation instead of guessing a method name.',
				array(), array(), $read_only),
			$tool('annotations', 'The annotation grammar as data: the exact spelling of each directive, what each keyword does, how arguments and attributes work, and how blocks nest. lint checks against this same description.',
				array(), array(), $read_only),
			$tool('list_views', 'The view files of a theme, as paths relative to the theme folder.', array('theme' => array('type' => 'string', 'description' => 'Defaults to the site\'s theme')), array(), $read_only),
			$tool('read_view', 'The source of one view.', array('view' => array('type' => 'string', 'description' => 'Path inside the theme folder, e.g. about.html or docs/setup.html'), 'theme' => array('type' => 'string')), array('view'), $read_only),
			$tool('check_view', 'Lints a view that is not written yet: pass the markup and get back the problems, with line and column. Nothing is written. Use it on a draft before write_view.',
				array('content' => array('type' => 'string', 'description' => 'The markup to check'), 'view' => array('type' => 'string', 'description' => 'The name it would be saved as, for the messages'), 'theme' => array('type' => 'string')), array('content'), $read_only),
			$tool('write_view', 'Writes a view, but only if it lints clean: the file is left untouched when there are errors, and the problems come back instead. Warnings do not stop the write. Also reports what the change does to the content model.',
				array('view' => array('type' => 'string', 'description' => 'Path inside the theme folder, e.g. about.html'), 'content' => array('type' => 'string'), 'theme' => array('type' => 'string')), array('view', 'content'), $write),
			$tool('render_url', 'Renders a URL of this site and returns the status and the HTML, without a web server. The fastest way to see whether a change works. Runs in a separate process, so a page that fails cannot take this server down. Pass as="editor" to see the page as staff do: the answer then says what the in-page editor can do there (editable fields, the lists it marks, which lists get a card for a new item). Check it after changing a page staff edit: a list built by a model instead of render.cms.<name> is invisible to the editor.',
				array(
					'url' => array('type' => 'string', 'description' => 'A path on the site, with a query string if the page reads one, e.g. / or /menu/menu_item/flat-white or /bookings?stylist=ana'),
					'as' => array('type' => 'string', 'description' => 'Render as this person: an account\'s email or username, or a role (editor, admin, member) for someone with that role. Leave out to render as a visitor. Not in production.'),
					'limit' => array('type' => 'integer', 'description' => 'Characters of HTML to return, 20000 by default'),
				), array('url'), $read_only),
			$tool('clear_cache', 'Throws the page cache away. In production, visitors who are not logged in get cached pages until it is cleared. Raster\'s own tools (write_view, update_page, create_item, update_item, delete_item, run_action) already clear it; call this after changing views, theme files, models or config with anything else, or after changing the database directly. render_url never reads the cache.',
				array(), array(), array('readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false)),
		);
		if (!self::may_write_views()) {
			$tools = array_values(array_filter($tools, function ($t) { return $t['name'] !== 'write_view'; }));
		}
		return $tools;
	}

	// write_view edits the site's templates, which is a bigger thing than
	// editing content: a template can call any model. Over stdio the agent is
	// already on the machine with the files. Over HTTP it is not, so it stays
	// off until the site says otherwise.
	static $transport = 'stdio';

	static function may_write_views() {
		if (self::$transport !== 'http') return true;
		return (bool)config::get('mcp_write_views', false);
	}

	public function call_tool($name, $arguments) {
		mcp::load_tools();
		// the stdio process outlives a `schema --apply` run beside it
		cms_store::forget();
		// what a tool's code prints would break the answer over stdio
		$level = ob_get_level();
		ob_start();
		try {
			return $this->run_tool($name, $arguments);
		} finally {
			while (ob_get_level() > $level) {
				$printed = ob_get_clean();
				if (trim((string)$printed) !== '') log::warning("MCP $name printed: ".substr(trim($printed), 0, 500));
			}
		}
	}

	protected function run_tool($name, $arguments) {
		try {
			if (!in_array($name, array_map(function ($t) { return $t['name']; }, $this->tools()))) {
				throw new InvalidArgumentException("Unknown tool '$name'");
			}
			$method = 'tool_'.$name;
			$data = $this->$method($arguments);
			return array(
				'content' => array(array('type' => 'text', 'text' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))),
				'structuredContent' => (object)$data,
			);
		} catch (Exception $e) {
			$message = $e->getMessage();
			if ($e instanceof RedBeanPHP\RedException || $e instanceof PDOException) {
				$message = database::$frozen
					? 'The database is frozen and does not have this table or column yet. Run `php bin/raster schema --apply` on the server. ('.$message.')'
					: 'Database error: '.$message;
			}
			return array('content' => array(array('type' => 'text', 'text' => $message)), 'isError' => true);
		} catch (Error $e) {
			// a mistake in PHP code (the site's or Raster's) answers this call
			// instead of ending the server
			return array('content' => array(array('type' => 'text', 'text' => get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine())), 'isError' => true);
		}
	}

	protected function arg($arguments, $name, $default = null) {
		if (array_key_exists($name, $arguments)) return $arguments[$name];
		if (func_num_args() < 3) throw new InvalidArgumentException("Missing argument '$name'");
		return $default;
	}

	protected function model() {
		static $model = null;
		if ($model === null) {
			$inspector = new raster_inspector();
			$model = $inspector->content_model();
		}
		return $model;
	}

	protected function find_page($reference) {
		$reference = (string)$reference;
		foreach ($this->model()['pages'] as $page) {
			$view = $page['view'] === '*' ? '*' : substr($page['view'], 0, -strlen(config::get('views_ext', '.html')));
			$names = $page['view'] === '*' ? array('site', 'sitepage') : array($page['url'], $page['slug'], $page['type'], $page['view'], $view, '/'.$view);
			if (in_array($reference, $names, true)) {
				return $page;
			}
		}
		throw new InvalidArgumentException("Unknown page '$reference'. Pages: ".implode(', ', array_map(function ($p) { return $p['url']; }, $this->model()['pages'])));
	}

	protected function find_collection($name) {
		$collections = $this->model()['collections'];
		if (!isset($collections[$name])) {
			throw new InvalidArgumentException("Unknown collection '$name'. Collections: ".implode(', ', array_keys($collections)));
		}
		return $collections[$name];
	}

	protected function fields_argument($arguments) {
		$fields = $this->arg($arguments, 'fields');
		if (!is_array($fields) || empty($fields)) throw new InvalidArgumentException("'fields' must be an object with at least one field");
		return $fields;
	}

	protected function tool_site_overview($arguments) {
		cms_store::connect();
		$model = $this->model();
		$pages = array();
		foreach ($model['pages'] as $page) {
			$pages[] = array('url' => $page['url'], 'view' => $page['view'], 'fields' => array_keys($page['fields']));
		}
		$collections = array();
		foreach ($model['collections'] as $collection) {
			$collections[] = array(
				'name' => $collection['name'],
				'fields' => array_keys($collection['fields']),
				'items' => cms_store::table_exists($collection['type']) ? (int)R::count($collection['type']) : 0,
				'used_in' => $collection['views'],
				'item_url' => '/'.$collection['name'].'/'.$collection['name'].'_item/{id}',
			);
			// records of a type a model declares: private unless it says public,
			// with fields only the model writes, and actions
			if (isset($collection['model'])) {
				$collections[count($collections) - 1] += array('declared_by' => $collection['model'], 'public' => $collection['public'], 'readonly' => $collection['readonly'], 'actions' => array_keys($collection['actions']));
				$collections[count($collections) - 1]['fields'] = array_values(array_diff(array_keys($collection['fields']), $collection['hidden']));
			}
		}
		// who listens to what: saving an item or a booking may do more than it says
		$events = array();
		foreach (event::bindings() as $event => $listeners) {
			$listeners = array_values(array_filter($listeners, function ($l) { return !preg_match('/^(controller|log)\./', $l); }));
			if ($listeners) $events[$event] = $listeners;
		}
		return array('base_url' => config::get('base_uri'), 'environment' => config::get('environment'), 'pages' => $pages, 'collections' => $collections, 'events' => $events);
	}

	protected function tool_get_page($arguments) {
		cms_store::connect();
		$page = $this->find_page($this->arg($arguments, 'page'));
		$stored = cms_store::page_values($page['type']);
		$fields = array();
		foreach ($page['fields'] as $name => $field) {
			$has = $stored && array_key_exists($name, $stored['fields']) && $stored['fields'][$name] !== null;
			$fields[$name] = $has ? $stored['fields'][$name] : $field['default'];
		}
		return array(
			'url' => $page['url'],
			'view' => $page['view'],
			'revision' => $stored ? $stored['revision'] : null,
			'updated_at' => $stored ? $stored['updated_at'] : null,
			'fields' => $fields,
		);
	}

	protected function tool_update_page($arguments) {
		cms_store::connect();
		$page = $this->find_page($this->arg($arguments, 'page'));
		$fields = $this->fields_argument($arguments);
		// a page that was never rendered starts from the template defaults
		if (!cms_store::latest($page['type'])) {
			$defaults = array();
			foreach ($page['fields'] as $name => $field) $defaults[$name] = trim($field['default']);
			$fields = $fields + $defaults;
		}
		cms_store::update_page($page['type'], $page['slug'], $fields, array_keys($page['fields']));
		return $this->tool_get_page(array('page' => $page['type']));
	}

	protected function tool_page_history($arguments) {
		cms_store::connect();
		$page = $this->find_page($this->arg($arguments, 'page'));
		return array('url' => $page['url'], 'revisions' => cms_store::page_history($page['type'], (int)$this->arg($arguments, 'limit', 10)));
	}

	protected function tool_list_items($arguments) {
		cms_store::connect();
		$collection = $this->find_collection($this->arg($arguments, 'collection'));
		$limit = min(200, max(1, (int)$this->arg($arguments, 'limit', 50)));
		$offset = max(0, (int)$this->arg($arguments, 'offset', 0));
		$fields = array_keys($collection['fields']);
		if (isset($collection['model'])) $fields = array_values(array_diff($fields, $collection['hidden']));
		return array('collection' => $collection['name'], 'fields' => $fields) + cms_store::list_items($collection['type'], $limit, $offset);
	}

	protected function tool_get_item($arguments) {
		cms_store::connect();
		$collection = $this->find_collection($this->arg($arguments, 'collection'));
		$item = cms_store::get_item($collection['type'], $this->arg($arguments, 'id'));
		if (!$item) throw new InvalidArgumentException('No item with that id');
		return $item;
	}

	protected function tool_create_item($arguments) {
		cms_store::connect();
		$collection = $this->find_collection($this->arg($arguments, 'collection'));
		return cms_store::save_item($collection['type'], 0, $this->fields_argument($arguments), array_keys($collection['fields']));
	}

	protected function tool_update_item($arguments) {
		cms_store::connect();
		$collection = $this->find_collection($this->arg($arguments, 'collection'));
		return cms_store::save_item($collection['type'], (int)$this->arg($arguments, 'id'), $this->fields_argument($arguments), array_keys($collection['fields']));
	}

	protected function tool_run_action($arguments) {
		cms_store::connect();
		$collection = $this->find_collection($this->arg($arguments, 'collection'));
		if (!isset($collection['model'])) throw new InvalidArgumentException("{$collection['name']} is content, not a record type; it has no actions");
		$input = $this->arg($arguments, 'input', array());
		// the site's token is trusted like an admin
		$item = cms_records::act($collection['name'], (int)$this->arg($arguments, 'id'), (string)$this->arg($arguments, 'action'), is_array($input) ? $input : array(), true);
		return cms_records::shown(cms_records::info($collection['name']), $item);
	}

	protected function tool_delete_item($arguments) {
		cms_store::connect();
		$collection = $this->find_collection($this->arg($arguments, 'collection'));
		$id = (int)$this->arg($arguments, 'id');
		if (!cms_store::delete_item($collection['type'], $id)) throw new InvalidArgumentException('No item with that id');
		return array('deleted' => $id);
	}

	protected function tool_lint_templates($arguments) {
		$inspector = new raster_inspector();
		$problems = $inspector->lint();
		$errors = count(array_filter($problems, function ($p) { return $p['severity'] === 'error'; }));
		return array('errors' => $errors, 'warnings' => count($problems) - $errors, 'problems' => $problems);
	}

	protected function tool_schema_status($arguments) {
		$schema = new raster_schema();
		return $schema->status();
	}

	// ##Working on the site, not only its content
	//
	// These read and write the templates. They are here rather than in the
	// command line so an agent pays for starting PHP once a session instead of
	// once a call: describing this site takes about 70 ms, everything else
	// under 20, and none of it needs a web server.

	protected function tool_describe($arguments) {
		$sections = $this->arg($arguments, 'sections', null);
		if ($sections !== null && !is_array($sections)) throw new InvalidArgumentException("'sections' must be a list of section names");
		if ($sections) {
			$unknown = array_diff($sections, raster_describe::sections());
			if ($unknown) throw new InvalidArgumentException('Unknown section(s): '.implode(', ', $unknown).'. Sections: '.implode(', ', raster_describe::sections()));
		}
		return raster_describe::site($sections ?: null);
	}

	protected function tool_vocabulary($arguments) {
		return (new raster_inspector())->vocabulary();
	}

	protected function tool_annotations($arguments) {
		return raster_inspector::grammar();
	}

	protected function inspector_for($arguments) {
		$theme = $this->arg($arguments, 'theme', null);
		return new raster_inspector(null, is_string($theme) && $theme !== '' ? $theme : null);
	}

	protected function tool_list_views($arguments) {
		$inspector = $this->inspector_for($arguments);
		return array('theme' => $inspector->theme, 'folder' => raster_inspector::short($inspector->theme_dir()), 'views' => $inspector->views());
	}

	// Where a view lives, refusing every name that points somewhere else. The
	// extensions come from the same list that decides what is never served raw.
	protected function view_file($inspector, $view) {
		$view = ltrim(str_replace('\\', '/', (string)$view), '/');
		// a null byte would make the path functions below throw, not refuse
		if ($view === '' || strpos($view, "\0") !== false) {
			throw new InvalidArgumentException("'$view' is not a name inside the theme folder");
		}
		$extensions = array_values(array_unique(array_merge(array(ltrim($inspector->ext, '.')), private_paths::$view_extensions)));
		if (!preg_match('/\.([a-z0-9]+)$/', $view, $m) || !in_array($m[1], $extensions)) {
			throw new InvalidArgumentException("A view ends in .".implode(', .', $extensions));
		}
		$dir = $inspector->theme_dir();
		$path = $dir.'/'.$view;
		$parent = dirname($path);
		if (!is_dir($parent)) throw new InvalidArgumentException('There is no folder '.raster_inspector::short($parent).' to put it in');
		// the one check that matters: wherever ..'s and links lead, it has to
		// land inside this theme
		if (strpos(realpath($parent).'/', realpath($dir).'/') !== 0) {
			throw new InvalidArgumentException("'$view' is outside the theme folder");
		}
		return $path;
	}

	protected function tool_read_view($arguments) {
		$inspector = $this->inspector_for($arguments);
		$view = (string)$this->arg($arguments, 'view');
		$path = $this->view_file($inspector, $view);
		if (!is_file($path)) throw new InvalidArgumentException("There is no view '$view' in theme '{$inspector->theme}'. list_views has the names.");
		$content = file_get_contents($path);
		return array('view' => $view, 'theme' => $inspector->theme, 'bytes' => strlen($content), 'content' => $content);
	}

	protected function tool_check_view($arguments) {
		$inspector = $this->inspector_for($arguments);
		$content = (string)$this->arg($arguments, 'content');
		$name = (string)$this->arg($arguments, 'view', 'draft'.$inspector->ext);
		$problems = $inspector->lint_source($content, $name, $inspector->theme);
		$errors = count(array_filter($problems, function ($p) { return $p['severity'] === 'error'; }));
		return array('view' => $name, 'errors' => $errors, 'warnings' => count($problems) - $errors, 'problems' => $problems);
	}

	protected function tool_write_view($arguments) {
		if (!self::may_write_views()) {
			throw new RuntimeException('Writing views is off over HTTP: a template can call any model. Set config mcp_write_views to true to allow it, or use MCP over stdio on the machine with the files.');
		}
		$inspector = $this->inspector_for($arguments);
		$view = (string)$this->arg($arguments, 'view');
		$content = (string)$this->arg($arguments, 'content');
		$path = $this->view_file($inspector, $view);
		// lint the markup before it exists on disk, so a view with errors is
		// never written and nothing has to be rolled back
		$problems = $inspector->lint_source($content, $view, $inspector->theme);
		$errors = array_values(array_filter($problems, function ($p) { return $p['severity'] === 'error'; }));
		if ($errors) {
			return array(
				'written' => false, 'view' => $view, 'errors' => count($errors),
				'problems' => $problems,
				'hint' => 'The file was not changed. check_view lints a draft the same way, without writing.',
			);
		}
		$existed = is_file($path);
		if (file_put_contents($path, $content) === false) {
			throw new RuntimeException('Could not write '.raster_inspector::short($path));
		}
		util::content_changed();
		$result = array(
			'written' => true, 'view' => $view, 'created' => !$existed,
			'bytes' => strlen($content), 'warnings' => count($problems), 'problems' => $problems,
		);
		// a new print.cms or render.cms annotation declares a field: say so
		try {
			$status = (new raster_schema())->status();
			$result['schema'] = array(
				'drift' => (bool)$status['drift'],
				'next' => $status['drift'] ? (database::$frozen ? 'php bin/raster schema --apply' : 'development adds the columns on the next request') : null,
			);
		} catch (Exception $e) {
			$result['schema'] = array('error' => $e->getMessage());
		}
		return $result;
	}

	protected function tool_clear_cache($arguments) {
		$cleared = raster_cache::clear();
		return array('ok' => true, 'page_cache' => raster_cache::enabled()) + $cleared;
	}

	protected function tool_render_url($arguments) {
		$url = (string)$this->arg($arguments, 'url');
		if ($url === '' || $url[0] !== '/') throw new InvalidArgumentException("'url' is a path on this site and starts with /, e.g. /about");
		$limit = max(200, min(200000, (int)$this->arg($arguments, 'limit', 20000)));
		$root = dirname(rtrim(BASE, '/'));
		// a separate process: a page that dies, redirects or exits cannot take
		// this server down between calls
		$env = array('RASTER_APP' => boot::$appname, 'PATH' => (string)getenv('PATH'), 'HOME' => (string)getenv('HOME'), 'NO_COLOR' => '1');
		foreach (array('RASTER_DB', 'RASTER_URL', 'RASTER_ENV', 'RASTER_MAIL', 'RASTER_MAIL_FROM') as $name) {
			$value = getenv($name);
			if ($value !== false) $env[$name] = $value;
		}
		$command = array(PHP_BINARY, "$root/bin/raster", 'render', $url);
		$as = (string)$this->arg($arguments, 'as', '');
		if ($as !== '' && config::get('environment') === 'production') {
			throw new InvalidArgumentException("'as' only works outside production: render as staff on a development copy of the site");
		}
		if ($as !== '') $command[] = '--as='.$as;
		$process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $root, $env);
		if (!is_resource($process)) throw new RuntimeException('Could not start a process to render '.$url);
		$html = stream_get_contents($pipes[1]);
		$errors = trim(stream_get_contents($pipes[2]));
		$exit = proc_close($process);
		$status = preg_match('/HTTP (\d{3})/', $errors, $m) ? (int)$m[1] : ($exit === 0 ? 200 : null);
		$answer = array('url' => $url, 'ok' => $exit === 0, 'status' => $status);
		if ($as !== '') {
			// the summary line is ours, not an error
			$errors = trim(preg_replace('/^In-page editor.*?(?=^\S|\z)/ms', '', $errors));
			$answer['editor'] = cms_editor::summary($html);
		}
		return $answer + array(
			'bytes' => strlen($html),
			'truncated' => strlen($html) > $limit, 'html' => substr($html, 0, $limit),
			'errors' => $errors === '' ? null : $errors,
		);
	}
}
