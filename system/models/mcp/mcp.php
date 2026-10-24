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
		$bearer = mcp::bearer($_SERVER);
		if ($bearer === '' || !hash_equals($token, $bearer)) {
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
		$response = $this->answer($body);
		if ($response === null) $this->http_reply(202, null);
		$this->http_reply(200, $response);
	}

	// The token a request carries. Apache hands the Authorization header to
	// PHP-FPM only when the .htaccess copies it into the environment, and its
	// rewrite to index.php renames that REDIRECT_HTTP_AUTHORIZATION, leaving
	// HTTP_AUTHORIZATION empty; under mod_php only getallheaders() has it.
	static function bearer($server) {
		$headers = array();
		foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $name) {
			if (isset($server[$name])) $headers[] = $server[$name];
		}
		if ($server === $_SERVER && function_exists('getallheaders')) {
			foreach ((array)getallheaders() as $name => $value) {
				if (strtolower($name) === 'authorization') $headers[] = $value;
			}
		}
		foreach ($headers as $header) {
			if (preg_match('/^Bearer\s+(.+)$/i', trim((string)$header), $m)) return trim($m[1]);
		}
		return '';
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
			// PHP can't load a class again, so once a file this process
			// loaded has changed (a model's api(), types() or listens()), a
			// fresh process answers each message instead
			if ($this->changed_since_loaded()) {
				fwrite($out, $this->answer_fresh($line));
				fflush($out);
				continue;
			}
			$message = json_decode($line, true);
			$response = json_last_error() !== JSON_ERROR_NONE ? $this->error(null, -32700, 'Parse error') : $this->answer($message);
			$this->changed_since_loaded();
			if ($response !== null) {
				fwrite($out, json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
				fflush($out);
			}
		}
	}

	// the PHP files this process has loaded, as they were when it first saw them
	protected $loaded = array();

	protected function changed_since_loaded() {
		clearstatcache();
		$changed = false;
		foreach (get_included_files() as $file) {
			// a dictionary holds words, no class: read_strings reads it again
			if (strpos($file, APPBASE.'i18n/') === 0) continue;
			$stamp = @filemtime($file).' '.@filesize($file);
			if (!isset($this->loaded[$file])) $this->loaded[$file] = $stamp;
			elseif ($this->loaded[$file] !== $stamp) $changed = true;
		}
		return $changed;
	}

	// one line answered by `php bin/raster mcp` started for it: its reply
	// lines, as it wrote them
	protected function answer_fresh($line) {
		$root = dirname(rtrim(BASE, '/'));
		$log = tempnam(sys_get_temp_dir(), 'raster-mcp-');
		$env = array_merge(getenv(), array('RASTER_APP' => boot::$appname));
		$process = proc_open(array(mcp::php_binary(), "$root/bin/raster", 'mcp'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('file', $log, 'w')), $pipes, $root, $env);
		if (!is_resource($process)) {
			@unlink($log);
			return json_encode($this->error(null, -32603, 'Could not start a fresh MCP process'))."\n";
		}
		fwrite($pipes[0], $line."\n");
		fclose($pipes[0]);
		$reply = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		proc_close($process);
		$errors = trim((string)file_get_contents($log));
		@unlink($log);
		if ($errors !== '') log::warning('MCP: '.substr($errors, 0, 500));
		if (trim($reply) !== '') return $reply;
		// the fresh process died before it answered (a model it loads at boot
		// no longer parses): every request in the line still gets an answer
		$failed = $this->fresh_failed($line, $errors);
		return $failed === null ? '' : json_encode($failed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
	}

	// what a line gets when no fresh process could answer it: ping needs no
	// site code, a request gets -32603 with what the process wrote on its
	// way down, a notification nothing
	protected function fresh_failed($line, $errors) {
		$body = json_decode($line, true);
		if (json_last_error() !== JSON_ERROR_NONE) return $this->error(null, -32700, 'Parse error');
		$batch = is_array($body) && $body && array_keys($body) === range(0, count($body) - 1);
		$message = 'A fresh MCP process could not answer: '.($errors !== '' ? substr($errors, 0, 2000) : 'it exited without a reply');
		$responses = array();
		foreach ($batch ? $body : array($body) as $one) {
			if (!is_array($one) || !array_key_exists('id', $one)) continue;
			$id = is_scalar($one['id']) ? $one['id'] : null;
			$responses[] = isset($one['method']) && $one['method'] === 'ping' ? $this->result($id, new stdClass()) : $this->error($id, -32603, $message);
		}
		if (!$responses) return null;
		return $batch ? $responses : $responses[0];
	}

	// ##JSON-RPC
	// One message or a batch (a list of them), from either transport: the
	// answer, a list of answers, or null when there is nothing to send back.
	public function answer($body) {
		if (is_array($body) && $body && array_keys($body) === range(0, count($body) - 1)) {
			$responses = array_values(array_filter(array_map(array($this, 'handle'), $body)));
			return $responses ?: null;
		}
		return $this->handle($body);
	}

	public function handle($message) {
		mcp::load_tools();
		// anything but an object with a method is refused before it is read:
		// one bad line must not end the stdio server
		$id = is_array($message) && array_key_exists('id', $message) && is_scalar($message['id']) ? $message['id'] : null;
		if (!is_array($message) || !isset($message['method']) || !is_string($message['method'])) {
			return $this->error($id, -32600, 'Invalid request');
		}
		$method = $message['method'];
		$params = isset($message['params']) && is_array($message['params']) ? $message['params'] : array();
		$is_notification = !array_key_exists('id', $message);

		if (strpos($method, 'notifications/') === 0) return null;

		switch ($method) {
			case 'initialize':
				$requested = isset($params['protocolVersion']) ? $params['protocolVersion'] : null;
				return $this->result($id, array(
					'protocolVersion' => in_array($requested, self::$protocol_versions) ? $requested : self::$protocol_versions[0],
					'capabilities' => array('tools' => array('listChanged' => false)),
					'serverInfo' => array('name' => 'raster', 'version' => trim((string)@file_get_contents(BASE.'VERSION'))),
					'instructions' => 'This is a Raster site: views are plain HTML and the dynamic parts are HTML comments. Call describe first — it returns how URLs reach views, the content model the markup declares, and whether the templates lint clean. Before writing an annotation, check vocabulary (the models and their signatures) and annotations (the grammar); both are read from the code, so neither can be out of date. To change a template use check_view then write_view, which refuses markup that does not lint, and render_url to see the result. Content edits go through get_page/update_page and the item tools: page edits keep revisions, and fields that are not in the templates cannot be written — to add a field, edit the template. In production pages are cached for visitors who are not logged in: Raster\'s own tools clear that cache, but views, theme files, models, config or the database changed any other way do not, so call clear_cache after such a change. render_url never reads the cache, so a fresh render_url does not mean visitors see the change. The in-page editor only marks what the CMS prints: keep lists staff edit on render.cms.<name>, using its options for filters from the URL (field=?param), dates (date>=today) and sorting (order=date,time); for what those cannot say (totals, grouping by day, several types), the render method of a model returns its records through cms_records::listed($type, $rows), which keeps them editable; after changing such a page, call render_url with as="editor" to see that editing still works. read_asset and write_asset read and write the theme\'s CSS, JS and SVG files. Making a site multilingual: 1. set_languages, the default language first (["en", "ro"]). 2. Words editors change are CMS fields (print.cms.<name>); fixed interface words, such as a button\'s, are dictionary keys (print.i18n.<file>(\'key\')). 3. translation_status lists what a language lacks, with the default text. 4. Translate with update_page with lang, and write_strings. 5. Check with render_url /ro/….',
				));
			case 'ping':
				return $this->result($id, new stdClass());
			case 'tools/list':
				return $this->result($id, array('tools' => $this->tools()));
			case 'tools/call':
				$name = isset($params['name']) && is_string($params['name']) ? $params['name'] : '';
				$arguments = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : array();
				// a tool that isn't offered is the caller's mistake, not the tool's
				if (!in_array($name, array_map(function ($t) { return $t['name']; }, $this->tools()), true)) {
					return $this->error($id, -32602, "Unknown tool '$name'. tools/list has the names.");
				}
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
		$page = array('type' => 'string', 'description' => 'The page: /about, about or aboutpage; site for the site_* fields');
		$collection = array('type' => 'string', 'description' => 'news for render.cms.news');
		$fields = array('type' => 'object', 'description' => 'Field => value, as its type: 4, 4.5, true, 2026-10-05, 19:30; text may be HTML. Empty clears it.', 'additionalProperties' => array('type' => array('string', 'number', 'boolean', 'null')));
		$id = array('type' => 'integer', 'description' => 'Item id');
		$theme = array('type' => 'string', 'description' => 'A folder directly under views/; the site\'s theme by default');
		$read_only = array('readOnlyHint' => true, 'openWorldHint' => false);
		$write = array('readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false);
		$tool = function ($name, $description, $properties, $required, $annotations) {
			return array(
				'name' => $name,
				'description' => $description,
				'inputSchema' => array('type' => 'object', 'properties' => $properties ?: new stdClass()) + ($required ? array('required' => $required) : array()),
				'annotations' => $annotations,
			);
		};
		$lang = array('type' => 'string', 'description' => 'A language code from the site\'s languages. Leave out for the default language.');
		$dictionary = array('type' => 'string', 'pattern' => '^[a-z0-9_]+$', 'description' => 'Dictionary name: home for print.i18n.home(\'key\'). raster_editor is the editor\'s own words.');
		$asset = array('type' => 'string', 'description' => 'Path inside the theme folder, e.g. css/style.css');
		$tools = array(
			$tool('site_overview', 'Pages (URL, view, fields), collections (fields, item count), languages and event listeners. describe is the full answer.', array(), array(), $read_only),
			$tool('get_page', 'Current values of the editable fields of a page.', array('page' => $page, 'lang' => $lang), array('page'), $read_only),
			$tool('update_page', 'Changes fields of a page. Stores a new revision; nothing is overwritten.', array('page' => $page, 'fields' => $fields, 'lang' => $lang), array('page', 'fields'), $write),
			$tool('page_history', 'Previous revisions of a page, newest first.', array('page' => $page, 'limit' => array('type' => 'integer', 'default' => 10), 'lang' => $lang), array('page'), $read_only),
			$tool('list_items', 'Items of a collection.', array('collection' => $collection, 'limit' => array('type' => 'integer', 'default' => 50), 'offset' => array('type' => 'integer', 'default' => 0)), array('collection'), $read_only),
			$tool('get_item', 'One item of a collection.', array('collection' => $collection, 'id' => $id), array('collection', 'id'), $read_only),
			$tool('create_item', 'Adds an item to a collection.', array('collection' => $collection, 'fields' => $fields), array('collection', 'fields'), $write),
			$tool('update_item', 'Changes fields of a collection item.', array('collection' => $collection, 'id' => $id, 'fields' => $fields), array('collection', 'id', 'fields'), $write),
			$tool('run_action', 'Runs an action of a record type (confirm a booking); site_overview lists them. The model may refuse, saying why.',
				array('collection' => $collection, 'id' => $id, 'action' => array('type' => 'string'), 'input' => array('type' => 'object', 'description' => 'Values the action takes, if any', 'additionalProperties' => array('type' => 'string'))), array('collection', 'id', 'action'), $write),
			$tool('delete_item', 'Deletes a collection item.', array('collection' => $collection, 'id' => $id), array('collection', 'id'), array('readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false)),
			$tool('lint_templates', 'Lints every template: problems with file:line.', array(), array(), $read_only),
			$tool('schema_status', 'The content model against the database: missing and orphaned columns, renames.', array(), array(), $read_only),
			// ##Languages (#200)
			$tool('set_languages', 'Writes the languages to the site\'s config and adds translation columns. Not in production.',
				array('languages' => array('type' => 'array', 'items' => array('type' => 'string', 'pattern' => '^[a-z]{2,3}(-[a-z0-9]{2,8})?$'), 'description' => 'Language codes, the default first. [] turns languages off.')), array('languages'), $write),
			$tool('translation_status', 'What a language lacks: page, site and dictionary words, with the default text.',
				array('lang' => $lang, 'limit' => array('type' => 'integer', 'default' => 200), 'offset' => array('type' => 'integer', 'default' => 0)), array('lang'), $read_only),
			$tool('read_strings', 'A dictionary in a language, and the keys the views print.', array('file' => $dictionary, 'lang' => $lang), array('file', 'lang'), $read_only),
			$tool('write_strings', 'Merges words into a dictionary; Raster writes the file. Not in production.',
				array('file' => $dictionary, 'lang' => $lang, 'strings' => array('type' => 'object', 'additionalProperties' => array('type' => 'string'), 'description' => 'key => text; keys match ^[A-Za-z0-9_.-]+$')), array('file', 'lang', 'strings'), $write),
			// ##Working on the site itself, not only its content
			$tool('describe', 'The whole site in one answer: URLs to views, pages and collections, languages, settings, lint. Ask this first. The vocabulary only when a section names it.',
				array('sections' => array('type' => 'array', 'items' => array('type' => 'string', 'enum' => raster_describe::sections()), 'description' => 'Only these sections. All but vocabulary by default.')), array(), $read_only),
			$tool('vocabulary', 'Every name a template may call (models, method signatures, SQL queries, events), read from the code. Use it instead of guessing.',
				array(), array(), $read_only),
			$tool('annotations', 'The annotation grammar as data, which lint checks against.',
				array(), array(), $read_only),
			$tool('list_views', 'A theme\'s view files, as paths inside it.', array('theme' => $theme), array(), $read_only),
			$tool('read_view', 'The source of one view.', array('view' => array('type' => 'string', 'description' => 'Path inside the theme folder, e.g. about.html'), 'theme' => $theme), array('view'), $read_only),
			$tool('check_view', 'Lints markup without writing it: the problems, with line and column. Use it on a draft before write_view.',
				array('content' => array('type' => 'string', 'description' => 'The markup to check'), 'view' => array('type' => 'string', 'description' => 'Its file name, for the messages'), 'theme' => $theme), array('content'), $read_only),
			$tool('write_view', 'Writes a view if it lints clean (on errors the file is untouched and the problems come back; warnings don\'t stop it), and says what it does to the content model.',
				array('view' => array('type' => 'string', 'description' => 'Path inside the theme folder, e.g. about.html'), 'content' => array('type' => 'string'), 'theme' => $theme), array('view', 'content'), $write),
			$tool('read_asset', 'A theme\'s CSS, JS or SVG file.', array('path' => $asset, 'theme' => $theme), array('path'), $read_only),
			$tool('write_asset', 'Writes a theme\'s CSS, JS or SVG (no scripts in SVG). Clears the page cache.', array('path' => $asset, 'content' => array('type' => 'string'), 'theme' => $theme), array('path', 'content'), $write),
			$tool('render_url', 'Renders a URL (/ro/menu for a language) in a separate process: status and HTML. With as="editor" it says what the in-page editor can edit there; check that after changing a page staff edit.',
				array(
					'url' => array('type' => 'string', 'description' => 'A path, with its query, e.g. /menu or /bookings?stylist=ana'),
					'as' => array('type' => 'string', 'description' => 'An account\'s email or username, or a role (editor, admin, member). Leave out for a visitor. Not in production.'),
					'limit' => array('type' => 'integer', 'description' => 'HTML characters, 20000 by default'),
				), array('url'), $read_only),
			$tool('clear_cache', 'Throws the page cache away. Raster\'s tools clear it; call this after changing files or the database any other way.',
				array(), array(), array('readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false)),
		);
		if (!self::may_write_views()) {
			$tools = array_values(array_filter($tools, function ($t) { return !in_array($t['name'], array('write_view', 'write_asset'), true); }));
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
		// the stdio process outlives a `schema --apply` run beside it, and the
		// models, views and types an agent writes between calls
		cms_store::forget();
		cms_records::forget();
		raster_inspector::forget();
		$this->content_model = null;
		clearstatcache();
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
				'content' => array(array('type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))),
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

	// the content model, read once a call: write_view may change it
	protected $content_model = null;

	protected function model() {
		if ($this->content_model === null) $this->content_model = (new raster_inspector())->content_model();
		return $this->content_model;
	}

	protected function find_page($reference) {
		$reference = (string)$reference;
		foreach ($this->model()['pages'] as $page) {
			$view = $page['view'] === '*' ? '*' : substr($page['view'], 0, -strlen(config::get('views_ext', '.html')));
			$names = $page['view'] === '*' ? array('site', 'sitepage') : array($page['url'], $page['slug'], $page['type'], $page['view'], $view, '/'.$view);
			// a routed view of another theme is found by its address only:
			// its view's name (menu.html) is the site theme's /menu
			if (isset($page['theme'])) $names = array($page['url'], $page['slug'], $page['type']);
			if (in_array($reference, $names, true)) {
				return $page;
			}
		}
		// any address of a page finds it: a literal route's URLs with
		// parameters (/specials/day/friday is /specials), page numbers and filters
		if (strpos($reference, '/') === 0) {
			$slug = cms::slug_for_uri($reference, (new raster_inspector())->route_for($reference));
			foreach ($this->model()['pages'] as $page) {
				if ($page['slug'] === $slug) return $page;
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
		// a translation is written by its field's name and a lang, never as its column
		foreach (array_keys($fields) as $name) {
			if (strpos((string)$name, '__') !== false) throw new InvalidArgumentException("'$name' is not a field: write '".substr((string)$name, 0, strpos((string)$name, '__'))."' by its own name, with lang for a translation");
		}
		return $fields;
	}

	// ##Languages (#200)

	// the language a content tool is asked for, as the site lists it, or null
	// when it is left out; refused when it isn't one of the site's languages
	protected function lang_argument($arguments) {
		$lang = $this->arg($arguments, 'lang', null);
		if ($lang === null || $lang === '') return null;
		if (!is_string($lang)) throw new InvalidArgumentException("'lang' is a language code, such as ".(i18n::default_language() ?: 'en'));
		foreach (i18n::available() as $code) {
			if (strtolower($code) !== strtolower((string)$lang)) continue;
			if (i18n::is_default($code) || i18n::enabled()) return $code;
		}
		$shown = is_string($lang) ? $lang : json_encode($lang);
		if (!i18n::enabled()) throw new InvalidArgumentException("'$shown': the content of this site is in one language (".(i18n::default_language() ?: 'none set').'). set_languages adds languages.');
		throw new InvalidArgumentException("'$shown' is not a language of this site: ".implode(', ', i18n::available()));
	}

	// a translation language, refusing the default one
	protected function translation_lang($arguments) {
		$lang = $this->lang_argument($arguments);
		if ($lang === null || i18n::is_default($lang)) {
			throw new InvalidArgumentException("'lang' is a translation language (".implode(', ', array_slice(i18n::available(), 1)).'); '.(i18n::default_language() ?: 'the default').' is what the templates and the fields already hold');
		}
		return $lang;
	}

	// the fields of a page that have a translation per language: as the
	// templates say (the content model's translate) or the database has them
	protected function translated_fields($page) {
		$fields = array();
		foreach ($page['fields'] as $name => $field) {
			if (!empty($field['translate'])) $fields[] = $name;
		}
		return array_values(array_unique(array_merge($fields, array_intersect(cms_types::translated($page['type']), array_keys($page['fields'])))));
	}

	protected function dev_only($tool) {
		if (config::get('environment') === 'production') throw new InvalidArgumentException("$tool only works outside production: change a development copy of the site and deploy it");
	}

	protected function tool_set_languages($arguments) {
		$this->dev_only('set_languages');
		$codes = $this->arg($arguments, 'languages');
		if (!is_array($codes) || ($codes && array_keys($codes) !== range(0, count($codes) - 1))) throw new InvalidArgumentException("'languages' is a list of language codes, the default first: [\"en\", \"ro\"]");
		$languages = array();
		foreach ($codes as $code) {
			$code = is_string($code) ? strtolower(trim($code)) : '';
			if (!preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $code)) throw new InvalidArgumentException('A language code is two or three letters, with a region if needed: en, ro, pt-br ('.json_encode($codes).')');
			if (in_array($code, $languages, true)) throw new InvalidArgumentException("'$code' is listed twice");
			$languages[] = $code;
		}
		$file = APPBASE.'config/the_app.php';
		$source = is_file($file) ? file_get_contents($file) : "<?php\n";
		$lines = $languages ? array(
			'multilingual' => "config::set('multilingual')->to(".(count($languages) > 1 ? 'true' : 'false').');',
			'languages' => "config::set('languages')->to(array('".implode("', '", $languages)."'));",
		) : array('multilingual' => null, 'languages' => null);
		$written = self::config_lines($source, $lines);
		if ($written !== $source && file_put_contents($file, $written) === false) throw new RuntimeException('Could not write '.raster_inspector::short($file));
		if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
		// this process goes on with them; the next call reads the file again
		config::set('multilingual')->to(count($languages) > 1);
		config::set('languages')->to($languages ?: null);
		$added = 0;
		if (i18n::enabled() && !database::$frozen) {
			cms_store::connect();
			foreach ($this->model()['pages'] as $page) {
				if (!cms_store::table_exists($page['type'])) continue;
				$before = count(cms_store::columns($page['type']));
				$this->ensure_columns($page['type'], $page['fields'], array('slug', 'updated_at'));
				$added += count(cms_store::columns($page['type'])) - $before;
			}
		}
		$described = raster_describe::languages();
		return array('enabled' => $described['enabled'], 'default' => $described['default'], 'languages' => $described['list'], 'urls' => $described['urls'], 'columns_added' => $added, 'missing' => $described['missing']);
	}

	// $source with each setting of $lines (name => its line, or null to
	// remove it) written once: the first statement that sets it replaced,
	// later ones removed, a missing one added at the end. Only statements
	// of PHP count, so a commented-out example stays as it is.
	static function config_lines($source, $lines) {
		$tokens = token_get_all($source);
		$found = array();
		$offset = 0;
		$spans = array();
		foreach ($tokens as $i => $token) {
			$text = is_array($token) ? $token[1] : $token;
			if (is_array($token) && $token[0] === T_STRING && strtolower($text) === 'config') {
				// config :: set ( 'name'
				$next = array();
				for ($j = $i + 1; $j < count($tokens) && count($next) < 4; $j++) {
					if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
					$next[] = $tokens[$j];
				}
				$name = count($next) === 4 && is_array($next[0]) && $next[0][0] === T_DOUBLE_COLON && is_array($next[1]) && strtolower($next[1][1]) === 'set' && $next[2] === '(' && is_array($next[3]) && $next[3][0] === T_CONSTANT_ENCAPSED_STRING ? substr($next[3][1], 1, -1) : null;
				if ($name !== null && array_key_exists($name, $lines)) {
					// the statement ends at the ; outside every bracket
					$depth = 0;
					$end = $offset;
					for ($j = $i; $j < count($tokens); $j++) {
						$t = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
						$end += strlen($t);
						if (in_array($t, array('(', '[', '{'), true)) $depth++;
						if (in_array($t, array(')', ']', '}'), true)) $depth--;
						if ($t === ';' && $depth === 0) break;
					}
					$spans[] = array($name, $offset, $end);
				}
			}
			$offset += strlen($text);
		}
		// from the last to the first, so the offsets stay right
		$first = array();
		foreach ($spans as $span) if (!isset($first[$span[0]])) $first[$span[0]] = $span[1];
		foreach (array_reverse($spans) as $span) {
			list($name, $start, $end) = $span;
			$line = $first[$name] === $start ? $lines[$name] : null;
			if ($line === null) {
				// the whole line goes when nothing else is on it
				$before = strrpos(substr($source, 0, $start), "\n");
				$before = $before === false ? 0 : $before + 1;
				$after = strpos($source, "\n", $end);
				$after = $after === false ? strlen($source) : $after + 1;
				if (trim(substr($source, $before, $start - $before)) === '' && trim(substr($source, $end, $after - $end)) === '') {
					$start = $before;
					$end = $after;
				}
				$source = substr($source, 0, $start).substr($source, $end);
			} else {
				$source = substr($source, 0, $start).$line.substr($source, $end);
			}
			$found[$name] = true;
		}
		$add = '';
		foreach ($lines as $name => $line) {
			if ($line !== null && !isset($found[$name])) $add .= $line."\n";
		}
		if ($add === '') return $source;
		// before a closing tag the file ends with
		if (preg_match('/\?>\s*$/', $source, $m, PREG_OFFSET_CAPTURE)) {
			return rtrim(substr($source, 0, $m[0][1]))."\n".$add.substr($source, $m[0][1]);
		}
		return rtrim($source)."\n".$add;
	}

	protected function tool_translation_status($arguments) {
		$lang = $this->translation_lang($arguments);
		$limit = max(1, (int)$this->arg($arguments, 'limit', 200));
		$offset = max(0, (int)$this->arg($arguments, 'offset', 0));
		$rows = raster_describe::translations($lang);
		$missing = array();
		$done = 0;
		foreach ($rows as $row) {
			if ($row['done']) { $done++; continue; }
			$missing[] = array('kind' => $row['kind'], 'where' => $row['where'], 'field' => $row['field'], 'default_value' => mb_substr($row['default_value'], 0, 300), 'length' => mb_strlen($row['default_value']), 'shared' => false);
		}
		return array('lang' => $lang, 'missing' => array_slice($missing, $offset, $limit), 'total' => count($missing), 'done' => $done);
	}

	// a dictionary's name and language: raster_editor's may be any language
	// the editor has words in, the others one of the site's
	protected function dictionary_argument($arguments) {
		$file = $this->arg($arguments, 'file');
		if (!is_string($file) || !preg_match('/^[a-z0-9_]+$/', $file)) throw new InvalidArgumentException("'file' is a dictionary name: lowercase letters, digits and _ (home for print.i18n.home('key'))");
		$lang = $this->arg($arguments, 'lang');
		foreach (i18n::available() as $code) {
			if (is_string($lang) && strtolower($code) === strtolower($lang)) return array($file, $code);
		}
		if ($file === 'raster_editor' && is_string($lang) && in_array(strtolower($lang), self::editor_languages(), true)) return array($file, strtolower($lang));
		throw new InvalidArgumentException("'lang' is one of the site's languages: ".(implode(', ', i18n::available()) ?: 'none yet; set_languages adds them'));
	}

	static function editor_languages() {
		$languages = array('en');
		foreach (glob(BASE.'models/cms/editor/lang/*.php') ?: array() as $file) $languages[] = basename($file, '.php');
		return $languages;
	}

	// the keys a dictionary is for: what the views print, or for
	// raster_editor the editor's own words
	protected function used_keys($file) {
		if ($file === 'raster_editor') {
			$keys = array();
			foreach (glob(BASE.'models/cms/editor/lang/*.php') ?: array() as $words) $keys = array_merge($keys, array_keys((array)raster_describe::dictionary_file($words)));
			return array_values(array_unique($keys));
		}
		$used = raster_describe::strings_used(new raster_inspector());
		return isset($used[$file]) ? array_map('strval', array_keys($used[$file])) : array();
	}

	protected function tool_read_strings($arguments) {
		list($file, $lang) = $this->dictionary_argument($arguments);
		$strings = raster_describe::dictionary($file, $lang);
		$used = $this->used_keys($file);
		// the default language is the templates' own text; the editor has its words
		$missing = $file === 'raster_editor' || i18n::is_default($lang) ? array() : array_values(array_filter($used, function ($key) use ($strings) { return !isset($strings[$key]) || trim((string)$strings[$key]) === ''; }));
		return array('file' => $file, 'lang' => $lang, 'strings' => (object)$strings, 'used_keys' => $used, 'missing_keys' => $missing);
	}

	protected function tool_write_strings($arguments) {
		$this->dev_only('write_strings');
		list($file, $lang) = $this->dictionary_argument($arguments);
		$strings = $this->arg($arguments, 'strings');
		if (!is_array($strings) || !$strings || array_keys($strings) === range(0, count($strings) - 1)) throw new InvalidArgumentException("'strings' is an object of key => text, with at least one key");
		foreach ($strings as $key => $value) {
			if (!preg_match('/^[A-Za-z0-9_.-]+$/', (string)$key)) throw new InvalidArgumentException("'$key' is not a key: letters, digits, _, . and -");
			if (!is_string($value)) throw new InvalidArgumentException("The text of '$key' is a string");
		}
		$dir = APPBASE.'i18n/'.$lang;
		$path = "$dir/$file.php";
		if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new RuntimeException('Could not make '.raster_inspector::short($dir));
		$merged = array_merge(raster_describe::dictionary($file, $lang), array_map('strval', $strings));
		// Raster writes the PHP: the agent's words are values, never code
		if (file_put_contents($path, '<?php'."\n".'return '.var_export($merged, true).";\n", LOCK_EX) === false) throw new RuntimeException('Could not write '.raster_inspector::short($path));
		if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
		util::content_changed();
		$used = $this->used_keys($file);
		$unused = array_values(array_filter(array_map('strval', array_keys($strings)), function ($key) use ($used, $file) {
			// an action's or a problem's words are the site's own
			if ($file === 'raster_editor' && preg_match('/^(action|problem)_/', $key)) return false;
			return !in_array($key, $used, true);
		}));
		return array('written' => count($strings), 'unused_keys' => $unused);
	}

	protected function tool_site_overview($arguments) {
		cms_store::connect();
		$model = $this->model();
		$pages = array();
		foreach ($model['pages'] as $page) {
			$pages[] = array('url' => $page['url'], 'view' => $page['view'], 'fields' => array_keys($page['fields'])) + raster_describe::types($page['fields']);
		}
		$collections = array();
		foreach ($model['collections'] as $collection) {
			$collections[] = array(
				'name' => $collection['name'],
				'fields' => array_keys($collection['fields']),
			) + raster_describe::types($collection['fields']) + array(
				'items' => cms_store::table_exists($collection['type']) ? (int)R::count($collection['type']) : 0,
				'used_in' => $collection['views'],
				'item_url' => cms::item_path($collection['name'], '{slug}'),
			);
			// records of a type a model declares: private unless it says public,
			// with fields only the model writes, and actions
			if (isset($collection['model'])) {
				$collections[count($collections) - 1] += array('declared_by' => $collection['model'], 'public' => $collection['public'], 'readonly' => $collection['readonly'], 'actions' => array_keys($collection['actions']));
				$collections[count($collections) - 1]['fields'] = array_values(array_diff(array_keys($collection['fields']), $collection['hidden']));
				if (isset($collections[count($collections) - 1]['types'])) $collections[count($collections) - 1]['types'] = array_diff_key($collections[count($collections) - 1]['types'], array_flip($collection['hidden']));
			}
		}
		// who listens to what: saving an item or a booking may do more than it says
		$events = array();
		foreach (event::bindings() as $event => $listeners) {
			$listeners = array_values(array_filter($listeners, function ($l) { return !preg_match('/^(controller|log)\./', $l); }));
			if ($listeners) $events[$event] = $listeners;
		}
		return array('base_url' => config::get('base_uri'), 'environment' => config::get('environment'), 'languages' => raster_describe::languages(), 'pages' => $pages, 'collections' => $collections, 'events' => $events);
	}

	protected function tool_get_page($arguments) {
		cms_store::connect();
		$page = $this->find_page($this->arg($arguments, 'page'));
		$lang = $this->lang_argument($arguments);
		if ($lang !== null && i18n::is_default($lang)) $lang = null;
		$stored = cms_store::page_values($page['type']);
		$translated = $lang === null ? array() : $this->translated_fields($page);
		$fields = array();
		foreach ($page['fields'] as $name => $field) {
			$has = $stored && array_key_exists($name, $stored['fields']) && $stored['fields'][$name] !== null;
			$fields[$name] = $has ? $stored['fields'][$name] : $field['default'];
			if ($lang === null) continue;
			// in a language: its own value next to the default one's
			$shared = !in_array($name, $translated, true);
			$column = cms_types::column($name, $lang);
			$value = $shared ? $fields[$name] : ($stored && isset($stored['fields'][$column]) ? (string)$stored['fields'][$column] : '');
			$fields[$name] = array('value' => $value, 'default_value' => $fields[$name], 'translated' => !$shared && trim($value) !== '', 'shared' => $shared);
		}
		return array(
			'url' => $page['url'],
			'view' => $page['view'],
		) + ($lang === null ? array() : array('lang' => $lang)) + array(
			'revision' => $stored ? $stored['revision'] : null,
			'updated_at' => $stored ? $stored['updated_at'] : null,
			'fields' => $fields,
		);
	}

	protected function tool_update_page($arguments) {
		cms_store::connect();
		$page = $this->find_page($this->arg($arguments, 'page'));
		$fields = $this->fields_argument($arguments);
		$lang = $this->lang_argument($arguments);
		if ($lang !== null && i18n::is_default($lang)) $lang = null;
		if ($lang !== null) {
			$translated = $this->translated_fields($page);
			foreach (array_keys($fields) as $name) {
				if (isset($page['fields'][$name]) && !in_array($name, $translated, true)) throw new InvalidArgumentException("'$name' is shared by every language; update it without lang");
			}
		}
		$this->ensure_columns($page['type'], $page['fields'], array('slug', 'updated_at'));
		// a page that was never rendered starts from the template defaults,
		// which are the default language's
		if (!cms_store::latest($page['type'])) {
			$defaults = array();
			foreach ($page['fields'] as $name => $field) $defaults[$name] = trim($field['default']);
			if ($lang === null) $fields = $fields + $defaults;
			else cms_store::update_page($page['type'], $page['slug'], $defaults, array_keys($page['fields']));
		}
		try {
			cms_store::update_page($page['type'], $page['slug'], $fields, array_keys($page['fields']), $lang);
		} catch (cms_refused $e) {
			if (!in_array('field_shared', $e->problems, true)) throw $e;
			// the templates translate it, but a frozen database has no column for it yet
			$missing = array_values(array_diff(array_keys($fields), cms_types::translated($page['type'])));
			throw new InvalidArgumentException("The database has no translation column for '".($missing ? $missing[0] : '')."' yet: run `php bin/raster schema --apply` on the server");
		}
		return $this->tool_get_page(array('page' => $page['type']) + ($lang === null ? array() : array('lang' => $lang)));
	}

	protected function tool_page_history($arguments) {
		cms_store::connect();
		$page = $this->find_page($this->arg($arguments, 'page'));
		$lang = $this->lang_argument($arguments);
		$limit = (int)$this->arg($arguments, 'limit', 10);
		if ($lang === null) return array('url' => $page['url'], 'revisions' => cms_store::page_history($page['type'], $limit));
		// the revisions saved in that language; older ones, saved before
		// languages, count as the default language's
		$revisions = array_values(array_filter(cms_store::page_history($page['type'], max(200, $limit)), function ($revision) use ($lang) {
			$saved = isset($revision['saved_lang']) ? (string)$revision['saved_lang'] : '';
			return strtolower($saved) === strtolower($lang) || ($saved === '' && i18n::is_default($lang));
		}));
		return array('url' => $page['url'], 'lang' => $lang, 'revisions' => array_slice($revisions, 0, max(1, $limit)));
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

	// in development, the columns a write needs, of the templates' types, so a
	// page or collection no request rendered yet is stored the same way
	protected function ensure_columns($table, $fields, $system) {
		if (database::$frozen) return;
		$types = array_intersect_key(cms_types::$system, array_flip($system));
		$translated = array();
		foreach ($fields as $name => $field) {
			$types[$name] = $field['type'];
			// and a column per language for the fields the templates translate
			if (!empty($field['translate'])) $translated[] = $name;
		}
		cms_types::ensure($table, $types, $translated);
	}

	protected function tool_create_item($arguments) {
		cms_store::connect();
		$collection = $this->find_collection($this->arg($arguments, 'collection'));
		if (!isset($collection['model'])) $this->ensure_columns($collection['type'], $collection['fields'], array('slug', 'enabled', 'published_at', 'updated_at'));
		return cms_store::save_item($collection['type'], 0, $this->fields_argument($arguments), array_keys($collection['fields']), 'editor', $collection['name']);
	}

	protected function tool_update_item($arguments) {
		cms_store::connect();
		$collection = $this->find_collection($this->arg($arguments, 'collection'));
		return cms_store::save_item($collection['type'], (int)$this->arg($arguments, 'id'), $this->fields_argument($arguments), array_keys($collection['fields']), 'editor', $collection['name']);
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
		if (!cms_store::delete_item($collection['type'], $id, $collection['name'])) throw new InvalidArgumentException('No item with that id');
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
		// the vocabulary is most of a full answer, and has its own tool
		return raster_describe::site($sections ?: array_values(array_diff(raster_describe::sections(), array('vocabulary'))));
	}

	protected function tool_vocabulary($arguments) {
		return (new raster_inspector())->vocabulary();
	}

	protected function tool_annotations($arguments) {
		return raster_inspector::grammar();
	}

	// The theme an agent names is a folder directly under views/, never a
	// path: '../..' or a theme folder linked to somewhere else would let the
	// view tools read and write files outside the site's views.
	protected function inspector_for($arguments) {
		$theme = $this->arg($arguments, 'theme', null);
		if ($theme === null || $theme === '') return new raster_inspector();
		$inspector = new raster_inspector();
		// compared with the real views folder, wherever links lead
		$views = realpath($inspector->views_dir);
		$is_theme = function ($name) use ($views) {
			if ($views === false || !is_string($name) || !preg_match('/^[A-Za-z0-9_-]+$/D', $name)) return false;
			$dir = realpath("$views/$name");
			return $dir !== false && is_dir($dir) && dirname($dir) === $views;
		};
		if (!$is_theme($theme)) {
			$themes = $views === false ? array() : array_values(array_filter(scandir($views), $is_theme));
			$shown = is_string($theme) ? $theme : json_encode($theme);
			throw new InvalidArgumentException("'$shown' is not a theme: a theme is the name of a folder directly under views/ (".implode(', ', $themes).')');
		}
		$inspector->theme = $theme;
		return $inspector;
	}

	protected function tool_list_views($arguments) {
		$inspector = $this->inspector_for($arguments);
		$views = array_values(array_filter($inspector->views(), function ($view) use ($inspector) {
			return $this->in_theme($inspector, $inspector->theme_dir().'/'.$view);
		}));
		return array('theme' => $inspector->theme, 'folder' => raster_inspector::short($inspector->theme_dir()), 'views' => $views);
	}

	// Whether a path inside the theme really is there, wherever ..'s and
	// links lead: its folder, and the file itself when it is a link. A link
	// that leads nowhere is refused too, since writing would create its target.
	protected function in_theme($inspector, $path) {
		$dir = realpath($inspector->theme_dir());
		$parent = realpath(dirname($path));
		if ($dir === false || $parent === false || strpos($parent.'/', $dir.'/') !== 0) return false;
		if (!is_link($path) && !file_exists($path)) return true;
		$real = realpath($path);
		return $real !== false && strpos($real, $dir.'/') === 0;
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
		// the one check that matters: it has to land inside this theme
		if (!$this->in_theme($inspector, $path)) {
			throw new InvalidArgumentException("'$view' is outside the theme folder, or links out of it");
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
		$path = $inspector->theme_dir().'/'.ltrim(str_replace('\\', '/', $name), '/');
		if (strpos($name, "\0") === false && is_dir(dirname($path)) && !$this->in_theme($inspector, $path)) {
			throw new InvalidArgumentException("'$name' is outside the theme folder, or links out of it");
		}
		$problems = $inspector->lint_source($content, $name, $inspector->theme);
		$errors = count(array_filter($problems, function ($p) { return $p['severity'] === 'error'; }));
		return array('view' => $name, 'errors' => $errors, 'warnings' => count($problems) - $errors, 'problems' => $problems) + $this->new_fields($inspector, $content, $name);
	}

	// On a multilingual site, the fields markup declares that the content
	// model doesn't have yet, each with whether it gets a translation per
	// language (translate) or is shared by all of them, so an agent sees
	// before writing that a phone number is shared and a heading translated.
	protected function new_fields($inspector, $content, $view) {
		if (!i18n::enabled() || strpos($view, "\0") !== false) return array();
		$view = ltrim(str_replace('\\', '/', $view), '/');
		$model = $inspector->content_model();
		$known = array();
		foreach ($model['pages'] as $page) {
			if ($page['view'] === '*' || ($page['view'] === $view && !isset($page['theme']))) $known = array_merge($known, array_keys($page['fields']));
		}
		$draft = $inspector->fields_in($content, $view);
		$new = array();
		foreach ($draft['fields'] as $name => $field) {
			if (!in_array($name, $known, true)) $new[] = array('field' => $name, 'translate' => !empty($field['translate']));
		}
		foreach ($draft['collections'] as $name => $collection) {
			$have = isset($model['collections'][$name]) ? $model['collections'][$name]['fields'] : array();
			foreach ($collection['fields'] as $field => $info) {
				if (!isset($have[$field])) $new[] = array('field' => $field, 'collection' => $name, 'translate' => !empty($info['translate']));
			}
		}
		return array('new_fields' => $new);
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
		// what it adds to the content model, read before the file changes it
		$new = $this->new_fields($inspector, $content, $view);
		if (file_put_contents($path, $content) === false) {
			throw new RuntimeException('Could not write '.raster_inspector::short($path));
		}
		util::content_changed();
		$result = array(
			'written' => true, 'view' => $view, 'created' => !$existed,
			'bytes' => strlen($content), 'warnings' => count($problems), 'problems' => $problems,
		) + $new;
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

	// ##Theme files that aren't views (#159)
	//
	// A stylesheet, a script or an SVG picture, with the same checks as a
	// view: inside the theme, no link out. Like write_view, write_asset is
	// offered over HTTP only with mcp_write_views: a script runs in every
	// visitor's browser, as a template runs on the server.

	static $asset_extensions = array('css', 'js', 'svg');

	protected function asset_file($inspector, $path) {
		$path = ltrim(str_replace('\\', '/', (string)$path), '/');
		if ($path === '' || strpos($path, "\0") !== false) throw new InvalidArgumentException("'$path' is not a name inside the theme folder");
		if (!preg_match('/\.([a-z0-9]+)$/', $path, $m) || !in_array($m[1], self::$asset_extensions, true)) throw new InvalidArgumentException('An asset ends in .'.implode(', .', self::$asset_extensions).'; views go through read_view and write_view');
		$file = $inspector->theme_dir().'/'.$path;
		if (!is_dir(dirname($file))) throw new InvalidArgumentException('There is no folder '.raster_inspector::short(dirname($file)).' to put it in');
		if (!$this->in_theme($inspector, $file)) throw new InvalidArgumentException("'$path' is outside the theme folder, or links out of it");
		return array($path, $file);
	}

	protected function tool_read_asset($arguments) {
		$inspector = $this->inspector_for($arguments);
		list($path, $file) = $this->asset_file($inspector, $this->arg($arguments, 'path'));
		if (!is_file($file)) throw new InvalidArgumentException("There is no '$path' in theme '{$inspector->theme}'");
		$content = file_get_contents($file);
		return array('path' => $path, 'theme' => $inspector->theme, 'bytes' => strlen($content), 'content' => $content);
	}

	protected function tool_write_asset($arguments) {
		if (!self::may_write_views()) {
			throw new RuntimeException('Writing theme files is off over HTTP: a script runs in every visitor\'s browser. Set config mcp_write_views to true to allow it, or use MCP over stdio on the machine with the files.');
		}
		$inspector = $this->inspector_for($arguments);
		list($path, $file) = $this->asset_file($inspector, $this->arg($arguments, 'path'));
		$content = $this->arg($arguments, 'content');
		if (!is_string($content)) throw new InvalidArgumentException("'content' is the file's text");
		if (substr($path, -4) === '.svg') {
			$problem = self::svg_problem($content);
			if ($problem !== null) throw new InvalidArgumentException("The SVG was not written: $problem");
		}
		$existed = is_file($file);
		if (file_put_contents($file, $content) === false) throw new RuntimeException('Could not write '.raster_inspector::short($file));
		util::content_changed();
		return array('written' => true, 'path' => $path, 'created' => !$existed, 'bytes' => strlen($content));
	}

	// why an SVG may not be a theme's picture, or null: it must be XML,
	// and run nothing when it is opened on its own (no script element, no
	// on* attribute, no javascript: link)
	static function svg_problem($svg) {
		if (!function_exists('xml_parser_create')) return 'checking it needs PHP\'s xml extension';
		if (stripos($svg, '<!ENTITY') !== false) return 'it declares entities';
		$problem = null;
		$parser = xml_parser_create('UTF-8');
		xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
		xml_set_element_handler($parser, function ($parser, $name, $attributes) use (&$problem) {
			$local = strtolower(substr((string)strrchr(':'.$name, ':'), 1));
			if ($local === 'script') $problem = $problem ?: 'it has a <script> element';
			foreach ($attributes as $attribute => $value) {
				$attribute = strtolower(substr((string)strrchr(':'.$attribute, ':'), 1));
				if (strpos($attribute, 'on') === 0) $problem = $problem ?: "it has an event attribute ($attribute)";
				if (preg_match('/^\s*javascript:/i', preg_replace('/[\x00-\x20]+/', '', (string)$value))) $problem = $problem ?: "it links to javascript: ($attribute)";
			}
		}, function ($parser, $name) {});
		$parsed = xml_parse($parser, $svg, true);
		if (!$parsed) $problem = 'it is not well-formed XML ('.xml_error_string(xml_get_error_code($parser)).' on line '.xml_get_current_line_number($parser).')';
		return $problem;
	}

	protected function tool_clear_cache($arguments) {
		$cleared = raster_cache::clear();
		if (isset($cleared['error'])) throw new RuntimeException(ucfirst($cleared['error']));
		return array('ok' => true, 'page_cache' => raster_cache::enabled()) + $cleared;
	}

	// The PHP that runs bin/raster. Over HTTP under PHP-FPM, PHP_BINARY is
	// php-fpm itself, which can't; the command line PHP of the same version
	// sits next to it in PHP_BINDIR (php8.2 on Debian, else php).
	static function php_binary($sapi = PHP_SAPI) {
		if ($sapi === 'cli' && PHP_BINARY !== '') return PHP_BINARY;
		foreach (array('php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, 'php') as $name) {
			if (is_executable(PHP_BINDIR.'/'.$name)) return PHP_BINDIR.'/'.$name;
		}
		return 'php';
	}

	protected function tool_render_url($arguments) {
		$url = (string)$this->arg($arguments, 'url');
		if ($url === '' || $url[0] !== '/') throw new InvalidArgumentException("'url' is a path on this site and starts with /, e.g. /about");
		$limit = max(200, min(200000, (int)$this->arg($arguments, 'limit', 20000)));
		$root = dirname(rtrim(BASE, '/'));
		// a separate process: a page that dies, redirects or exits cannot take
		// this server down between calls. It gets the whole environment, as
		// `raster send` does, since a site's config may read its own variables.
		$env = array_merge(getenv(), array('RASTER_APP' => boot::$appname, 'NO_COLOR' => '1'));
		$command = array(mcp::php_binary(), "$root/bin/raster", 'render', $url);
		$as = (string)$this->arg($arguments, 'as', '');
		if ($as !== '' && config::get('environment') === 'production') {
			throw new InvalidArgumentException("'as' only works outside production: render as staff on a development copy of the site");
		}
		if ($as !== '') $command[] = '--as='.$as;
		// what the page logs goes to a file: read from a second pipe after the
		// page, a page that logs more than a pipe holds would wait on this
		// server while it waits on the page
		$log = tempnam(sys_get_temp_dir(), 'raster-render-');
		$process = proc_open($command, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', $log, 'w')), $pipes, $root, $env);
		if (!is_resource($process)) { @unlink($log); throw new RuntimeException('Could not start a process to render '.$url); }
		$html = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$exit = proc_close($process);
		$errors = trim((string)file_get_contents($log));
		@unlink($log);
		$status = preg_match('/HTTP (\d{3})/', $errors, $m) ? (int)$m[1] : ($exit === 0 ? 200 : null);
		$answer = array('url' => $url, 'ok' => $exit === 0, 'status' => $status);
		if ($as !== '') {
			// the summary line is ours, not an error
			$errors = trim(preg_replace('/^In-page editor.*?(?=^\S|\z)/ms', '', $errors));
			$answer['editor'] = cms_editor::summary($html);
			// the language the editor saves in: the page's own (/ro/menu is ro)
			if (i18n::enabled() && preg_match('#<script id="raster-editor-config" type="application/json">(.*?)</script>#s', $html, $m)) {
				$config = json_decode($m[1], true);
				$answer['editing_lang'] = isset($config['lang']) ? $config['lang'] : null;
			}
		}
		return $answer + array(
			'bytes' => strlen($html),
			'truncated' => strlen($html) > $limit, 'html' => substr($html, 0, $limit),
			'errors' => $errors === '' ? null : $errors,
		);
	}
}
