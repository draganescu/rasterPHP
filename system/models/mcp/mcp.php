<?php

require_once BASE.'models/cms/cms.php';
require_once BASE.'tools/inspector.php';
require_once BASE.'tools/schema.php';

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

	// ##stdio transport: one JSON-RPC message per line
	public function stdio($in = STDIN, $out = STDOUT) {
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
					'instructions' => 'This is a Raster site. Its content model comes from HTML templates: call site_overview first to see pages, collections and their fields. Page edits create a new revision (see page_history). Fields that are not in the templates cannot be written; to add a field, edit the templates.',
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
		return array(
			$tool('site_overview', 'Lists the pages (URL, view, editable fields) and collections (fields, item count) of the site. Start here.', array(), array(), $read_only),
			$tool('get_page', 'Current values of the editable fields of a page.', array('page' => $page), array('page'), $read_only),
			$tool('update_page', 'Changes fields of a page. Stores a new revision; nothing is overwritten.', array('page' => $page, 'fields' => $fields), array('page', 'fields'), $write),
			$tool('page_history', 'Previous revisions of a page, newest first.', array('page' => $page, 'limit' => array('type' => 'integer', 'default' => 10)), array('page'), $read_only),
			$tool('list_items', 'Items of a collection.', array('collection' => $collection, 'limit' => array('type' => 'integer', 'default' => 50), 'offset' => array('type' => 'integer', 'default' => 0)), array('collection'), $read_only),
			$tool('get_item', 'One item of a collection.', array('collection' => $collection, 'id' => $id), array('collection', 'id'), $read_only),
			$tool('create_item', 'Adds an item to a collection.', array('collection' => $collection, 'fields' => $fields), array('collection', 'fields'), $write),
			$tool('update_item', 'Changes fields of a collection item.', array('collection' => $collection, 'id' => $id, 'fields' => $fields), array('collection', 'id', 'fields'), $write),
			$tool('delete_item', 'Deletes a collection item.', array('collection' => $collection, 'id' => $id), array('collection', 'id'), array('readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false)),
			$tool('lint_templates', 'Checks every template for annotation errors (unclosed blocks, unknown models, typos) with file:line positions.', array(), array(), $read_only),
			$tool('schema_status', 'Compares the content model in the templates with the database: missing columns, orphaned columns, likely renames.', array(), array(), $read_only),
		);
	}

	public function call_tool($name, $arguments) {
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
		}
		return array('base_url' => config::get('base_uri'), 'environment' => config::get('environment'), 'pages' => $pages, 'collections' => $collections);
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
		return array('collection' => $collection['name'], 'fields' => array_keys($collection['fields'])) + cms_store::list_items($collection['type'], $limit, $offset);
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
}
