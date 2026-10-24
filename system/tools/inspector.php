<?php
// #Template inspector
//
// Reads views without rendering them. It is the single place that knows the
// annotation grammar, and it is used by:
// - `raster lint`, to report problems with file:line:column positions
// - `raster schema`, to derive the CMS content model from the markup
// - the MCP endpoint, to describe the site to agents
//
// It never executes models, so it is safe to run anywhere.
class raster_inspector {

	// ##The grammar
	// system/tools/annotations.php is the one description of what the engine
	// accepts, and `raster annotations` prints it. Everything below reads from
	// it, so the lint rules and what agents are told can't drift apart.
	static $grammar = null;

	static function grammar() {
		if (self::$grammar === null) self::$grammar = include __DIR__.'/annotations.php';
		return self::$grammar;
	}

	// the directives the template engine understands
	static function keywords() {
		return array_keys(self::grammar()['keywords']);
	}

	// keywords that may end in /--> instead of wrapping content
	static function self_closing_keywords() {
		$keywords = array();
		foreach (self::grammar()['keywords'] as $keyword => $rules) {
			if (!empty($rules['self_closing'])) $keywords[] = $keyword;
		}
		return $keywords;
	}

	// keywords that take a .reference (remove does not)
	static function named_keywords() {
		$keywords = array();
		foreach (self::grammar()['keywords'] as $keyword => $rules) {
			if (!empty($rules['name'])) $keywords[] = $keyword;
		}
		return $keywords;
	}

	// models the template engine handles itself
	static function builtin_models() {
		return self::grammar()['references']['builtin_models'];
	}

	// print keys inside render blocks that Raster fills in by itself
	static function builtin_keys() {
		return self::grammar()['references']['builtin_keys'];
	}

	// the character class of a kind of name (model, key, field, attribute,
	// view, res), the one the engine's patterns are built from
	static function name($kind) {
		return self::grammar()['names'][$kind];
	}

	public $theme;
	public $views_dir;
	public $ext;
	// the content model of each theme, for collection_fields()
	protected $models = array();

	function __construct($views_dir = null, $theme = null, $ext = null) {
		$this->views_dir = rtrim($views_dir ?: APPBASE.config::get('views_path', 'views'), '/');
		$this->theme = $theme ?: config::get('theme', 'default');
		$this->ext = $ext ?: config::get('views_ext', '.html');
	}

	function theme_dir($theme = null) {
		return $this->views_dir.'/'.($theme ?: $this->theme);
	}

