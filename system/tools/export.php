<?php
// #Static export
//
//   php bin/raster export <folder> [--url=https://example.com/] [--skip=pattern] [--clean]
//
// Writes the site as plain files any static host can serve. It runs the site
// with PHP's built-in server, visits every page the way a visitor would
// (no session, so no drafts and no editor), follows the links it finds, and
// saves each response:
//
//   /                          index.html
//   /about                     about/index.html
//   /menu/menu_item/cortado    menu/menu_item/cortado/index.html
//   /journal.rss               journal.rss
//   a missing page             404.html
//
// Theme files and media are copied next to them. Links become --url (or
// root-relative links when it's not given). With several languages, each
// other language goes in /<lang>/, and the language switcher links there.
// What a static site can't do (forms, accounts, query strings) is listed at
// the end so nothing fails quietly.
class raster_export {

	public $root;
	public $target;
	public $url;
	public $skip = array();
	public $pages = array();      // path => array(language => file written)
	public $warnings = array();
	public $files = 0;
	public $skipped = array();
	protected $linked = array();    // pages that redirect (they need an account)
	protected $base;              // the local server's address
	protected $server;
	protected $queue = array();
	protected $seen = array();
	protected $forms = array();
	protected $queries = array();
	protected $languages = array();
	protected $default_language = '';
	protected $raw = array();     // path|lang => response body

	function __construct($root, $target, $url = '/') {
		$this->root = $root;
		$this->target = rtrim($target, '/');
		$this->url = $url === '' ? '/' : $url;
		if (substr($this->url, -1) !== '/') $this->url .= '/';
		// pages that only work with PHP behind them: accounts and newsletter answers
		$this->skip = array('#^/(api|mcp|logout)(/|$)#');
		foreach (array(config::get('login_page', 'login'), 'register', 'forgot', config::get('reset_page', 'reset'), 'account', config::get('newsletter_confirm_page', 'newsletter-confirm'), config::get('newsletter_unsubscribe_page', 'newsletter-unsubscribe')) as $page) {
			$this->skip[] = '#^/'.preg_quote(trim($page, '/'), '#').'$#';
		}
		$this->skip = array_merge($this->skip, (array)config::get('export_skip', array()));
	}

