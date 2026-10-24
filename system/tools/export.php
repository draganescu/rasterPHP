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
// the site's address, RASTER_URL or site_url, or root-relative links when
// none is set). With several languages, each other language goes in
// /<lang>/, and the language switcher links there. A multilingual site
// (i18n::enabled()) is crawled from each language's own address (/, /ro/),
// and its pages already link in their language; a site with languages but
// multilingual off fetches each page again in every other language.
// A static host can't answer forms, so the export stops when a page still
// has one: templates wrap forms in <!-- print.if.live --> and say what shows
// instead in <!-- print.if.static -->. It also stops when a page answers 5xx
// or nothing, rather than publish the site without it. Accounts are left
// out, and links with a query string are listed at the end.
//
// Exporting again to the same folder changes only what changed: when nothing
// the site is made of changed (content, templates, models, assets, media),
// nothing runs; otherwise every page is rendered again, but only files whose
// bytes differ are written, and files the site no longer has are removed.
// The list lives in the folder's .raster-export.json.
class raster_export {

	public $root;
	public $target;
	public $url;
	public $skip = array();
	public $pages = array();      // path => array(language => file written)
	public $warnings = array();
	public $errors = array();
	public $written = array();    // files new or changed
	public $removed = array();    // files the site no longer has
	public $unchanged = 0;
	public $current = false;      // true when nothing changed since the last export
	public $clean = false;
	public $day;                  // the day the pages are made on, read once per run
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
	protected $multilingual = false;
	protected $prefixes = array(); // language => its address under the site's (multilingual only)
	protected $domains = array();  // language => its own domain_language host (multilingual only)
	protected $raw = array();     // path|lang => response body
	protected $output = array();  // relative file => array('body' => …) or array('from' => file)

	function __construct($root, $target, $url = '/') {
		$this->root = $root;
		$this->target = rtrim($target, '/');
		$this->url = $url === '' ? '/' : $url;
		if (substr($this->url, -1) !== '/') $this->url .= '/';
		// pages that only work with PHP behind them, as the models in use
		// name them: accounts and newsletter answers
		$this->skip = array('#^/(api|mcp|logout)(/|$)#');
		foreach (controller::required('pages') as $page) {
			$this->skip[] = '#^/'.preg_quote(trim($page, '/'), '#').'$#';
		}
		$this->skip = array_merge($this->skip, (array)config::get('export_skip', array()));
	}

	// ##Running
	function run() {
		$manifest = $this->manifest();
		// an export that crosses midnight made its pages on this day
		$this->day = date('Y-m-d');
		raster_cache::publish_due();
		if (!$this->clean && $manifest && isset($manifest['fingerprint']) && $manifest['fingerprint'] === $this->fingerprint()) {
			$this->current = true;
			return $this;
		}
		$this->start_server();
		try {
			$this->languages = class_exists('i18n') ? i18n::available() : array();
			$this->default_language = class_exists('i18n') ? i18n::default_language() : '';
			$this->multilingual = class_exists('i18n') && i18n::enabled();
			if ($this->multilingual) {
				// each language from its own address, /ro/ for ro: the local
				// server's address is no language's domain, so every language
				// but the default has its prefix there
				foreach ($this->languages as $language) {
					$prefix = i18n::prefix($language);
					$this->prefixes[$language] = ($prefix === '' && !i18n::is_default($language)) ? strtolower($language).'/' : $prefix;
				}
				// a language with its own domain answers only there: its pages
				// are asked for on that host, and still written in ro/
				foreach ((array)config::get('domain_language', array()) as $domain => $language) {
					foreach ($this->languages as $code) {
						if (strtolower($code) === strtolower($language) && !i18n::is_default($code) && !isset($this->domains[$code])) $this->domains[$code] = strtolower($domain);
					}
				}
				foreach ($this->prefixes as $prefix) foreach ($this->seeds() as $path) $this->enqueue('/'.$prefix.ltrim($path, '/'));
			} else {
				foreach ($this->seeds() as $path) $this->enqueue($path);
			}
			while ($this->queue) {
				$path = array_shift($this->queue);
				$this->visit($path, $this->language_of($path));
				if (count($this->seen) > 5000) { $this->warnings[] = 'Stopped after 5000 pages'; break; }
			}
			// languages without multilingual: the same pages, in a cookie's language
			if (!$this->multilingual) foreach (array_slice($this->languages, 1) as $language) {
				foreach (array_keys($this->pages) as $path) {
					if ($this->is_format($path)) continue;
					$this->visit($path, $language);
				}
			}
			$this->not_found();
		} finally {
			$this->stop_server();
		}
		// forms need PHP, and pages that answer an error have nothing to
		// write: nothing is written, or removed, while either is left
		$by_form = array();
		foreach ($this->forms as $path => $owners) foreach (array_unique($owners) as $owner) $by_form[$owner][] = $path;
		foreach ($by_form as $owner => $paths) {
			$where = count($paths) > 3 ? count($paths).' pages' : implode(', ', $paths);
			$this->errors[] = "The $owner form ($where) needs PHP. Wrap it in <!-- print.if.live --> and put what the static site shows instead in <!-- print.if.static -->";
		}
		if ($this->errors) return $this;
		$this->collect_pages();
		$this->collect_assets();
		$this->sync($manifest);
		$missing = array_diff(array_keys($this->linked), array_keys($this->pages), array('/404'));
		if ($missing) $this->warnings[] = 'Pages link to what the export leaves out: '.implode(', ', array_slice($missing, 0, 8)).(count($missing) > 8 ? ', …' : '');
		if ($this->queries) {
			$this->warnings[] = 'Links with a query string point to the same static page: '.implode(', ', array_slice(array_unique($this->queries), 0, 8)).(count(array_unique($this->queries)) > 8 ? ', …' : '');
		}
		if ($this->multilingual) {
			// a language on its own domain is linked at that domain, not in this folder
			$own = array();
			foreach ($this->domains as $language => $domain) $own[] = "$language ($domain)";
			if ($own) $this->warnings[] = 'Languages with their own domain: '.implode(', ', $own).'. Their pages are in their folder (ro/), and links and hreflang point at the domain: publish that folder there, or take them out of domain_language';
			if ($this->url[0] === '/') $this->warnings[] = 'No --url, RASTER_URL or site_url: the canonical and hreflang links are root-relative, and search engines want them absolute. Export with --url=https://example.com/';
		}
		return $this;
	}