	// every view in a theme, as paths relative to the theme folder
	function views($theme = null) {
		$dir = $this->theme_dir($theme);
		$views = array();
		if (!is_dir($dir)) return $views;
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			// html views, plus feeds and data views (news.rss, sitemap.xml, api.json)
			if (substr($file->getFilename(), -strlen($this->ext)) !== $this->ext && !preg_match('/\.(rss|atom|xml|json|txt)$/', $file->getFilename())) continue;
			$views[] = ltrim(substr($file->getPathname(), strlen($dir)), '/');
		}
		sort($views);
		return $views;
	}

	// ##Tokens
	// Finds every HTML comment and classifies it. Comments that are not
	// directives are ignored unless they look like a misspelled directive.
	// The script form the engine reads in scripts and styles,
	// "/*- print.x.y /-*/" (template::$script_form), counts as a comment too.
	static function tokenize($html) {
		$tokens = array();
		$opens = array('<!--');
		$closes = array('-->');
		foreach (template::$script_form as $mark => $means) {
			if ($means === '<!--') $opens[] = $mark;
			if ($means === '-->') $closes[] = $mark;
		}
		// a body holds no opener, so a "/*-" that ends with a plain "*/" (a CSS
		// or JS comment) cannot run on to a later "-->" and swallow the
		// annotation there: a comment starts at the last opener before its
		// closer. Found by walking the markers, not with one regex over the
		// bodies, which runs out of PCRE stack on a long comment.
		$is_open = array_flip($opens);
		$markers = array();
		foreach (array_merge($opens, $closes) as $marker) $markers[] = preg_quote($marker, '/');
		if (preg_match_all('/'.implode('|', $markers).'/', $html, $marks, PREG_OFFSET_CAPTURE) === false) {
			throw new Exception('Could not read the comments in this view: '.preg_last_error_msg());
		}
		$matches = array(array(), array());
		$start = null;
		foreach ($marks[0] as $mark) {
			if (isset($is_open[$mark[0]])) {
				$start = $mark;
			} elseif ($start !== null) {
				$body_at = $start[1] + strlen($start[0]);
				$end = $mark[1] + strlen($mark[0]);
				$matches[0][] = array(substr($html, $start[1], $end - $start[1]), $start[1]);
				$matches[1][] = array(substr($html, $body_at, $mark[1] - $body_at), $body_at);
				$start = null;
			}
		}
		foreach ($matches[0] as $i => $match) {
			$raw = $match[0];
			$offset = $match[1];
			$body = $matches[1][$i][0];
			$token = array(
				'raw' => $raw,
				'offset' => $offset,
				'line' => substr_count($html, "\n", 0, $offset) + 1,
			);
			if ($offset > 0) {
				$nl = strrpos(substr($html, 0, $offset), "\n");
				$token['column'] = $nl === false ? $offset + 1 : $offset - $nl;
			} else {
				$token['column'] = 1;
			}

			$trimmed = trim($body);
			if (!preg_match('/^(\/)?([a-z]+)(?:\.([^\s(]+?(?:\(.*\))?))?\s*(\/)?$/s', $trimmed, $m)) continue;
			$keyword = $m[2];
			if (!in_array($keyword, self::keywords())) {
				// a comment like <!-- prnit.cms.title --> is probably a typo
				if (isset($m[3]) && $m[3] !== '') {
					foreach (self::keywords() as $known) {
						if (levenshtein($keyword, $known) <= 2) {
							$token['type'] = 'typo';
							$token['keyword'] = $keyword;
							$token['suggestion'] = $known;
							$tokens[] = $token;
							break;
						}
					}
				}
				continue;
			}
			$token['type'] = $m[1] === '/' ? 'close' : ((isset($m[4]) && $m[4] === '/') ? 'self' : 'open');
			$token['keyword'] = $keyword;
			$token['ref'] = isset($m[3]) ? $m[3] : '';
			$token['name'] = $keyword.($token['ref'] !== '' ? '.'.$token['ref'] : '');
			// the engine matches exact strings, spacing included
			$canonical = $token['type'] === 'close' ? '<!-- /'.$token['name'].' -->'
				: ($token['type'] === 'self' ? '<!-- '.$token['name'].' /-->' : '<!-- '.$token['name'].' -->');
			$token['script'] = template::unscript($raw) !== $raw;
			$token['canonical'] = self::spelled($token, $canonical);
			$token['exact'] = ($raw === $token['canonical']);
			$tokens[] = $token;
		}
		return $tokens;
	}

	// ##Block tree
	// Pairs openings with closings. Returns the top level blocks and the
	// structural problems found on the way.
	static function blocks($html) {
		$tokens = self::tokenize($html);
		$problems = array();
		$root = array('children' => array());
		$stack = array(&$root);
		$open = array();

		foreach ($tokens as $token) {
			if ($token['type'] === 'typo') {
				// only a guess: <!-- div.card --> is as likely an ordinary comment,
				// so this is never fixed automatically
				$problems[] = self::problem('warning', $token, "Unknown directive '{$token['keyword']}' in {$token['raw']}; did you mean '{$token['suggestion']}'?");
				continue;
			}
			if (!$token['exact']) {
				$problems[] = self::problem('error', $token, "Directive written as {$token['raw']} is ignored by the template engine; write it exactly as {$token['canonical']}",
					self::edit($token, $token['canonical']));
				continue;
			}
			// an opening needs its name (remove has none); a closing tag without
			// one is handled below, where lint can say which block it meant
			if ($token['type'] !== 'close' && in_array($token['keyword'], self::named_keywords()) && $token['ref'] === '') {
				$problems[] = self::problem('error', $token, "{$token['raw']} needs a name, e.g. <!-- {$token['keyword']}.model.method -->");
				continue;
			}
			if ($token['type'] === 'self') {
				if (!in_array($token['keyword'], self::self_closing_keywords())) {
					$problems[] = self::problem('error', $token, "{$token['keyword']} blocks can't be self-closing: use {$token['canonical']} ... <!-- /{$token['name']} -->");
					continue;
				}
				$block = $token + array('end' => null, 'inner' => '', 'children' => array());
				$top = &$stack[count($stack) - 1];
				$top['children'][] = $block;
				unset($top);
				continue;
			}
			if ($token['type'] === 'open') {
				if ($token['keyword'] === 'remove' && !empty(array_filter($open, function ($t) { return $t['keyword'] === 'remove'; }))) {
					$problems[] = self::problem('error', $token, "remove blocks can't be nested");
				}
				$block = $token + array('end' => null, 'inner' => '', 'children' => array());
				$top = &$stack[count($stack) - 1];
				$top['children'][] = $block;
				$index = count($top['children']) - 1;
				$stack[] = &$top['children'][$index];
				unset($top);
				$open[] = $token;
				continue;
			}
			// closing tag
			if (empty($open)) {
				$problems[] = self::problem('error', $token, "{$token['raw']} closes a block that was never opened");
				continue;
			}
			$last = end($open);
			if ($last['name'] !== $token['name']) {
				// A short closing tag (<!-- /render -->, or the name without its
				// arguments) does not work: the engine matches the full name as an
				// exact string. When it plainly closes the innermost open block, lint
				// names that block and `lint --fix` writes the full name in, so the
				// engine only ever sees the full form.
				if (self::shortens($last, $token)) {
					$full = self::spelled($token, '<!-- /'.$last['name'].' -->');
					$problems[] = self::problem('error', $token, "{$token['raw']} is ignored by the template engine, which needs the full name; write $full", self::edit($token, $full));
				} else {
					$opened_at = null;
					foreach (array_reverse($open) as $candidate) {
						if ($candidate['name'] === $token['name'] || self::shortens($candidate, $token)) { $opened_at = $candidate; break; }
					}
					if ($opened_at === null) {
						$problems[] = self::problem('error', $token, "{$token['raw']} closes a block that was never opened");
						continue;
					}
					$problems[] = self::problem('error', $token, "{$token['raw']} closes before <!-- {$last['name']} --> (line {$last['line']}); blocks must nest, close the inner block first");
					// recover by closing everything up to the matching opening
					while (!empty($open) && end($open)['name'] !== $opened_at['name']) {
						array_pop($open);
						array_pop($stack);
					}
				}
			}
			$current = &$stack[count($stack) - 1];
			$current['end'] = $token;
			$current['inner'] = substr($html, $current['offset'] + strlen($current['raw']), $token['offset'] - $current['offset'] - strlen($current['raw']));
			unset($current);
			array_pop($stack);
			array_pop($open);
		}

		foreach ($open as $token) {
			$problems[] = self::problem('error', $token, "{$token['raw']} is never closed; add <!-- /{$token['name']} --> or make it self-closing ({$token['keyword']}.x /-->)");
		}
		return array($root['children'], $problems);
	}

	// A tag in the form the token was written in: in a script or a style,
	// <!-- print.x /--> is written /*- print.x /-*/
	static function spelled($token, $tag) {
		if (empty($token['script'])) return $tag;
		return str_replace(array_values(template::$script_form), array_keys(template::$script_form), $tag);
	}

	// Whether a closing tag is a shortened form of this opening's: the keyword
	// alone, or the name without its arguments. Such a tag is an error (the
	// engine needs the full name), but lint can tell which block it meant.
	static function shortens($open, $close) {
		if ($open['keyword'] !== $close['keyword'] || $open['ref'] === $close['ref']) return false;
		return $close['ref'] === '' || strpos($open['ref'], $close['ref'].'(') === 0;
	}

	static function problem($severity, $token, $message, $fix = null) {
		$problem = array('severity' => $severity, 'line' => $token['line'], 'column' => $token['column'], 'message' => $message);
		if ($fix) $problem['fix'] = $fix;
		return $problem;
	}

	// ##Fixable problems
	// A problem carries a `fix` when the repair is mechanical: the same
	// directive, written the way the engine reads it. `lint --fix` applies
	// these; everything else needs a decision and is only reported.
	static function edit($token, $replacement) {
		if ($replacement === $token['raw']) return null;
		return array('offset' => $token['offset'], 'length' => strlen($token['raw']), 'replacement' => $replacement, 'was' => $token['raw']);
	}

	// Splits a print/render reference into model and method. Returns false
	// when the reference has no model part.
	static function model_reference($ref) {
		if (!preg_match('/^('.self::name('model').'+)\.(.+)$/', $ref, $m)) return false;
		return array('model' => $m[1], 'method' => $m[2]);
	}

	// Resolves a data key used inside a render block:
	//   print.title            -> key title
	//   print.@href.link       -> key link, sets the href attribute
	//   print.+class.state     -> key state, appends to the class attribute
	static function data_key($ref) {
		if (strpos($ref, 'raster_filter@') === 0) {
			return array('key' => $ref, 'attribute' => null, 'append' => false, 'builtin' => true);
		}
		// a link to the other items sharing field values:
		// print.@href.raster_filter@author (or @author@year for several fields)
		if (preg_match('/^([@+])('.self::name('attribute').'+)\.(raster_filter(@[a-z0-9_]+)+)$/', $ref, $m)) {
			return array('key' => $m[3], 'attribute' => $m[2], 'append' => $m[1] === '+', 'builtin' => true);
		}
		if (preg_match('/^([@+])('.self::name('attribute').'+)\.('.self::name('field').'+)$/', $ref, $m)) {
			return array('key' => $m[3], 'attribute' => $m[2], 'append' => $m[1] === '+', 'builtin' => in_array($m[3], self::builtin_keys()));
		}
		return array('key' => $ref, 'attribute' => null, 'append' => false, 'builtin' => in_array($ref, self::builtin_keys()));
	}

	// ##Models
	// Finds the file that would be loaded for a model (controller::model)
	// and lists its public methods.
	function model_info($model) {
		if (isset(self::$known[$model])) return self::$known[$model];
		$found = controller::model($model);
		$info = false;
		foreach ($found ? array($found['file']) : array() as $file) {
			$info = array('file' => $file, 'methods' => array(), 'signatures' => array(), 'magic' => false, 'class' => false, 'loose' => strpos(file_get_contents($file), 'func_get_args') !== false);
			$tokens = token_get_all(file_get_contents($file));
			$count = count($tokens);
			$class_depth = null; $depth = 0; $pending_class = false;
			for ($i = 0; $i < $count; $i++) {
				$t = $tokens[$i];
				if (is_array($t) && $t[0] === T_CLASS) $pending_class = true;
				if ($pending_class && is_array($t) && $t[0] === T_STRING) {
					if (strtolower($t[1]) === strtolower(basename(dirname($file)))) $info['class'] = true;
					$pending_class = false;
				}
				if ($t === '{' || (is_array($t) && ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES))) {
					$depth++;
					if ($class_depth === null && $info['class'] && !$pending_class) $class_depth = $depth;
				}
				if ($t === '}') $depth--;
				if (is_array($t) && $t[0] === T_FUNCTION && $class_depth !== null && $depth === $class_depth) {
					// skip private/protected methods
					$visibility = 'public';
					for ($j = $i - 1; $j >= 0 && $j > $i - 8; $j--) {
						if (is_array($tokens[$j]) && in_array($tokens[$j][0], array(T_PRIVATE, T_PROTECTED))) { $visibility = 'hidden'; break; }
						if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], array(T_WHITESPACE, T_STATIC, T_PUBLIC, T_FINAL, T_ABSTRACT, T_COMMENT, T_DOC_COMMENT))) break;
					}
					for ($j = $i + 1; $j < $count; $j++) {
						if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
							$name = $tokens[$j][1];
							if ($name === '__call') $info['magic'] = true;
							elseif ($visibility === 'public') {
								$info['methods'][] = $name;
								$info['signatures'][$name] = self::signature($tokens, $j + 1);
							}
							break;
						}
						if ($tokens[$j] === '(') break;
					}
				}
			}
			// an override (the_feed extends feed) has its parent's methods too
			if ($found['parent']) {
				$parent = $found['parent'];
				if (file_exists($parent)) {
					$base_info = $this->parent_info($parent);
					$info['methods'] = array_values(array_unique(array_merge($info['methods'], $base_info['methods'])));
					// the override's own signature wins over the one it replaces
					$info['signatures'] = $info['signatures'] + $base_info['signatures'];
					$info['magic'] = $info['magic'] || $base_info['magic'];
					$info['loose'] = $info['loose'] || !empty($base_info['loose']);
				}
			}
			break;
		}
		return self::$known[$model] = $info;
	}

	// What model_info() and raised_names() read is kept for the process,
	// since lint asks for the same models over and over; the stdio MCP server
	// lives longer than a lint and forgets it before every call.
	static $known = array();
	static $raised = null;

	static function forget() {
		self::$known = array();
		self::$raised = null;
	}

	// methods of a system model file, for overrides
	protected function parent_info($file) {
		$source = file_get_contents($file);
		$info = array('methods' => array(), 'signatures' => array(), 'magic' => false, 'loose' => strpos($source, 'func_get_args') !== false);
		$tokens = token_get_all($source);
		foreach ($tokens as $i => $t) {
			if (!is_array($t) || $t[0] !== T_FUNCTION) continue;
			for ($j = $i + 1; $j < count($tokens); $j++) {
				if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
					if ($tokens[$j][1] === '__call') $info['magic'] = true;
					else {
						$info['methods'][] = $tokens[$j][1];
						$info['signatures'][$tokens[$j][1]] = self::signature($tokens, $j + 1);
					}
					break;
				}
				if ($tokens[$j] === '(') break;
			}
		}
		return $info;
	}

	// ##The vocabulary
	//
	// Everything a template is allowed to name: the models and their methods
	// with signatures, the named SQL queries, the events and who listens, and
	// the names the CMS keeps for itself. Nothing is executed, and for a site
	// the size of the demo café it takes about a millisecond, so it is read
	// fresh every time instead of cached — a stale vocabulary is worse than no
	// vocabulary.
	function vocabulary() {
		$models = array();
		// the_<name> is an override of <name>, called by the original name
		foreach (controller::models() as $name => $found) {
			$info = $this->model_info($name);
			if ($info === false || !$info['class']) continue;
			$methods = array();
			foreach ($info['methods'] as $method) {
				$signature = isset($info['signatures'][$method]) ? $info['signatures'][$method] : null;
				$methods[$method] = $signature
					? array('reads' => self::signature_text($method, $signature), 'needs' => $signature['required'], 'takes' => $signature['variadic'] ? null : $signature['total'])
					: array('reads' => $method.'(…)', 'needs' => 0, 'takes' => null);
			}
			ksort($methods);
			$bundled = strpos($info['file'], BASE) === 0;
			$models[$name] = array(
				'file' => self::short($info['file']),
				'bundled' => $bundled,
				'any_method' => !empty($info['magic']),
				'queries' => $this->model_queries($name),
				'methods' => $methods,
			);
			// what /api/<model>/<method> answers, and for whom, when /api
			// reaches the model at all (api::reachable)
			if (api::reachable($name)) $models[$name]['api'] = $this->model_api($name);
		}
		ksort($models);
		$listeners = array();
		foreach ($this->listeners() as $l) {
			$listeners[$l['event']][] = $l['model'].'.'.$l['method'].' ('.$l['file'].':'.$l['line'].')';
		}
		$reserved = self::grammar()['references'];
		return array(
			'models' => $models,
			'engine_models' => $reserved['builtin_models'],
			'row_keys_from_raster' => $reserved['builtin_keys'],
			'shared_queries' => array_keys(database::named_queries()),
			'events' => array('sent' => $this->dispatched_events(), 'listened_to' => $listeners),
			'reserved' => array(
				'fields' => array_values(array_unique(array_merge($reserved['reserved_fields'], class_exists('cms') ? get_class_methods('cms') : array()))),
				'collections' => $reserved['reserved_collections'],
			),
		);
	}

	// What an application model offers over /api: array(method => role).
	function model_api($model) {
		if (!controller::model($model)) return array();
		try {
			$class = controller::model_class($model);
			if (!$class) return array();
			// an override adds to what the model it overrides lists, as api.php does
			$offered = array_merge(class_exists($model) ? api::offered($model) : array(), api::offered($class));
		} catch (Throwable $e) {
			return array();
		}
		return (object)$offered;
	}

	// the named queries a model can call as methods: the sql/ folders of
	// its own, override and bundled folders (as database::query_file reads them)
	function model_queries($model) {
		$found = controller::model($model);
		$names = array();
		foreach ($found ? $found['dirs'] : array() as $dir) {
			foreach (glob("$dir/sql/*.sql") ?: array() as $file) $names[] = basename($file, '.sql');
		}
		$names = array_values(array_unique($names));
		sort($names);
		return $names;
	}

	// ##Signatures
	//
	// A method's parameter list, read from its tokens: how many arguments it
	// needs, how many it takes, and whether it is variadic. Annotations pass
	// literals only, so the count is known at lint time and can be checked.
	static function signature($tokens, $from) {
		$count = count($tokens);
		$empty = array('required' => 0, 'total' => 0, 'variadic' => false, 'params' => array());
		for ($i = $from; $i < $count && $tokens[$i] !== '('; $i++) {
			if ($tokens[$i] === '{' || $tokens[$i] === ';') return $empty;
		}
		$depth = 0; $params = array(); $current = null; $variadic = false; $rest = false;
		for (; $i < $count; $i++) {
			$t = $tokens[$i];
			if ($t === '(') { $depth++; continue; }
			if ($t === ')') { $depth--; if ($depth === 0) break; continue; }
			// anything nested is part of a default value, e.g. array(1, 2)
			if ($depth !== 1) continue;
			if (is_array($t) && $t[0] === T_ELLIPSIS) { $variadic = true; $rest = true; continue; }
			if (is_array($t) && $t[0] === T_VARIABLE) {
				$params[] = array('name' => ltrim($t[1], '$'), 'optional' => $variadic, 'rest' => !empty($rest));
				$rest = false;
				$current = count($params) - 1;
				continue;
			}
			if ($t === '=' && $current !== null) $params[$current]['optional'] = true;
		}
		$required = 0;
		foreach ($params as $param) if (empty($param['optional'])) $required++;
		return array('required' => $required, 'total' => count($params), 'variadic' => $variadic, 'params' => $params);
	}

	// The nearest real name, when there is an obvious one. Agents and people
	// mistype the same way, and the list of real names is right here.
	static function did_you_mean($name, $names) {
		$best = null; $distance = null;
		foreach ($names as $candidate) {
			$d = levenshtein($name, $candidate);
			if ($d > 0 && $d <= max(2, (int)floor(strlen($name) / 3)) && ($distance === null || $d < $distance)) {
				$best = $candidate; $distance = $d;
			}
		}
		return $best === null ? '' : "; did you mean '$best'?";
	}

	// how a signature reads in a message: method(a, b = …)
	static function signature_text($method, $signature) {
		$parts = array();
		foreach ($signature['params'] as $param) {
			if (!empty($param['rest'])) $parts[] = '…'.$param['name'];
			else $parts[] = $param['name'].(empty($param['optional']) ? '' : ' = …');
		}
		return $method.'('.implode(', ', $parts).')';
	}

	// ##Lint
	// Returns every problem in one view file.
	function lint_file($relative, $theme = null) {
		$theme = $theme ?: $this->theme;
		return $this->lint_path($this->theme_dir($theme).'/'.$relative, $theme);
	}

	// Applies every mechanical fix in the views of these themes. Returns one
	// entry per change: file, line, was, now.
	function fix($themes = null) {
		$applied = array();
		foreach ($themes ?: array($this->theme) as $theme) {
			foreach ($this->views($theme) as $view) {
				$applied = array_merge($applied, $this->fix_path($this->theme_dir($theme).'/'.$view, $theme));
			}
		}
		return $applied;
	}

	// One pass can make another fix visible (<!--/render--> is first spaced
	// out, then written in full), so it runs until nothing changes.
	function fix_path($path, $theme = null) {
		$applied = array();
		for ($pass = 0; $pass < 3; $pass++) {
			$more = $this->fix_once($path, $theme);
			if (!$more) break;
			$applied = array_merge($applied, $more);
		}
		return $applied;
	}

	protected function fix_once($path, $theme) {
		$problems = $this->lint_path($path, $theme);
		$edits = array();
		foreach ($problems as $problem) {
			if (isset($problem['fix'])) $edits[] = $problem['fix'] + array('line' => $problem['line']);
		}
		if (!$edits) return array();
		// from the end of the file, so the offsets of the others still hold
		usort($edits, function ($a, $b) { return $b['offset'] <=> $a['offset']; });
		$html = file_get_contents($path);
		$applied = array();
		foreach ($edits as $edit) {
			if (substr($html, $edit['offset'], $edit['length']) !== $edit['was']) continue;
			$html = substr_replace($html, $edit['replacement'], $edit['offset'], $edit['length']);
			$applied[] = array('file' => self::short($path), 'line' => $edit['line'], 'was' => $edit['was'], 'now' => $edit['replacement']);
		}
		if ($applied) file_put_contents($path, $html);
		return array_reverse($applied);
	}

	// Lints any view file by its path
	function lint_path($path, $theme = null) {
		return $this->lint_source(file_get_contents($path), self::short($path), $theme);
	}

	// Lints a view that is not on disk yet, so a draft can be checked before
	// anything is written. `dry` references resolve against the theme, as they
	// will once the file is there.
	// the view being linted, for checks that depend on who sees it
	protected $linting = '';

	function lint_source($html, $name = 'view', $theme = null) {
		$theme = $theme ?: $this->theme;
		$this->linting = $name;
		list($blocks, $problems) = self::blocks($html);
		$this->lint_blocks($blocks, $problems, null, $theme);
		$this->lint_forms($html, $problems, $theme);
		usort($problems, function ($a, $b) { return $a['line'] <=> $b['line'] ?: $a['column'] <=> $b['column']; });
		foreach ($problems as &$problem) {
			$problem['file'] = $name;
		}
		unset($problem);
		return $problems;
	}

	protected function lint_blocks($blocks, &$problems, $render, $theme) {
		foreach ($blocks as $block) {
			$keyword = $block['keyword'];
			// mock-up content is removed before anything runs
			if ($keyword === 'remove') continue;
			if ($keyword === 'print' && $render !== null) {
				$this->lint_data_key($block, $problems, $render);
			} elseif ($keyword === 'print' || $keyword === 'render') {
				$this->lint_model_call($block, $problems);
			} elseif ($keyword === 'dry') {
				$this->lint_dry($block, $problems, $theme);
			}
			if (!empty($block['children'])) {
				$this->lint_blocks($block['children'], $problems, $keyword === 'render' ? $block : $render, $theme);
			}
		}
	}

	// print.@src.cms.photo: the attribute, and the model call without it
	static function attribute_call($ref) {
		return preg_match('/^([@+])('.self::name('attribute').'+)\.('.self::name('model').'+\..+)$/', $ref, $m) ? array('attribute' => $m[2], 'append' => $m[1] === '+', 'ref' => $m[3]) : null;
	}

	protected function lint_model_call($block, &$problems) {
		$attribute = $block['keyword'] === 'print' ? self::attribute_call($block['ref']) : null;
		if ($attribute) {
			if ($block['type'] === 'self') {
				$problems[] = self::problem('error', $block, "Attribute directives wrap the tag they change: <!-- {$block['name']} --><img {$attribute['attribute']}=\"...\"><!-- /{$block['name']} -->");
				return;
			}
			if (!preg_match('/\s'.preg_quote($attribute['attribute'], '/').'\s*=/i', $block['inner'])) {
				$problems[] = self::problem('error', $block, "{$block['raw']} sets the '{$attribute['attribute']}' attribute but the HTML it wraps has no {$attribute['attribute']}=\"...\"");
			}
			$block['ref'] = $attribute['ref'];
			$block['type'] = 'open';
		}
		$ref = self::model_reference($block['ref']);
		if ($ref === false) {
			$problems[] = self::problem('error', $block, "{$block['raw']} is outside any render block, so it must name a model and a method: <!-- {$block['keyword']}.model.method -->");
			return;
		}
		if (!preg_match('/^'.self::name('model').'+$/', $ref['model'])) {
			$problems[] = self::problem('error', $block, "Model names are lowercase letters, digits and underscores: '{$ref['model']}'");
			return;
		}
		$call = template::parse_call($ref['method']);
		if ($call === false) {
			$problems[] = self::problem('error', $block, "Can't parse '{$ref['method']}'. Methods take literal arguments only: method, method(3), method('a', true)");
			return;
		}
		list($method, $arguments) = $call;
		if (in_array($ref['model'], self::builtin_models())) return;
		$info = $this->model_info($ref['model']);
		if ($info === false) {
			$problems[] = self::problem('error', $block, "Model '{$ref['model']}' not found; create ".boot::$appname."/models/{$ref['model']}/{$ref['model']}.php with class {$ref['model']}");
			return;
		}
		if (!$info['class']) {
			$problems[] = self::problem('error', $block, "Model file ".self::short($info['file'])." has no class named '{$ref['model']}'");
			return;
		}
		if (!in_array($method, $info['methods']) && !$info['magic']) {
			$problems[] = self::problem('error', $block, "Model '{$ref['model']}' has no public method '$method' (".self::short($info['file']).")".self::did_you_mean($method, $info['methods']));
			return;
		}
		// the arguments are literals, so the count is known here
		if (isset($info['signatures'][$method]) && empty($info['loose'])) {
			$signature = $info['signatures'][$method];
			$given = count($arguments);
			$reads = self::signature_text($method, $signature);
			if ($given < $signature['required']) {
				$problems[] = self::problem('error', $block, "{$ref['model']}.$reads needs {$signature['required']} argument(s), $given given");
			} elseif (!$signature['variadic'] && $given > $signature['total']) {
				$problems[] = self::problem('error', $block, "{$ref['model']}.$reads takes {$signature['total']} argument(s), $given given");
			}
		}
		if ($ref['model'] === 'cms') {
			$template_methods = array('style', 'login', 'login_message', 'buttons');
			// CMS helpers used by templates are written self-closing; a helper name
			// wrapping default content is a field that would never be stored
			$reserved = (in_array($method, $info['methods']) && (!in_array($method, $template_methods) || ($block['keyword'] === 'print' && $block['type'] !== 'self')))
				|| ($block['keyword'] === 'print' && in_array($method, array('slug', 'id', 'updated_at', 'enabled', 'published_at')))
				|| ($block['keyword'] === 'render' && in_array($method, array('users', 'raster')));
			if ($reserved) {
				$problems[] = self::problem('error', $block, "'$method' is reserved by the CMS and can't be used as a ".($block['keyword'] === 'print' ? 'field' : 'collection')." name; pick another name");
				return;
			}
		}
		if ($ref['model'] === 'cms' && !in_array($method, $info['methods'])) {
			if (!preg_match('/^[a-z][a-z0-9_]*$/', $method)) {
				$problems[] = self::problem('error', $block, "CMS field names are lowercase letters, digits and underscores, starting with a letter: '$method'");
			}
			if ($block['keyword'] === 'print' && $block['type'] === 'self') {
				$problems[] = self::problem('warning', $block, "{$block['raw']} has no default content; use <!-- print.cms.$method -->default<!-- /print.cms.$method --> so the field starts with a value");
			}
		}
		if ($block['keyword'] === 'render' && $block['type'] === 'open' && trim($block['inner']) !== '' && trim(strip_tags($block['inner'])) === '' && strpos($block['inner'], '<!--') === false && strpos($block['inner'], '<') === false) {
			$problems[] = self::problem('warning', $block, "{$block['raw']} is empty; render repeats its inner HTML once per item");
		}
		// records a model hands back itself print a false bool as the
		// mock-up and what visitors typed as HTML (#125); on a staff page the
		// in-page editor can't edit them or add one either
		if ($block['keyword'] === 'render' && $ref['model'] !== 'cms' && stripos($block['inner'], '<form') === false) {
			$admin = $this->admin_view($this->linting);
			$type = $this->record_rows($info['file'], $method, !$admin);
			if ($type !== null && $admin) {
				$problems[] = self::problem('warning', $block, "{$block['raw']} shows $type records the {$ref['model']} model reads itself without cms_records::listed(), so the in-page editor can't edit them or add one. Return cms_records::listed('$type', \$rows) (for a list inside a row too), or list them with <!-- render.cms.$type('…') -->: filters from the URL (field=?param), dates (date>=today) and order=a,b cover most staff pages.");
			} elseif ($type !== null) {
				$problems[] = self::problem('warning', $block, "{$block['raw']} shows $type records the {$ref['model']} model reads itself without cms_records::listed(), so a false bool prints the mock-up and what visitors typed prints as HTML. Return cms_records::listed('$type', \$rows) (for a list inside a row too), or list them with <!-- render.cms.$type('…') -->.");
			}
		}
	}

	// whether config 'protected' keeps a view (staff.html, or a path ending
	// in views/<theme>/staff.html) for editors or admins: an admin page
	function admin_view($view) {
		$name = preg_replace('#^.*/views/[^/]+/#', '', (string)$view);
		$name = preg_replace('/\.[a-z]+$/', '', $name);
		foreach (authentication::protection($name) as $role) {
			if (in_array($role, array('editor', 'edit', 'admin'), true)) return true;
		}
		return false;
	}

	// the record type a model method reads with cms_records::find() or get()
	// and hands to the template without cms_records::listed(), or null. With
	// $text, rows a method makes text itself, with cms_records::for_template()
	// or util::e(), are fine too (they print as records do, but aren't
	// editable).
	function record_rows($file, $method, $text = false) {
		$source = @file_get_contents($file);
		if ($source === false || !preg_match('/function\s+'.preg_quote($method, '/').'\s*\(/', $source, $m, PREG_OFFSET_CAPTURE)) return null;
		$body = substr($source, $m[0][1]);
		// up to the next method
		if (preg_match('/\n\s*(?:(?:public|protected|private|static|final)\s+)*function\s/', $body, $next, PREG_OFFSET_CAPTURE, 1)) $body = substr($body, 0, $next[0][1]);
		if (strpos($body, 'cms_records::listed(') !== false || ($text && (strpos($body, 'cms_records::for_template(') !== false || strpos($body, 'util::e(') !== false))) return null;
		if (!preg_match_all('/cms_records::(?:find|get)\(\s*[\'"]([a-z][a-z0-9]*)[\'"]/', $body, $found)) return null;
		foreach ($found[1] as $type) {
			if (cms_records::info($type)) return $type;
		}
		return null;
	}

	protected function lint_data_key($block, &$problems, $render) {
		$key = self::data_key($block['ref']);
		if ($key['builtin']) return;
		if (strpos($key['key'], '.') !== false) {
			// model.method inside a render block runs after the block renders,
			// once for every copy
			if ($key['attribute'] === null) $this->lint_model_call($block, $problems);
			return;
		}
		// the engine reads only keys made of these (template::$names)
		if (!preg_match('/^'.self::name('field').'+$/', $key['key'])) {
			$problems[] = self::problem('error', $block, "Keys inside a render block are letters, digits, _ and -: '{$key['key']}' is never printed");
			return;
		}
		if ($key['attribute'] !== null) {
			if ($block['type'] === 'self') {
				$problems[] = self::problem('error', $block, "Attribute directives wrap the tag they change: <!-- {$block['name']} --><a {$key['attribute']}=\"...\">...</a><!-- /{$block['name']} -->");
			} elseif (!preg_match('/\s'.preg_quote($key['attribute'], '/').'\s*=/i', $block['inner'])) {
				$problems[] = self::problem('error', $block, "{$block['raw']} sets the '{$key['attribute']}' attribute but the HTML it wraps has no {$key['attribute']}=\"...\"");
			}
		}
		$ref = self::model_reference($render['ref']);
		if ($ref && $ref['model'] === 'cms' && !preg_match('/^[a-z][a-z0-9_]*$/', $key['key'])) {
			$problems[] = self::problem('error', $block, "CMS field names are lowercase letters, digits and underscores, starting with a letter: '{$key['key']}'");
		}
	}

	protected function lint_dry($block, &$problems, $theme) {
		if (!preg_match('/^('.self::name('view').'+)\.('.self::name('res').'+)$/', $block['ref'], $m)) {
			$problems[] = self::problem('error', $block, "dry takes a view and a res block: <!-- dry.layout.header /-->");
			return;
		}
		$file = $this->theme_dir($theme).'/'.$m[1].$this->ext;
		if (!file_exists($file)) {
			$problems[] = self::problem('error', $block, "{$block['raw']} reads from ".self::short($file)." which does not exist");
			return;
		}
		$source = file_get_contents($file);
		if (strpos($source, "<!-- res.{$m[2]} -->") === false || strpos($source, "<!-- /res.{$m[2]} -->") === false) {
			$problems[] = self::problem('error', $block, "{$block['raw']}: ".self::short($file)." has no <!-- res.{$m[2]} --> ... <!-- /res.{$m[2]} --> block");
		}
	}

	static function position($html, $offset) {
		$before = substr($html, 0, $offset);
		$nl = strrpos($before, "\n");
		return array('line' => substr_count($before, "\n") + 1, 'column' => $nl === false ? $offset + 1 : $offset - $nl);
	}

	static function problem_at($severity, $html, $offset, $message) {
		return array('severity' => $severity, 'message' => $message) + self::position($html, $offset);
	}

	// every raise('x') and done('x') in the models, so alerts can be checked
	function raised_names() {
		if (self::$raised !== null) return self::$raised;
		$names = array();
		foreach (array(BASE.'models', APPBASE.config::get('models_path', 'models')) as $dir) {
			if (!is_dir($dir)) continue;
			foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
				if (substr($file, -4) !== '.php') continue;
				$source = file_get_contents($file);
				preg_match_all('/(?:raise|done|refuse)\(\s*[\'"]([a-z0-9_]+)[\'"]/', $source, $m);
				$names = array_merge($names, $m[1]);
				// problems a check() names: $problems[] = 'sold_out'
				preg_match_all('/\$problems\[\]\s*=\s*[\'"]([a-z0-9_]+)[\'"]/', $source, $m);
				$names = array_merge($names, $m[1]);
				// cms_records::submit('inquiry', 'sent') redirects with ?done=sent
				// (the type's name when there is no second argument)
				preg_match_all('/submit\(\s*[\'"]([a-z0-9_]+)[\'"](?:\s*,\s*[\'"]([a-z0-9_]+)[\'"])?/', $source, $m, PREG_SET_ORDER);
				foreach ($m as $call) $names[] = isset($call[2]) && $call[2] !== '' ? $call[2] : $call[1];
			}
		}
		// what a records form raises when the visitor may not create, and
		// when a value isn't of its field's type (guests_invalid)
		$names = array_merge($names, array('login_required', 'not_allowed'));
		foreach (cms_records::types() as $info) {
			foreach ($info['types'] as $field => $type) if ($type !== 'text') $names[] = $field.'_invalid';
		}
		return self::$raised = array_unique($names);
	}

	// ##Forms
	// Post forms, validation regions and alerts follow conventions the
	// engine relies on; these checks catch the mistakes that fail silently.
	protected function lint_forms($html, &$problems, $theme) {
		// render blocks
		preg_match_all('/<!-- (\/?)render\.([a-z0-9_\-]+\.[^ ]*(?:\([^)]*\))?) -->/', $html, $tags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
		$blocks = array(); $stack = array();
		foreach ($tags as $tag) {
			if ($tag[1][0] === '') { $stack[] = array('ref' => $tag[2][0], 'start' => $tag[0][1]); continue; }
			for ($i = count($stack) - 1; $i >= 0; $i--) {
				if ($stack[$i]['ref'] === $tag[2][0]) {
					$blocks[] = array('ref' => $tag[2][0], 'start' => $stack[$i]['start'], 'end' => $tag[0][1]);
					array_splice($stack, $i, 1);
					break;
				}
			}
		}
		// post forms and their owners
		$forms = array();
		preg_match_all('/<form\b[^>]*>/i', $html, $opens, PREG_OFFSET_CAPTURE);
		foreach ($opens[0] as $open) {
			if (!preg_match('/\bmethod\s*=\s*["\']?post/i', $open[0])) continue;
			$close = stripos($html, '</form>', $open[1]);
			$end = $close === false ? strlen($html) : $close;
			$owner = ''; $best = -1;
			foreach ($blocks as $block) {
				if (strpos($block['ref'], 'validation.') === 0) continue;
				if ($block['start'] < $open[1] && $block['end'] > $open[1] && $block['start'] > $best) { $owner = $block['ref']; $best = $block['start']; }
			}
			if ($owner === '') {
				$problems[] = self::problem_at('warning', $html, $open[1], 'This post form is not inside a render block, so no model handles it. Wrap it: <!-- render.model.method --><form method="post">...</form><!-- /render.model.method -->');
			}
			$forms[] = array('owner' => $owner, 'at' => $open[1], 'end' => $end, 'fields' => template::instance()->constraints(substr($html, $open[1], $end - $open[1])));
		}
		// validation regions
		$validation = $this->model_info('validation');
		$methods = $validation ? $validation['methods'] : array();
		preg_match_all('/<!-- (print|render)\.validation\.([a-z0-9_]+)(\(([^)]*)\))? \/?-->/', $html, $vtags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
		foreach ($vtags as $vtag) {
			$method = $vtag[2][0];
			$at = $vtag[0][1];
			if (in_array($method, array('alert', 'raise', 'finalize', 'get', 'submitted', 'valid', 'errors'))) continue;
			$call = template::parse_call($method.(isset($vtag[3]) ? $vtag[3][0] : ''));
			if (!in_array($method, $methods)) {
				$rule_file = APPBASE.config::get('models_path', 'models').'/validation/rules/'.$method.'.php';
				if (!file_exists($rule_file)) {
					$problems[] = self::problem_at('error', $html, $at, "Unknown validation rule '$method'. Built in: field, matches, cant_be, accepted; or create ".self::short($rule_file));
					continue;
				}
			}
			$form = null;
			foreach ($forms as $f) {
				if ($at > $f['at'] && $at < $f['end']) $form = $f;
			}
			if (!$form || $form['owner'] === '') {
				$problems[] = self::problem_at('warning', $html, $at, "validation.$method only shows inside a post form that sits in a render block");
				continue;
			}
			$fields = $call ? array_filter($call[1], 'is_string') : array();
			$checked = $method === 'cant_be' ? array_slice($fields, 0, 1) : ($method === 'field' || $method === 'accepted' || $method === 'not_empty' || $method === 'email_format' ? array_slice($fields, 0, 1) : $fields);
			foreach ($checked as $field) {
				if (!isset($form['fields'][$field])) {
					$problems[] = self::problem_at('error', $html, $at, "validation.$method checks '$field' but the form has no input named $field");
				}
			}
			if ($method === 'field' && $fields && isset($form['fields'][reset($fields)])) {
				$rules = $form['fields'][reset($fields)];
				unset($rules['type'], $rules['list'], $rules['step']);
				if (!$rules && !in_array($form['fields'][reset($fields)]['type'], array('email', 'url', 'number', 'range', 'date'))) {
					$problems[] = self::problem_at('warning', $html, $at, "validation.field('".reset($fields)."') never shows: the input has no constraint (required, type, minlength, maxlength, min, max, pattern)");
				}
			}
		}
		// alerts need something that raises them
		preg_match_all("/<!-- print\.validation\.alert\('([a-z0-9_]+)'\) -->/", $html, $alerts, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
		$raised = $this->raised_names();
		foreach ($alerts as $alert) {
			if (!in_array($alert[1][0], $raised)) {
				$problems[] = self::problem_at('warning', $html, $alert[0][1], "No model raises '{$alert[1][0]}': call validation::get()->raise('{$alert[1][0]}') or util::done('{$alert[1][0]}') for this alert to show");
			}
		}
		// forms that can turn a post away need alerts to say so
		// (a fragment the view includes, such as a layout's header, counts)
		$whole = $this->expand($html, $theme);
		foreach (validation_spam::$checked_regions as $region) {
			if (!preg_match('/<!-- '.preg_quote($region, '/').'[ (]/', $html, $m, PREG_OFFSET_CAPTURE)) continue;
			if (strpos($whole, "print.validation.alert('try_again')") === false || strpos($whole, "print.validation.alert('slow_down')") === false) {
				$problems[] = self::problem_at('warning', $html, $m[0][1], "$this->linting: $region can turn a post away as spam; add print.validation.alert('try_again') and print.validation.alert('slow_down') so the person knows to send it again");
				break;
			}
		}
		// models that send emails need their email views, as their
		// requires() names them; a region in this view is a use of its
		// model, so a draft is checked before it is written
		foreach (controller::required('emails', true) as $tag => $views) {
			$at = strpos($html, '<!-- '.$tag.' -->');
			if ($at === false) continue;
			foreach ((array)$views as $view) {
				if (file_exists($this->theme_dir($theme).'/'.$view.$this->ext)) continue;
				$problems[] = self::problem_at('error', $html, $at, "$tag sends an email written in ".self::short($this->theme_dir($theme).'/'.$view.$this->ext).", which does not exist");
			}
		}
		// and the records staff must make first (a booking form needs a
		// resource); without a database to ask, nothing is said
		foreach (controller::required('records') as $tag => $types) {
			$at = strpos($html, '<!-- '.$tag.' -->');
			if ($at === false) continue;
			foreach ((array)$types as $type) {
				if ($this->record_count($type) !== 0) continue;
				$problems[] = self::problem_at('warning', $html, $at, "$tag needs at least one $type record, and there is none yet: staff add one in a page that renders render.cms.$type, or over MCP (create_item)");
			}
		}
		// the guests' manage pages (booking's, rsvp's) answer links from
		// emails: an editors' page would send every guest to the login
		$at = strpos($html, '<!-- render.booking.manage -->');
		if ($at !== false && $this->admin_view($this->linting)) {
			$problems[] = self::problem_at('warning', $html, $at, "render.booking.manage is the guests' page, but config 'protected' keeps this view for staff: protect staff pages by their own names (staff, booking_item), never booking");
		}
		$at = strpos($html, '<!-- render.rsvp.manage -->');
		if ($at !== false && $this->admin_view($this->linting)) {
			$problems[] = self::problem_at('warning', $html, $at, "render.rsvp.manage is the guests' page, but config 'protected' keeps this view for staff: protect staff pages by their own names (staff, rsvp_item), never rsvp");
		}
		// the reply form reads each event's date and places from the fields
		// of its collection (rsvp_collection), as the views declare them
		$at = strpos($html, '<!-- render.rsvp.form -->');
		if ($at !== false) {
			$collection = (string)config::get('rsvp_collection', 'events');
			$fields = $this->collection_fields($collection, $theme);
			if (!isset($fields['date'])) {
				$problems[] = self::problem_at('error', $html, $at, "render.rsvp.form takes replies to the items of '$collection', whose views have no date field, so no item takes replies: write <!-- print.date -->2026-11-20<!-- /print.date --> in its item view (config rsvp_collection names the collection)");
			} elseif (!isset($fields['places'])) {
				$problems[] = self::problem_at('warning', $html, $at, "render.rsvp.form: the items of '$collection' have no places field, so no event has a limit, and so no waitlist: write <!-- print.places -->40<!-- /print.places --> in its item view");
			}
		}
		// the contact messages are for staff: the region shows visitors
		// nothing, but the page belongs in the Admin menu, behind the login
		if (preg_match('/<!-- render\.contact\.messages[ (]/', $html, $m, PREG_OFFSET_CAPTURE) && !$this->admin_view($this->linting)) {
			$problems[] = self::problem_at('warning', $html, $m[0][1], "$this->linting lists contact messages but isn't protected for editors: add it to config 'protected' (config::set('protected')->to(array('staff' => 'editor')))");
		}
		// so are the rsvp replies and their counts
		if (preg_match('/<!-- render\.rsvp\.(?:events|attention)[ (]/', $html, $m, PREG_OFFSET_CAPTURE) && !$this->admin_view($this->linting)) {
			$problems[] = self::problem_at('warning', $html, $m[0][1], "$this->linting lists rsvp replies but isn't protected for editors: add it to config 'protected' (config::set('protected')->to(array('staff' => 'editor'))), never rsvp, the guests' page");
		}
	}

	// how many records of a declared type there are, or null when that
	// can't be known (no such type, no database)
	protected function record_count($type) {
		$info = cms_records::info($type);
		if (!$info) return null;
		try {
			cms_store::connect();
			if (!database::configured()) return null;
			return cms_store::table_exists($info['type']) ? (int)R::count($info['type']) : 0;
		} catch (Exception $e) {
			return null;
		}
	}

	// the routes in config/the_routes.php: pattern, view, theme, line
	function routes() {
		$file = APPBASE.'config/the_routes.php';
		$routes = array();
		if (!file_exists($file)) return $routes;
		$code = '';
		foreach (token_get_all(file_get_contents($file)) as $token) {
			if (is_array($token) && in_array($token[0], array(T_COMMENT, T_DOC_COMMENT))) {
				$code .= str_repeat("\n", substr_count($token[1], "\n"));
			} else {
				$code .= is_array($token) ? $token[1] : $token;
			}
		}
		foreach (explode("\n", $code) as $n => $line) {
			if (!preg_match('/route\(\s*([\'"])(.*?)\1\s*\)\s*->\s*to\(\s*([\'"])(.*?)\3\s*\)(?:\s*->\s*from\(\s*([\'"])(.*?)\5\s*\))?/', $line, $m)) continue;
			$routes[] = array('pattern' => $m[2], 'view' => $m[4], 'theme' => isset($m[6]) && $m[6] !== '' ? $m[6] : $this->theme, 'line' => $n + 1, 'code' => $line);
		}
		return $routes;
	}

	// The pattern of the first route that matches a URL, the way the
	// controller picks one, or null when none does
	function route_for($uri) {
		foreach ($this->routes() as $route) {
			if (@preg_match('%^/?'.$route['pattern'].'%', ltrim((string)$uri, '/'))) return $route['pattern'];
		}
		return null;
	}

	// Checks routes in application/config/the_routes.php point at views
	function lint_routes() {
		$problems = array();
		$file = APPBASE.'config/the_routes.php';
		if (!file_exists($file)) return $problems;
		// comments are blanked out (keeping line numbers) so examples are skipped
		$code = '';
		foreach (token_get_all(file_get_contents($file)) as $token) {
			if (is_array($token) && in_array($token[0], array(T_COMMENT, T_DOC_COMMENT))) {
				$code .= str_repeat("\n", substr_count($token[1], "\n"));
			} else {
				$code .= is_array($token) ? $token[1] : $token;
			}
		}
		$lines = explode("\n", $code);
		foreach ($lines as $n => $line) {
			if (!preg_match('/route\(\s*([\'"])(.*?)\1\s*\)\s*->\s*to\(\s*([\'"])(.*?)\3\s*\)(?:\s*->\s*from\(\s*([\'"])(.*?)\5\s*\))?/', $line, $m)) continue;
			$theme = isset($m[6]) && $m[6] !== '' ? $m[6] : config::get('theme', $this->theme);
			$view = $m[4];
			$app_file = $this->theme_dir($theme).'/'.$view.$this->ext;
			$system_file = BASE.'views/'.$theme.'/'.$view.$this->ext;
			if (!file_exists($app_file) && !file_exists($system_file)) {
				$problems[] = array('severity' => 'error', 'file' => self::short($file), 'line' => $n + 1, 'column' => strpos($line, 'to(') + 1,
					'message' => "Route '{$m[2]}' points to view '$view' but ".self::short($app_file)." does not exist");
			}
			if (@preg_match('%^/?'.$m[2].'%', '') === false) {
				$problems[] = array('severity' => 'error', 'file' => self::short($file), 'line' => $n + 1, 'column' => strpos($line, 'route(') + 1,
					'message' => "Route pattern '{$m[2]}' is not a valid regular expression");
			}
		}
		return $problems;
	}

	// ##Events
	// Where the app listens: bindings in config/the_events.php and
	// listens() in the models (controller::models()). array(event, model, method, file, line)
	function listeners() {
		$found = array();
		$file = APPBASE.'config/the_events.php';
		if (file_exists($file)) {
			foreach (explode("\n", $this->without_comments(file_get_contents($file))) as $n => $line) {
				if (!preg_match_all('/event::bind\(\s*([\'"])(.+?)\1\s*\)\s*->\s*to\(\s*([\'"])(.+?)\3\s*,\s*([\'"])(.+?)\5\s*\)/', $line, $matches, PREG_SET_ORDER)) continue;
				foreach ($matches as $m) $found[] = array('event' => $m[2], 'model' => $m[4], 'method' => $m[6], 'file' => self::short($file), 'line' => $n + 1);
			}
		}
		foreach (controller::models() as $model => $declared) {
			if (!$declared['listens']) continue;
			// the line of listens() in the file that has it: the override's or the bundled one's
			$line = 1;
			foreach (array_filter(array($declared['file'], $declared['parent'])) as $model_file) {
				$source = file_get_contents($model_file);
				$at = strpos($source, 'function listens(');
				if ($at === false) continue;
				$line = substr_count(substr($source, 0, $at), "\n") + 1;
				break;
			}
			foreach ($declared['listens'] as $event => $methods) {
				foreach ((array)$methods as $method) $found[] = array('event' => $event, 'model' => $model, 'method' => $method, 'file' => self::short($model_file), 'line' => $line);
			}
		}
		return $found;
	}

	// events something dispatches by name: the framework's and the app's
	function dispatched_events() {
		$names = array('launch', 'finding_route', 'route_set', 'route_found', 'route_not_found', 'before_drying', 'after_drying', 'before_render', 'after_render', 'before_print', 'after_print', 'loop', 'before_output', 'done', 'land', 'read_get_data', 'read_post_data', 'read_cookie_data');
		foreach (array(BASE, APPBASE) as $root) {
			$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
			foreach ($iterator as $file) {
				if (substr($file->getFilename(), -4) !== '.php' || strpos($file->getPathname(), '/libraries/') !== false || strpos($file->getPathname(), '/data/') !== false) continue;
				if (preg_match_all('/(?:event|cms_records)::dispatch\(\s*([\'"])([a-z0-9_.]+)\1/', file_get_contents($file->getPathname()), $m)) {
					$names = array_merge($names, $m[2]);
				}
			}
		}
		$names = array_values(array_unique($names));
		sort($names);
		return $names;
	}

	// is something going to send this event?
	function event_exists($event, $known) {
		if (in_array($event, $known, true)) return true;
		if (preg_match('/^loading_model_([a-z0-9_]+)$/', $event, $m)) return (bool)$this->model_info($m[1]);
		if (strpos($event, 'dried_') === 0) return true;
		if (preg_match('/^execut(?:ing|ed)_([a-z0-9_]+)$/', $event, $m)) {
			// executed_news_latest: some split into an existing model and method
			$parts = explode('_', $m[1]);
			for ($i = 1; $i < count($parts); $i++) {
				$info = $this->model_info(implode('_', array_slice($parts, 0, $i)));
				if ($info && ($info['magic'] || in_array(implode('_', array_slice($parts, $i)), $info['methods']))) return true;
			}
		}
		return false;
	}

	function lint_events() {
		$problems = array();
		$known = $this->dispatched_events();
		foreach ($this->listeners() as $l) {
			$at = array('file' => $l['file'], 'line' => $l['line'], 'column' => 1);
			$info = $this->model_info($l['model']);
			if (!$info) {
				$problems[] = $at + array('severity' => 'error', 'message' => "'{$l['event']}' is bound to model '{$l['model']}', which does not exist");
				continue;
			}
			if (!$info['magic'] && !in_array($l['method'], $info['methods'])) {
				$problems[] = $at + array('severity' => 'error', 'message' => "'{$l['event']}' is bound to {$l['model']}.{$l['method']}, which is not a public method of {$l['model']}");
			}
			if (!$this->event_exists($l['event'], $known)) {
				$problems[] = $at + array('severity' => 'warning', 'message' => "Nothing sends '{$l['event']}', so {$l['model']}.{$l['method']} never runs. Events sent: ".implode(', ', array_filter($known, function ($e) { return strpos($e, '.') !== false; })).', and the request events in AGENTS.md');
			}
		}
		return $problems;
	}

	protected function without_comments($code) {
		$out = '';
		foreach (token_get_all($code) as $token) {
			if (is_array($token) && in_array($token[0], array(T_COMMENT, T_DOC_COMMENT))) $out .= str_repeat("\n", substr_count($token[1], "\n"));
			else $out .= is_array($token) ? $token[1] : $token;
		}
		return $out;
	}

	// ##Named queries
	// database::instance('cafe')->count_category(...) needs
	// models/cafe/sql/count_category.sql (or the_cafe's, or the bundled
	// model's) or $queries['count_category'] in models/sql.php. Without a
	// model name, the calling model's folders count.
	function lint_queries() {
		$problems = array();
		$models = APPBASE.config::get('models_path', 'models');
		if (!is_dir($models)) return $problems;
		$own = array_map('strtolower', get_class_methods('database'));
		$named = database::named_queries();
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($models, FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if (substr($file->getFilename(), -4) !== '.php') continue;
			$relative = substr($file->getPathname(), strlen($models) + 1);
			$folder = strpos($relative, '/') !== false ? strstr($relative, '/', true) : null;
			$source = $this->without_comments(file_get_contents($file->getPathname()));
			if (!preg_match_all('/database::instance\(\s*(?:([\'"])([a-z0-9_]+)\1)?\s*\)\s*->\s*([a-z_][a-z0-9_]*)\s*\(/i', $source, $calls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) continue;
			foreach ($calls as $call) {
				$name = $call[3][0];
				if (in_array(strtolower($name), $own)) continue;
				$model = isset($call[2]) && $call[2][0] !== '' ? $call[2][0] : ($folder !== null ? preg_replace('/^the_/', '', $folder) : null);
				if (database::query_file($model, $name) !== null || isset($named[$name])) continue;
				$where = $model ? config::get('models_path', 'models')."/$model/sql/$name.sql" : "models/<model>/sql/$name.sql";
				$problems[] = array(
					'severity' => 'error', 'file' => self::short($file->getPathname()),
					'line' => substr_count(substr($source, 0, $call[0][1]), "\n") + 1, 'column' => 1,
					'message' => "No query named '$name': add $where, or \$queries['$name'] in models/sql.php",
				);
			}
		}
		return $problems;
	}

	// The themes a site's pages come from: its own, and the ones routes
	// serve views from (route('print/menu')->to('menu')->from('print')), so
	// lint, schema, describe and MCP read every view a URL can render (#133)
	function themes() {
		$themes = array($this->theme);
		foreach ($this->routes() as $route) {
			if (in_array($route['theme'], $themes, true) || !preg_match('/^[A-Za-z0-9_\-]+$/', $route['theme'])) continue;
			if (is_dir($this->theme_dir($route['theme']))) $themes[] = $route['theme'];
		}
		return $themes;
	}

	function lint($themes = null) {
		$problems = array();
		$themes = $themes ?: $this->themes();
		foreach ($themes as $theme) {
			foreach ($this->views($theme) as $view) {
				$problems = array_merge($problems, $this->lint_file($view, $theme), $this->lint_address($view, $theme));
			}
		}
		return array_merge($problems, $this->lint_routes(), $this->lint_events(), $this->lint_queries(), $this->lint_types(), $this->lint_collections($themes), $this->lint_api(), $this->lint_models());
	}

	// Two collections whose names differ only by what a table name drops
	// (team_members and teammembers) share one table, teammembersdata: each
	// list shows the other's items (#33).
	function lint_collections($themes = null) {
		$problems = array();
		$tables = array();
		foreach ($themes ?: $this->themes() as $theme) {
			foreach ($this->content_model($theme)['collections'] as $name => $collection) {
				if (!isset($tables[$collection['type']][$name])) $tables[$collection['type']][$name] = $collection['views'] ? array($theme, $collection['views'][0]) : null;
			}
		}
		foreach ($tables as $table => $names) {
			if (count($names) < 2) continue;
			$list = array_keys($names);
			foreach (array_slice($list, 1) as $name) {
				// where the second name is rendered, or the model that declares it
				$info = cms_records::info($name);
				$file = $info ? (new ReflectionClass($info['class']))->getFileName() : APPBASE.'models';
				$at = array('file' => self::short($file), 'line' => 1, 'column' => 1);
				if ($names[$name]) {
					list($theme, $view) = $names[$name];
					$html = (string)file_get_contents($this->theme_dir($theme).'/'.$view);
					$offset = strpos($html, '<!-- render.cms.'.$name);
					$at = array('file' => self::short($this->theme_dir($theme).'/'.$view)) + ($offset === false ? array('line' => 1, 'column' => 1) : self::position($html, $offset));
				}
				$problems[] = $at + array('severity' => 'error', 'message' => "The collections '{$list[0]}' and '$name' share one table, $table, so each shows the other's items: give one of them another name");
			}
		}
		return $problems;
	}

	// A view whose address the server refuses: the rules that keep an app's
	// config/, models/, data/ and i18n/ private apply to the second segment
	// of every URL, so docs/config.html is a page nobody can open (#98).
	function lint_address($view, $theme) {
		if (controller::is_partial($view)) return array();
		// a feed (news.rss) is its file's own address
		$url = cms::page_path($view, $this->ext) ?: '/'.$view;
		require_once BASE.'private_paths.php';
		if (!private_paths::blocked($url)) return array();
		return array(array('file' => self::short($this->theme_dir($theme).'/'.$view), 'line' => 1, 'column' => 1, 'severity' => 'warning',
			'message' => "$url is refused by the server (system/private_paths.php keeps config/, models/, data/ and i18n/ private under any folder), so nobody can open this page: give the view another name"));
	}

	// A site model with a bundled model's name replaces it whole: the
	// bundled one's methods, listeners and tables are gone, and bundled code
	// that calls it gets the site's class. A site type with the name of a
	// bundled model in use, or of one of its types, keeps it, and the
	// bundled model's types are left out (cms_records::types).
	function lint_models() {
		$problems = array();
		foreach (controller::models() as $name => $found) {
			if (!$found['shadows']) continue;
			$problems[] = array('file' => self::short($found['file']), 'line' => 1, 'column' => 1, 'severity' => 'warning',
				'message' => config::get('models_path', 'models')."/$name shadows the bundled $name, which is never loaded: to change the bundled one, name the folder the_$name (class the_$name extends $name); otherwise pick another name");
		}
		cms_records::types();
		foreach (cms_records::$left_out as $name => $taken) {
			$found = controller::model($taken['by']);
			$problems[] = array('file' => $found ? self::short($found['file']) : $taken['by'], 'line' => 1, 'column' => 1, 'severity' => 'warning',
				'message' => "{$taken['by']} declares a type '{$taken['type']}', so the bundled $name model's types are left out and render.$name.* finds nothing: rename that type to use the bundled $name, or stop using render.$name.* in the views");
		}
		return $problems;
	}

	// What models offer over /api (static function api()): every method
	// listed must be a public method that isn't static, with a known role.
	function lint_api() {
		$problems = array();
		// the site's own models and overrides; the bundled ones are Raster's
		foreach (controller::models() as $name => $found) {
			$class = $found['class'];
			$file = $found['file'];
			if (strpos($file, APPBASE) !== 0 || strpos(file_get_contents($file), 'function api(') === false) continue;
			try {
				if (!controller::model_class($name) || !method_exists($class, 'api')) continue;
				$declaration = new ReflectionMethod($class, 'api');
				$at = array('file' => self::short($file), 'line' => $declaration->getStartLine(), 'column' => 1);
				if (!$declaration->isStatic()) {
					$problems[] = $at + array('severity' => 'error', 'message' => "$class::api() must be static; as it is, it offers nothing");
					continue;
				}
				$listed = call_user_func(array($class, 'api'));
				if (!is_array($listed)) {
					$problems[] = $at + array('severity' => 'error', 'message' => "$class::api() must return an array of method => role (visitor, member, editor or admin); it offers nothing");
					continue;
				}
			} catch (Throwable $e) {
				$problems[] = array('file' => self::short($file), 'line' => 1, 'column' => 1, 'severity' => 'error', 'message' => "$class::api(): ".$e->getMessage());
				continue;
			}
			foreach ($listed as $method => $role) {
				if (is_int($method)) {
					$problems[] = $at + array('severity' => 'error', 'message' => "$class::api() lists '".(is_scalar($role) ? $role : gettype($role))."' without a role, so it offers nothing: write '".(is_scalar($role) ? $role : 'method')."' => 'visitor' (or member, editor, admin)");
					continue;
				}
				if (!is_string($method) || !method_exists($class, $method)) {
					$problems[] = $at + array('severity' => 'error', 'message' => "$class::api() offers '$method', but $class has no such method");
					continue;
				}
				$m = new ReflectionMethod($class, $method);
				if ($m->getName() !== $method) {
					$problems[] = $at + array('severity' => 'error', 'message' => "$class::api() offers '$method', but the method is spelled '".$m->getName()."', and /api is case sensitive");
				}
				if (!$m->isPublic() || $m->isStatic()) {
					$problems[] = $at + array('severity' => 'error', 'message' => "$class::api() offers '$method', which /api can't call: it must be public and not static");
				}
				if (!in_array($role, api::$roles, true)) {
					$problems[] = $at + array('severity' => 'error', 'message' => "$class::api() gives '$method' the role '".(is_scalar($role) ? $role : gettype($role))."'; the roles are ".implode(', ', api::$roles).". Until it is one of them, nobody can call it");
				}
			}
		}
		return $problems;
	}

	// Types models declare: the hooks must be static (so /api can't reach
	// them) and every action needs its method.
	function lint_types() {
		$problems = array();
		foreach (cms_records::types() as $name => $info) {
			$reflection = new ReflectionClass($info['class']);
			$at = array('file' => self::short($reflection->getFileName()), 'line' => $reflection->getStartLine(), 'column' => 1);
			if (cms::reserved($name, 'collection')) {
				$problems[] = $at + array('severity' => 'error', 'message' => "'$name' is a name the CMS keeps for itself; call the type something else");
			}
			foreach ($info['unknown'] as $key) {
				$problems[] = $at + array('severity' => 'warning', 'message' => "The type '$name' has '$key', which means nothing. A type has: ".implode(', ', cms_records::$keys));
			}
			if (!$info['fields']) {
				$problems[] = $at + array('severity' => 'error', 'message' => "The type '$name' declares no fields ('fields' => array('name' => '', ...))");
			}
			// RedBean reads x_id as a link to an x record, and then never stores x
			foreach (array_keys($info['fields']) as $field) {
				if (isset($info['fields'][$field.'_id'])) {
					$problems[] = $at + array('severity' => 'error', 'message' => "The type '$name' has '$field' and '{$field}_id': the database reads '{$field}_id' as a link to a '$field' record and never stores '$field'. Rename one of them.");
				}
			}
			// records a form makes and no page lists: stored, and nobody sees them
			if ($info['create'] !== 'editor' && !$this->listed($name)) {
				$problems[] = $at + array('severity' => 'warning', 'message' => "Visitors make '$name' records with a form, but no view lists them with render.cms.$name, so staff can't see or edit them in the page. Add a staff page: <!-- render.cms.$name('order=newest') --> in a view protected for editors (config 'protected').");
			}
			foreach (array('check', 'fill') as $hook) {
				if (method_exists($info['class'], $hook) && !(new ReflectionMethod($info['class'], $hook))->isStatic()) {
					$problems[] = $at + array('severity' => 'error', 'message' => "{$info['class']}::$hook() must be static: a hook is never an ordinary method");
				}
			}
			foreach ($info['actions'] as $action => $role) {
				if (in_array($action, cms_records::$hooks, true)) {
					$problems[] = $at + array('severity' => 'error', 'message' => "'$action' can't be an action of '$name': the name has a meaning of its own");
				} elseif (!cms_records::is_action_method($info['class'], $action)) {
					$problems[] = $at + array('severity' => 'error', 'message' => "The type '$name' has the action '$action' but {$info['class']} has no public static function $action(\$item, \$input)");
				}
			}
		}
		return $problems;
	}

	static function short($path) {
		$root = dirname(APPBASE).'/';
		return strpos($path, $root) === 0 ? substr($path, strlen($root)) : $path;
	}

	// ##Content model
	// Expands dry includes the same way the engine does, so CMS tags that
	// live in shared layouts are counted for every page that uses them.
	function expand($html, $theme = null, $depth = 0) {
		if ($depth >= self::grammar()['dry_depth']) return $html;
		$self = $this;
		return preg_replace_callback('/<!-- dry\.('.self::name('view').'+)\.('.self::name('res').'+) (\/?)-->(?:(.*?)<!-- \/dry\.\1\.\2 -->)?/s', function ($m) use ($self, $theme, $depth) {
			$file = $self->theme_dir($theme).'/'.$m[1].$self->ext;
			if (!file_exists($file)) return '';
			$source = file_get_contents($file);
			$start = "<!-- res.{$m[2]} -->"; $end = "<!-- /res.{$m[2]} -->";
			$a = strpos($source, $start); $b = strpos($source, $end);
			if ($a === false || $b === false) return '';
			return $self->expand(substr($source, $a + strlen($start), $b - $a - strlen($start)), $theme, $depth + 1);
		}, $html);
	}

	// The URL path the CMS uses to identify the page a view renders: the
	// one cms::slug_for_uri() gives the view's URL, so schema, describe and
	// MCP name the table the page reads. An _item view is its collection's
	// item page, wherever it is.
	function slug_for_view($view) {
		$name = substr($view, 0, -strlen($this->ext));
		if (preg_match('/^(?:(.*)\/)?([a-z0-9_]+)_item$/', $name, $m)) {
			return cms::item_path($m[2]);
		}
		return cms::slug_for_uri('/'.$name);
	}

	function url_for_view($view) {
		$slug = $this->slug_for_view($view);
		if ($slug === 'home') return '/';
		$url = cms::collection_uri($slug);
		if ($url && $url['kind'] === 'item' && $slug === cms::item_path($url['name'])) return cms::item_path($url['name'], '{slug}');
		return $slug;
	}

	// Describes the CMS content of the theme: page fields per view and
	// collections with their fields. Defaults are the placeholder content
	// in the markup, which is what the CMS stores on first render.
	// whether a view of any theme renders render.cms.<name> (partials too),
	// or a model's region that its requires() says lists the type
	// ('lists' => array('contactmessage' => array('render.contact.messages')))
	function listed($name) {
		$regions = array('render.cms.'.$name);
		$lists = controller::required('lists');
		if (isset($lists[$name])) $regions = array_merge($regions, (array)$lists[$name]);
		$pattern = '/<!-- (?:'.implode('|', array_map(function ($region) { return preg_quote($region, '/'); }, $regions)).')(\(| -->)/';
		foreach (glob($this->views_dir.'/*', GLOB_ONLYDIR) ?: array() as $dir) {
			foreach ($this->views(basename($dir)) as $view) {
				if (preg_match($pattern, file_get_contents("$dir/$view"))) return true;
			}
		}
		return false;
	}

	function content_model($theme = null) {
		require_once BASE.'models/cms/cms.php';
		$theme = $theme ?: $this->theme;
		$pages = array();
		$collections = array();
		$site = array();
		$cms = $this->model_info('cms');
		$reserved = $cms ? $cms['methods'] : array();
		foreach ($this->views($theme) as $view) {
			if (cms::page_path($view, $this->ext) === null) continue; // partials, _email/ and feeds
			$html = $this->expand(file_get_contents($this->theme_dir($theme).'/'.$view), $theme);
			list($blocks) = self::blocks($html);
			$fields = array();
			$this->collect_content($blocks, $fields, $collections, $view, $reserved);
			// site_* fields are shared by all pages
			foreach ($fields as $name => $field) {
				if (strpos($name, 'site_') !== 0) continue;
				if (!isset($site[$name])) $site[$name] = $field;
				unset($fields[$name]);
			}
			if (empty($fields) && !$this->uses_collections($blocks)) continue;
			$slug = $this->slug_for_view($view);
			$pages[] = array(
				'view' => $view,
				'url' => $this->url_for_view($view),
				'slug' => $slug,
				'type' => cms::page_type($slug),
				'fields' => $fields,
			);
		}
		// literal routes (controller::route('specials')->to('menu')) are pages too,
		// with page fields of their own, shared by the route's URLs with
		// parameters (/specials/day/friday)
		foreach ($this->routes() as $route) {
			$slug = cms::route_slug($route['pattern']);
			if ($slug === null) continue;
			// a route to a view of another theme (->from('print')) is a page
			// of this site: its fields, and its collections, are read from
			// that theme's view (#133)
			if ($route['theme'] !== $theme) {
				if ($theme === $this->theme) $this->routed_page($route, $slug, $pages, $collections, $site, $reserved);
				continue;
			}
			foreach ($pages as $page) {
				if ($page['view'] !== $route['view'].$this->ext || !$page['fields']) continue;
				$pages[] = array('view' => $page['view'], 'url' => $slug, 'slug' => $slug, 'type' => cms::page_type($slug), 'fields' => $page['fields']);
				break;
			}
		}
		// a collection's fields are typed by the mock-up the first item is
		// stored from: its own view's (collection_defaults)
		foreach ($collections as $name => $collection) {
			foreach ($this->collection_defaults($name, $theme) as $field => $default) {
				if (isset($collection['fields'][$field])) $collections[$name]['fields'][$field] = array('default' => $default, 'type' => cms_types::of_example($default));
			}
		}
		// types models declare (static function types()) are collections too;
		// their fields come from the model, not the markup
		foreach (cms_records::types() as $name => $info) {
			$views = isset($collections[$name]) ? $collections[$name]['views'] : array();
			$fields = array();
			foreach ($info['fields'] as $field => $default) $fields[$field] = array('default' => is_array($default) ? '[]' : cms_types::show($default), 'type' => $info['types'][$field]);
			$collections[$name] = array(
				'name' => $name, 'type' => $info['type'], 'fields' => $fields, 'views' => $views,
				'model' => $info['model'], 'public' => $info['public'], 'owner' => $info['owner'], 'create' => $info['create'], 'staff_add' => $info['staff_add'],
				'readonly' => $info['readonly'], 'hidden' => $info['hidden'], 'actions' => $info['actions'],
			);
		}
		ksort($collections);
		if ($site) {
			array_unshift($pages, array('view' => '*', 'url' => 'site', 'slug' => 'site', 'type' => 'sitepage', 'fields' => $site));
		}
		return array('pages' => $pages, 'collections' => $collections);
	}

	// the page a literal route makes of a view in another theme
	protected function routed_page($route, $slug, &$pages, &$collections, &$site, $reserved) {
		if (!in_array($route['theme'], $this->themes(), true)) return;
		$view = $route['view'].$this->ext;
		$file = $this->theme_dir($route['theme']).'/'.$view;
		if (controller::is_partial($view) || !is_file($file)) return;
		list($blocks) = self::blocks($this->expand(file_get_contents($file), $route['theme']));
		$fields = array();
		$this->collect_content($blocks, $fields, $collections, $route['theme'].'/'.$view, $reserved);
		foreach ($fields as $name => $field) {
			if (strpos($name, 'site_') !== 0) continue;
			if (!isset($site[$name])) $site[$name] = $field;
			unset($fields[$name]);
		}
		if (!$fields) return;
		$pages[] = array('view' => $view, 'theme' => $route['theme'], 'url' => $slug, 'slug' => $slug, 'type' => cms::page_type($slug), 'fields' => $fields);
	}

	// the fields a markup collection has in a theme's views, from the
	// content model, read once per theme
	function collection_fields($name, $theme = null) {
		$theme = $theme ?: $this->theme;
		if (!isset($this->models[$theme])) $this->models[$theme] = $this->content_model($theme);
		$collections = $this->models[$theme]['collections'];
		return isset($collections[$name]) ? $collections[$name]['fields'] : array();
	}

	// the mock-up item of a collection, taken from its own view (news.html,
	// else news_item.html): the first item stored comes from there, whichever
	// page asks for the collection first
	function collection_defaults($name, $theme = null) {
		foreach (array($name, $name.'_item') as $view) {
			$file = $this->theme_dir($theme).'/'.$view.$this->ext;
			if (!is_file($file)) continue;
			list($blocks) = self::blocks($this->expand(file_get_contents($file), $theme));
			$fields = array();
			$collections = array();
			$this->collect_content($blocks, $fields, $collections, $view.$this->ext, array());
			if (!isset($collections[$name])) continue;
			$defaults = array();
			foreach ($collections[$name]['fields'] as $key => $field) $defaults[$key] = $field['default'];
			return $defaults;
		}
		return array();
	}

	protected function uses_collections($blocks) {
		foreach ($blocks as $block) {
			if ($block['keyword'] === 'remove') continue;
			if ($block['keyword'] === 'render' && strpos($block['ref'], 'cms.') === 0) return true;
			if (!empty($block['children']) && $this->uses_collections($block['children'])) return true;
		}
		return false;
	}

	protected function collect_content($blocks, &$fields, &$collections, $view, $reserved) {
		foreach ($blocks as $block) {
			// mock-up content is removed before anything runs, as in lint_blocks
			if ($block['keyword'] === 'remove') continue;
			$attribute = $block['keyword'] === 'print' ? self::attribute_call($block['ref']) : null;
			$ref = in_array($block['keyword'], array('print', 'render')) ? self::model_reference($attribute ? $attribute['ref'] : $block['ref']) : false;
			if ($ref && $ref['model'] === 'cms') {
				$call = template::parse_call($ref['method']);
				if ($call !== false && !in_array($call[0], $reserved)) {
					if ($block['keyword'] === 'print' && !isset($fields[$call[0]])) {
						$default = $attribute ? template::get_attribute($block['inner'], $attribute['attribute']) : trim($block['inner']);
						$fields[$call[0]] = array('default' => $default, 'type' => cms_types::of_example($default));
					}
					if ($block['keyword'] === 'render') {
						$this->collect_collection($block, $call, $collections, $view);
						continue;
					}
				}
			}
			if (!empty($block['children'])) {
				$this->collect_content($block['children'], $fields, $collections, $view, $reserved);
			}
		}
	}

	protected function collect_collection($block, $call, &$collections, $view) {
		list($name, $arguments) = $call;
		if (!isset($collections[$name])) {
			$collections[$name] = array('name' => $name, 'type' => cms::collection_type($name), 'fields' => array(), 'views' => array());
		}
		$collection = &$collections[$name];
		if (!in_array($view, $collection['views'])) $collection['views'][] = $view;
		$walk = function ($blocks) use (&$walk, &$collection) {
			foreach ($blocks as $child) {
				if ($child['keyword'] === 'remove') continue;
				if ($child['keyword'] === 'print') {
					$key = raster_inspector::data_key($child['ref']);
					if (!$key['builtin'] && strpos($key['key'], '.') === false && !isset($collection['fields'][$key['key']])) {
						$default = $key['attribute'] !== null ? template::get_attribute($child['inner'], $key['attribute']) : trim($child['inner']);
						$collection['fields'][$key['key']] = array('default' => $default, 'type' => cms_types::of_example($default));
					}
				}
				// nested renders are separate collections
				if (!empty($child['children']) && $child['keyword'] !== 'render') $walk($child['children']);
			}
		};
		$walk($block['children']);
		// filters passed as arguments become fields too: render.cms.news('featured=1'),
		// render.cms.booking('stylist=?stylist&date>=today')
		if (!empty($arguments) && is_string($arguments[0])) {
			foreach (cms_store::list_options($arguments[0])['fields'] as $field => $default) {
				if (!isset($collection['fields'][$field])) $collection['fields'][$field] = array('default' => $default, 'type' => cms_types::of_example($default));
			}
		}
		unset($collection);
	}
}