	// ##Running
	function run() {
		$this->start_server();
		try {
			$this->languages = class_exists('i18n') ? i18n::available() : array();
			$this->default_language = $this->languages ? $this->languages[0] : '';
			foreach ($this->seeds() as $path) $this->enqueue($path);
			while ($this->queue) {
				$path = array_shift($this->queue);
				$this->visit($path, $this->default_language);
				if (count($this->seen) > 5000) { $this->warnings[] = 'Stopped after 5000 pages'; break; }
			}
			foreach (array_slice($this->languages, 1) as $language) {
				foreach (array_keys($this->pages) as $path) {
					if ($this->is_format($path)) continue;
					$this->visit($path, $language);
				}
			}
			$this->not_found();
			$this->write_pages();
			$this->copy_assets();
		} finally {
			$this->stop_server();
		}
		$by_form = array();
		foreach ($this->forms as $path => $owners) foreach (array_unique($owners) as $owner) $by_form[$owner][] = $path;
		foreach ($by_form as $owner => $paths) {
			$where = count($paths) > 3 ? count($paths).' pages' : implode(', ', $paths);
			$this->warnings[] = "The $owner form ($where) needs the PHP site; a static host can't answer it";
		}
		$missing = array_diff(array_keys($this->linked), array_keys($this->pages), array('/404'));
		if ($missing) $this->warnings[] = 'Pages link to what the export leaves out: '.implode(', ', array_slice($missing, 0, 8)).(count($missing) > 8 ? ', …' : '');
		if ($this->queries) {
			$this->warnings[] = 'Links with a query string point to the same static page: '.implode(', ', array_slice(array_unique($this->queries), 0, 8)).(count(array_unique($this->queries)) > 8 ? ', …' : '');
		}
		file_put_contents($this->target.'/.raster-export.json', json_encode(array(
			'exported_at' => date(DATE_ATOM), 'url' => $this->url, 'pages' => count($this->pages), 'files' => $this->files,
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
		return $this;
	}

	// every page the templates define, feed views and literal routes; the
	// rest (items, pages of lists, filters) is found by following links
	protected function seeds() {
		$inspector = new raster_inspector();
		$paths = array('/');
		foreach ($inspector->views() as $view) {
			if (preg_match('#(^|/)_#', $view)) continue;
			if (preg_match('/\.(rss|atom|xml|json|txt)$/', $view)) { $paths[] = '/'.$view; continue; }
			$url = $inspector->url_for_view($view);
			if (strpos($url, '{') === false) $paths[] = $url;
		}
		foreach ($inspector->routes() as $route) {
			if (preg_match('#^[a-z0-9_\-/]+$#', $route['pattern'])) $paths[] = '/'.trim($route['pattern'], '/');
		}
		return array_values(array_unique($paths));
	}

	protected function enqueue($path) {
		$path = $this->normalize($path);
		if ($path === null || isset($this->seen[$path])) return;
		foreach ($this->skip as $pattern) {
			// a regular expression (#...#) or the start of a path
			$regex = preg_match('/^[#\/].*[#\/][a-z]*$/', $pattern) && @preg_match($pattern, '') !== false && $pattern[0] === '#';
			if ($regex ? preg_match($pattern, $path) : strpos($path, '/'.ltrim($pattern, '/')) === 0) return;
		}
		$this->seen[$path] = true;
		$this->queue[] = $path;
	}

	protected function normalize($path) {
		$path = '/'.ltrim(preg_replace('#[?\#].*$#s', '', (string)$path), '/');
		$path = preg_replace('#/+#', '/', rawurldecode($path));
		if ($path !== '/') $path = rtrim($path, '/');
		if (strpos($path, '..') !== false) return null;
		return $path;
	}

	protected function is_format($path) {
		return (bool)preg_match('/\.(rss|atom|xml|json|txt)$/', $path);
	}

	protected function asset_path($path) {
		$app = '/'.boot::$appname.'/';
		$media = '/'.trim(config::get('raster_media_folder', 'media'), '/').'/';
		return strpos($path, $app) === 0 || strpos($path, $media) === 0 || preg_match('/\.(css|js|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|pdf|mp4|webm|mp3)$/i', $path);
	}

	protected function visit($path, $language) {
		$headers = array();
		if ($language !== '' && $language !== $this->default_language) {
			$headers[] = 'Cookie: '.i18n::cookie_name().'='.$language;
		}
		list($status, $body, $type) = $this->fetch($path, $headers);
		if ($status !== 200) {
			if ($language !== $this->default_language) return;
			if ($status >= 300 && $status < 400) $this->skipped[] = $path;
			elseif ($status !== 404) $this->warnings[] = "$path answered $status";
			return;
		}
		$this->pages[$path][$language] = true;
		$this->raw[$path.'|'.$language] = $body;
		if ($language !== $this->default_language || strpos($type, 'html') === false) return;
		// links to follow, forms to report
		preg_match_all('/\s(?:href|src)=(["\'])(.*?)\1/i', $body, $links);
		foreach ($links[2] as $link) {
			$link = html_entity_decode($link, ENT_QUOTES);
			if (strpos($link, $this->base) === 0) $link = '/'.substr($link, strlen($this->base));
			elseif (!preg_match('#^/[^/]#', $link)) continue;
			$bare = $this->normalize($link);
			if ($bare === null || $this->asset_path($bare)) continue;
			if (strpos($link, '?') !== false && !preg_match('/\?lang=[a-z\-]+$/i', $link)) $this->queries[] = $link;
			$this->linked[$bare] = true;
			$this->enqueue($bare);
		}
		if (preg_match_all('/<form\b[^>]*method=["\']?post[^>]*>.*?name="raster_form" value="([^"]+)"/is', $body, $forms)) {
			$this->forms[$path] = $forms[1];
		}
	}

	// the host's 404 page, from a URL that can't exist
	protected function not_found() {
		list($status, $body, $type) = $this->fetch('/raster-export-'.bin2hex(random_bytes(4)));
		if ($status === 404 && strpos($type, 'html') !== false) $this->raw['/404|'.$this->default_language] = $body;
	}

	// ##Writing
	protected function file_for($path, $language) {
		$prefix = ($language !== '' && $language !== $this->default_language) ? '/'.$language : '';
		if ($path === '/404') return $this->target.$prefix.'/404.html';
		if ($this->is_format($path)) return $this->target.$prefix.$path;
		return $this->target.$prefix.($path === '/' ? '' : $path).'/index.html';
	}

	protected function write_pages() {
		$page_paths = array_keys($this->pages);
		foreach ($this->raw as $key => $body) {
			list($path, $language) = explode('|', $key, 2);
			$body = $this->rewrite($body, $language, $page_paths);
			$file = $this->file_for($path, $language);
			if (!is_dir(dirname($file))) mkdir(dirname($file), 0775, true);
			file_put_contents($file, $body);
			$this->files++;
		}
	}

	// local links become the site's address, in the page's language
	protected function rewrite($body, $language, $page_paths) {
		$base = $this->base;
		$default = $this->default_language;
		// pages in another language link to that language's pages
		if ($language !== '' && $language !== $default) {
			$lookup = array_flip($page_paths);
			$self = $this;
			$body = preg_replace_callback('#(href=["\'])'.preg_quote($base, '#').'([^"\'\#?]*+)(?!\?lang=)#', function ($m) use ($base, $language, $lookup, $self) {
				$path = '/'.trim($m[2], '/');
				if (!isset($lookup[$path]) || $self->is_format_path($path)) return $m[0];
				return $m[1].$base.$language.'/'.$m[2];
			}, $body);
		}
		// the language switcher: /menu?lang=ro -> /ro/menu
		if ($this->languages) {
			$body = preg_replace_callback('#'.preg_quote($base, '#').'([^"\'\s?]*)\?lang=([a-zA-Z\-]+)#', function ($m) use ($base, $default) {
				return $base.(strtolower($m[2]) === strtolower($default) ? '' : $m[2].'/').$m[1];
			}, $body);
		}
		// root-relative addresses (uploads, say) belong to the site's address too
		$url = $this->url;
		$body = preg_replace_callback('#(\s(?:href|src)=["\'])/(?!/)#i', function ($m) use ($url) { return $m[1].$url; }, $body);
		$escaped = str_replace('/', '\\/', $base);
		return str_replace(array($base, $escaped, rtrim($base, '/')), array($this->url, str_replace('/', '\\/', $this->url), rtrim($this->url, '/')), $body);
	}

	function is_format_path($path) { return $this->is_format($path); }

	// theme files (not views) and uploads, where the pages expect them
	protected function copy_assets() {
		$views = APPBASE.config::get('views_path', 'views');
		$folders = array();
		foreach (glob($views.'/*', GLOB_ONLYDIR) ?: array() as $theme) $folders[] = $theme;
		$media = dirname(BASE).'/'.trim(config::get('raster_media_folder', 'media'), '/');
		if (is_dir($media)) $folders[] = $media;
		foreach ($folders as $folder) {
			$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
			foreach ($iterator as $file) {
				if (!$file->isFile()) continue;
				$name = $file->getFilename();
				if ($name[0] === '.' || preg_match('/\.(html|rss|atom|xml|json|txt|php)$/i', $name)) continue;
				$relative = substr($file->getPathname(), strlen(dirname(BASE)) + 1);
				$to = $this->target.'/'.$relative;
				if (!is_dir(dirname($to))) mkdir(dirname($to), 0775, true);
				copy($file->getPathname(), $to);
				$this->files++;
			}
		}
	}

	// ##The local server
	protected function start_server() {
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$name = stream_socket_get_name($socket, false);
		fclose($socket);
		$port = (int)substr($name, strrpos($name, ':') + 1);
		$this->base = "http://127.0.0.1:$port/";
		$env = array_merge(getenv(), array('RASTER_URL' => $this->base, 'RASTER_APP' => boot::$appname));
		$this->server = proc_open(array(PHP_BINARY, '-S', "127.0.0.1:$port", $this->root.'/index.php'), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $this->root, $env);
		for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $port); $i++) usleep(50000);
	}

	protected function stop_server() {
		if ($this->server) {
			proc_terminate($this->server);
			proc_close($this->server);
		}
	}

	protected function fetch($path, $headers = array()) {
		$context = stream_context_create(array('http' => array(
			'method' => 'GET', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30,
			'header' => implode("\r\n", $headers),
		)));
		$body = @file_get_contents(rtrim($this->base, '/').str_replace('%2F', '/', rawurlencode($path)), false, $context);
		$status = 0; $type = '';
		foreach (isset($http_response_header) ? $http_response_header : array() as $line) {
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int)$m[1];
			if (stripos($line, 'Content-Type:') === 0) $type = strtolower(trim(substr($line, 13)));
		}
		return array($status, (string)$body, $type);
	}
}