	// ##What changed

	protected function manifest() {
		$file = $this->target.'/.raster-export.json';
		$manifest = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
		return is_array($manifest) ? $manifest : null;
	}

	// everything the pages are made of: the app's files (templates, models,
	// config, translations, theme), uploads, the database, content changes
	// (util::content_changed), the framework's version, these options and
	// the day in the site's time zone: `date>=today` and anything printed
	// from the clock change with it, so a new day always exports again
	function fingerprint() {
		$parts = array($this->url, json_encode($this->skip), raster_cache::version(), @file_get_contents(BASE.'VERSION'), $this->day ?: date('Y-m-d'));
		$folders = array(rtrim(APPBASE, '/'), dirname(BASE).'/'.trim(config::get('raster_media_folder', 'media'), '/'));
		foreach ($folders as $folder) {
			if (!is_dir($folder)) continue;
			$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
			foreach ($iterator as $file) {
				$path = $file->getPathname();
				if (strpos($path, rtrim(APPBASE, '/').'/data/') === 0) continue;
				$parts[] = $path.'|'.$file->getMTime().'|'.$file->getSize();
			}
		}
		$databases = glob(APPBASE.'data/*.sqlite*') ?: array();
		if (getenv('RASTER_DB')) $databases = array_merge($databases, glob(getenv('RASTER_DB').'*') ?: array());
		foreach (array_unique($databases) as $file) {
			// the -shm index changes when the database is only read (WAL)
			if (substr($file, -4) === '-shm') continue;
			clearstatcache(true, $file);
			$parts[] = $file.'|'.filemtime($file).'|'.filesize($file);
		}
		sort($parts);
		return sha1(implode("\n", $parts));
	}

	// every page the templates define, feed views and literal routes; the
	// rest (items, pages of lists, filters) is found by following links
	protected function seeds() {
		$inspector = new raster_inspector();
		$paths = array('/');
		foreach ($inspector->views() as $view) {
			if (controller::is_partial($view)) continue;
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
		// /ro/login is left out like /login
		$paths = array_unique(array($path, $this->unprefixed($path)));
		foreach ($this->skip as $pattern) {
			// a regular expression (#...#) or the start of a path
			$regex = preg_match('/^[#\/].*[#\/][a-z]*$/', $pattern) && @preg_match($pattern, '') !== false && $pattern[0] === '#';
			foreach ($paths as $one) if ($regex ? preg_match($pattern, $one) : strpos($one, '/'.ltrim($pattern, '/')) === 0) return;
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

	// the language a crawled path is in: its prefix's (multilingual), else the default
	protected function language_of($path) {
		foreach ($this->prefixes as $language => $prefix) {
			if ($prefix !== '' && ($path === '/'.rtrim($prefix, '/') || strpos($path, '/'.$prefix) === 0)) return $language;
		}
		return $this->default_language;
	}

	// a link to a language's own domain as a crawled path: http://ro.example/menu
	// is /ro/menu; null for any other link
	protected function on_domain($link) {
		foreach ($this->domains as $language => $domain) {
			if (preg_match('#^https?://'.preg_quote($domain, '#').'(:\d+)?(/.*)?$#i', $link, $m)) return '/'.$this->prefixes[$language].ltrim(isset($m[2]) ? $m[2] : '', '/');
		}
		return null;
	}

	// the path without its language prefix: /ro/menu is /menu
	protected function unprefixed($path) {
		$prefix = isset($this->prefixes[$this->language_of($path)]) ? $this->prefixes[$this->language_of($path)] : '';
		return $prefix === '' ? $path : '/'.substr($path, strlen($prefix) + 1);
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
		// a page fetched again in another language (multilingual off)
		$again = !$this->multilingual && $language !== $this->default_language;
		if ($again && $language !== '') {
			$headers[] = 'Cookie: '.i18n::cookie_name().'='.$language;
		}
		// a language's home page is /ro/ (D5)
		$address = ($this->multilingual && $path !== '/' && $path === '/'.rtrim($this->prefixes[$language], '/')) ? $path.'/' : $path;
		// on its own domain, without the prefix
		if (isset($this->domains[$language])) {
			$headers[] = 'Host: '.$this->domains[$language];
			$address = $this->unprefixed($path);
		}
		list($status, $body, $type) = $this->fetch($address, $headers);
		if ($status !== 200) {
			// a page that breaks or doesn't answer is an error in any language:
			// leaving it out would remove its file from the last export
			if ($status === 0 || $status >= 500) {
				$this->errors[] = $path.($again ? " ($language)" : '').($status ? " answered $status" : ' did not answer');
				return;
			}
			if ($again) return;
			if ($status >= 300 && $status < 400) $this->skipped[] = $path;
			elseif ($status !== 404) $this->warnings[] = "$path answered $status";
			return;
		}
		$this->pages[$path][$language] = true;
		$this->raw[$path.'|'.$language] = $body;
		if ($again || strpos($type, 'html') === false) return;
		// links to follow, forms to report
		preg_match_all('/\s(?:href|src)=(["\'])(.*?)\1/i', $body, $links);
		foreach ($links[2] as $link) {
			$link = html_entity_decode($link, ENT_QUOTES);
			if (strpos($link, $this->base) === 0) $link = '/'.substr($link, strlen($this->base));
			elseif (($on = $this->on_domain($link)) !== null) $link = $on;
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

	// the host's 404 page, from a URL that can't exist. One that breaks is
	// an error like any page: leaving it out would remove 404.html
	protected function not_found() {
		list($status, $body, $type) = $this->fetch('/raster-export-'.bin2hex(random_bytes(4)));
		if ($status === 0 || $status >= 500) {
			$this->errors[] = 'The 404 page'.($status ? " answered $status" : ' did not answer');
			return;
		}
		if ($status === 404 && strpos($type, 'html') !== false) $this->raw['/404|'.$this->default_language] = $body;
		// a multilingual site has one in each language: ro/404.html
		foreach ($this->prefixes as $language => $prefix) {
			if ($prefix === '') continue;
			$missing = 'raster-export-'.bin2hex(random_bytes(4));
			list($status, $body, $type) = isset($this->domains[$language]) ? $this->fetch('/'.$missing, array('Host: '.$this->domains[$language])) : $this->fetch('/'.$prefix.$missing);
			if ($status === 0 || $status >= 500) {
				$this->errors[] = "The 404 page ($language)".($status ? " answered $status" : ' did not answer');
				continue;
			}
			if ($status === 404 && strpos($type, 'html') !== false) $this->raw['/404|'.$language] = $body;
		}
	}

	// ##Writing
	protected function file_for($path, $language) {
		$prefix = ($language !== '' && $language !== $this->default_language) ? '/'.$language : '';
		// a multilingual site's paths carry their prefix already
		if ($this->multilingual) $prefix = ($path === '/404' && $prefix !== '') ? '/'.rtrim($this->prefixes[$language], '/') : '';
		if ($path === '/404') return $this->target.$prefix.'/404.html';
		if ($this->is_format($path)) return $this->target.$prefix.$path;
		return $this->target.$prefix.($path === '/' ? '' : $path).'/index.html';
	}

	protected function collect_pages() {
		$page_paths = array_keys($this->pages);
		foreach ($this->raw as $key => $body) {
			list($path, $language) = explode('|', $key, 2);
			$file = substr($this->file_for($path, $language), strlen($this->target) + 1);
			$this->output[$file] = array('body' => $this->rewrite($body, $language, $page_paths));
		}
	}

	// local links become the site's address, in the page's language. A
	// multilingual site's pages link in their language already
	protected function rewrite($body, $language, $page_paths) {
		$base = $this->base;
		$default = $this->default_language;
		// pages in another language link to that language's pages
		if (!$this->multilingual && $language !== '' && $language !== $default) {
			$lookup = array_flip($page_paths);
			$self = $this;
			$body = preg_replace_callback('#(href=["\'])'.preg_quote($base, '#').'([^"\'\#?]*+)(?!\?lang=)#', function ($m) use ($base, $language, $lookup, $self) {
				$path = '/'.trim($m[2], '/');
				if (!isset($lookup[$path]) || $self->is_format_path($path)) return $m[0];
				return $m[1].$base.$language.'/'.$m[2];
			}, $body);
		}
		// the language switcher: /menu?lang=ro -> /ro/menu
		if ($this->languages && !$this->multilingual) {
			$body = preg_replace_callback('#'.preg_quote($base, '#').'([^"\'\s?]*)\?lang=([a-zA-Z\-]+)#', function ($m) use ($base, $default) {
				return $base.(strtolower($m[2]) === strtolower($default) ? '' : $m[2].'/').$m[1];
			}, $body);
		}
		// root-relative addresses (uploads, say) belong to the site's address too
		$url = $this->url;
		$body = preg_replace_callback('#(\s(?:href|src)=["\'])/(?!/)#i', function ($m) use ($url) { return $m[1].$url; }, $body);
		// a language's own domain, as the site's address says it: https when it is
		$scheme = preg_match('#^(https?)://#i', $this->url, $m) ? strtolower($m[1]) : 'http';
		foreach ($this->domains as $domain) $body = preg_replace('#https?://'.preg_quote($domain, '#').'(:\d+)?/#i', $scheme.'://'.$domain.'/', $body);
		$escaped = str_replace('/', '\\/', $base);
		return str_replace(array($base, $escaped, rtrim($base, '/')), array($this->url, str_replace('/', '\\/', $this->url), rtrim($this->url, '/')), $body);
	}

	function is_format_path($path) { return $this->is_format($path); }

	// theme files (not views) and uploads, where the pages expect them
	protected function collect_assets() {
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
				$this->output[substr($file->getPathname(), strlen(dirname(BASE)) + 1)] = array('from' => $file->getPathname());
			}
		}
	}

	// write what's new or different, remove what the site no longer has
	protected function sync($manifest) {
		$before = ($manifest && isset($manifest['files']) && is_array($manifest['files'])) ? $manifest['files'] : array();
		$files = array();
		ksort($this->output);
		foreach ($this->output as $relative => $source) {
			$hash = isset($source['body']) ? sha1($source['body']) : sha1_file($source['from']);
			$files[$relative] = $hash;
			$to = $this->target.'/'.$relative;
			if (isset($before[$relative]) && $before[$relative] === $hash && is_file($to)) { $this->unchanged++; continue; }
			if (!is_dir(dirname($to))) mkdir(dirname($to), 0775, true);
			if (isset($source['body'])) file_put_contents($to, $source['body']);
			else copy($source['from'], $to);
			$this->written[] = $relative;
		}
		// only files an earlier export wrote are ever removed
		foreach (array_diff_key($before, $files) as $relative => $hash) {
			if (strpos($relative, '..') !== false) continue;
			$file = $this->target.'/'.$relative;
			if (is_file($file)) unlink($file);
			$this->removed[] = $relative;
			for ($dir = dirname($file); $dir !== $this->target && strpos($dir, $this->target.'/') === 0 && @rmdir($dir); $dir = dirname($dir));
		}
		file_put_contents($this->target.'/.raster-export.json', json_encode(array(
			'exported_at' => date(DATE_ATOM), 'url' => $this->url, 'pages' => count($this->pages),
			// after the run: rendering may touch the database (a fluid schema)
			'fingerprint' => $this->fingerprint(), 'files' => $files,
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
	}

	// ##The local server
	protected function start_server() {
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$name = stream_socket_get_name($socket, false);
		fclose($socket);
		$port = (int)substr($name, strrpos($name, ':') + 1);
		$this->base = "http://127.0.0.1:$port/";
		$env = array_merge(getenv(), array('RASTER_URL' => $this->base, 'RASTER_APP' => boot::$appname, 'RASTER_EXPORT' => '1'));
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
		// the headers come from the stream: PHP 8.5 deprecates $http_response_header
		$stream = @fopen(rtrim($this->base, '/').str_replace('%2F', '/', rawurlencode($path)), 'r', false, $context);
		$body = $stream ? stream_get_contents($stream) : '';
		$meta = $stream ? stream_get_meta_data($stream) : array();
		if ($stream) fclose($stream);
		$status = 0; $type = '';
		foreach (isset($meta['wrapper_data']) ? $meta['wrapper_data'] : array() as $line) {
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int)$m[1];
			if (stripos($line, 'Content-Type:') === 0) $type = strtolower(trim(substr($line, 13)));
		}
		return array($status, (string)$body, $type);
	}
}
