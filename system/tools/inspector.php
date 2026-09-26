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

	public $theme;
	public $views_dir;
	public $ext;

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
	static function tokenize($html) {
		$tokens = array();
		preg_match_all('/<!--(.*?)-->/s', $html, $matches, PREG_OFFSET_CAPTURE);
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
							// how it would be written with the keyword it meant,
							// so `lint --fix` repairs the spelling and the spacing at once
							$token['ref'] = $m[3];
							$name = $known.'.'.$m[3];
							$token['canonical'] = $m[1] === '/' ? '<!-- /'.$name.' -->'
								: ((isset($m[4]) && $m[4] === '/') ? '<!-- '.$name.' /-->' : '<!-- '.$name.' -->');
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
			$token['canonical'] = $canonical;
			$token['exact'] = ($raw === $canonical);
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
				$problems[] = self::problem('warning', $token, "Unknown directive '{$token['keyword']}' in {$token['raw']}; did you mean '{$token['suggestion']}'?",
					self::edit($token, $token['canonical']));
				continue;
			}
			if (!$token['exact']) {
				$problems[] = self::problem('error', $token, "Directive written as {$token['raw']} is ignored by the template engine; write it exactly as {$token['canonical']}",
					self::edit($token, $token['canonical']));
				continue;
			}
			// an opening needs its name; a closing may leave it out (see
			// template::closes), and remove has no name at all
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
			if (!self::pairs($last, $token)) {
				$opened_at = null;
				foreach (array_reverse($open) as $candidate) {
					if (self::pairs($candidate, $token)) { $opened_at = $candidate; break; }
				}
				if ($opened_at === null) {
					$problems[] = self::problem('error', $token, "{$token['raw']} closes a block that was never opened");
					continue;
				}
				$problems[] = self::problem('error', $token, "{$token['raw']} closes before <!-- {$last['name']} --> (line {$last['line']}); blocks must nest, close the inner block first");
				// recover by closing everything up to the matching opening
				while (!empty($open) && !self::pairs(end($open), $token)) {
					array_pop($open);
					array_pop($stack);
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

	// Whether a closing token closes an opening one. The rule itself is
	// template::closes, so lint accepts exactly what the engine renders.
	static function pairs($open, $close) {
		return template::closes($open['keyword'], $open['ref'], $close['keyword'], $close['ref']);
	}

	// A partial, read the way the engine reads it when drying: res fragments
	// are found by name, so a short closing tag is written out in full first.
	// Positions in this text are not the file's, so it is only used to look
	// fragments up, never to report a problem or to fix one.
	static function fragments_of($file) {
		return template::expand_closings(file_get_contents($file));
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
		if (!preg_match('/^([a-z0-9_\-]+)\.(.+)$/', $ref, $m)) return false;
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
		if (preg_match('/^([@+])([a-zA-Z0-9_\-:]+)\.(raster_filter(@[a-z0-9_]+)+)$/', $ref, $m)) {
			return array('key' => $m[3], 'attribute' => $m[2], 'append' => $m[1] === '+', 'builtin' => true);
		}
		if (preg_match('/^([@+])([a-zA-Z0-9_\-:]+)\.([A-Za-z0-9_\-]+)$/', $ref, $m)) {
			return array('key' => $m[3], 'attribute' => $m[2], 'append' => $m[1] === '+', 'builtin' => in_array($m[3], self::builtin_keys()));
		}
		return array('key' => $ref, 'attribute' => null, 'append' => false, 'builtin' => in_array($ref, self::builtin_keys()));
	}

	// ##Models
	// Finds the file that would be loaded for a model, the same way
	// controller::load_model does, and lists its public methods.
	function model_info($model) {
		static $cache = array();
		if (isset($cache[$model])) return $cache[$model];
		$models_path = config::get('models_path', 'models');
		$candidates = array(
			APPBASE.$models_path.'/'.$model.'/'.$model.'.php',
			APPBASE.$models_path.'/the_'.$model.'/the_'.$model.'.php',
			BASE.$models_path.'/'.$model.'/'.$model.'.php',
		);
		$info = false;
		foreach ($candidates as $file) {
			if (!file_exists($file)) continue;
			$info = array('file' => $file, 'methods' => array(), 'magic' => false, 'class' => false);
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
							elseif ($visibility === 'public') $info['methods'][] = $name;
							break;
						}
						if ($tokens[$j] === '(') break;
					}
				}
			}
			// an override (the_feed extends feed) has its parent's methods too
			if (strpos(basename(dirname($file)), 'the_') === 0) {
				$parent = BASE.$models_path.'/'.$model.'/'.$model.'.php';
				if (file_exists($parent)) {
					$base_info = $this->parent_info($parent);
					$info['methods'] = array_values(array_unique(array_merge($info['methods'], $base_info['methods'])));
					$info['magic'] = $info['magic'] || $base_info['magic'];
				}
			}
			break;
		}
		return $cache[$model] = $info;
	}

	// methods of a system model file, for overrides
	protected function parent_info($file) {
		$info = array('methods' => array(), 'magic' => false);
		$tokens = token_get_all(file_get_contents($file));
		foreach ($tokens as $i => $t) {
			if (!is_array($t) || $t[0] !== T_FUNCTION) continue;
			for ($j = $i + 1; $j < count($tokens); $j++) {
				if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
					if ($tokens[$j][1] === '__call') $info['magic'] = true; else $info['methods'][] = $tokens[$j][1];
					break;
				}
				if ($tokens[$j] === '(') break;
			}
		}
		return $info;
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

	function fix_path($path, $theme = null) {
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
		$theme = $theme ?: $this->theme;
		$html = file_get_contents($path);
		list($blocks, $problems) = self::blocks($html);
		$this->lint_blocks($blocks, $problems, null, $theme);
		$this->lint_forms($html, $problems, $theme);
		usort($problems, function ($a, $b) { return $a['line'] <=> $b['line'] ?: $a['column'] <=> $b['column']; });
		foreach ($problems as &$problem) {
			$problem['file'] = self::short($path);
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
		return preg_match('/^([@+])([a-zA-Z0-9_\-:]+)\.([a-z0-9_\-]+\..+)$/', $ref, $m) ? array('attribute' => $m[2], 'append' => $m[1] === '+', 'ref' => $m[3]) : null;
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
		if (!preg_match('/^[a-z0-9_\-]+$/', $ref['model'])) {
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
			$problems[] = self::problem('error', $block, "Model '{$ref['model']}' has no public method '$method' (".self::short($info['file']).")");
			return;
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
		if (!preg_match('/^([a-z0-9_\-\/]+)\.([a-z0-9_\-]+)$/', $block['ref'], $m)) {
			$problems[] = self::problem('error', $block, "dry takes a view and a res block: <!-- dry.layout.header /-->");
			return;
		}
		$file = $this->theme_dir($theme).'/'.$m[1].$this->ext;
		if (!file_exists($file)) {
			$problems[] = self::problem('error', $block, "{$block['raw']} reads from ".self::short($file)." which does not exist");
			return;
		}
		$source = self::fragments_of($file);
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
		static $names = null;
		if ($names !== null) return $names;
		$names = array();
		foreach (array(BASE.'models', APPBASE.config::get('models_path', 'models')) as $dir) {
			if (!is_dir($dir)) continue;
			foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
				if (substr($file, -4) !== '.php') continue;
				preg_match_all('/(?:raise|done)\(\s*[\'"]([a-z0-9_]+)[\'"]/', file_get_contents($file), $m);
				$names = array_merge($names, $m[1]);
			}
		}
		return $names = array_unique($names);
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
				unset($rules['type']);
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
		// models that send emails need their email views
		$emails = array('render.authentication.forgot' => '_email/password_reset', 'render.newsletter.signup' => '_email/newsletter_confirm');
		foreach ($emails as $tag => $view) {
			$at = strpos($html, '<!-- '.$tag.' -->');
			if ($at === false) continue;
			if (!file_exists($this->theme_dir($theme).'/'.$view.$this->ext)) {
				$problems[] = self::problem_at('error', $html, $at, "$tag sends an email written in ".self::short($this->theme_dir($theme).'/'.$view.$this->ext).", which does not exist");
			}
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
	// listens() in its models. array(event, model, method, file, line)
	function listeners() {
		$found = array();
		$file = APPBASE.'config/the_events.php';
		if (file_exists($file)) {
			foreach (explode("\n", $this->without_comments(file_get_contents($file))) as $n => $line) {
				if (!preg_match_all('/event::bind\(\s*([\'"])(.+?)\1\s*\)\s*->\s*to\(\s*([\'"])(.+?)\3\s*,\s*([\'"])(.+?)\5\s*\)/', $line, $matches, PREG_SET_ORDER)) continue;
				foreach ($matches as $m) $found[] = array('event' => $m[2], 'model' => $m[4], 'method' => $m[6], 'file' => self::short($file), 'line' => $n + 1);
			}
		}
		foreach (glob(APPBASE.config::get('models_path', 'models').'/*', GLOB_ONLYDIR) ?: array() as $dir) {
			$folder = basename($dir);
			$model_file = "$dir/$folder.php";
			if (!is_file($model_file)) continue;
			$source = file_get_contents($model_file);
			$at = strpos($source, 'function listens(');
			if ($at === false) continue;
			if (!class_exists($folder)) require_once $model_file;
			if (!method_exists($folder, 'listens')) continue;
			$line = substr_count(substr($source, 0, $at), "\n") + 1;
			$model = strpos($folder, 'the_') === 0 ? substr($folder, 4) : $folder;
			foreach ((array)call_user_func(array($folder, 'listens')) as $event => $methods) {
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
				if (preg_match_all('/event::dispatch\(\s*([\'"])([a-z0-9_.]+)\1/', file_get_contents($file->getPathname()), $m)) {
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
	// models/cafe/sql/count_category.sql or $queries['count_category'] in
	// models/sql.php. Without a model name, the calling model's folder counts.
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
				if (($model && is_file("$models/$model/sql/$name.sql")) || isset($named[$name])) continue;
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

	function lint($themes = null) {
		$problems = array();
		$themes = $themes ?: array($this->theme);
		foreach ($themes as $theme) {
			foreach ($this->views($theme) as $view) {
				$problems = array_merge($problems, $this->lint_file($view, $theme));
			}
		}
		return array_merge($problems, $this->lint_routes(), $this->lint_events(), $this->lint_queries());
	}

	static function short($path) {
		$root = dirname(APPBASE).'/';
		return strpos($path, $root) === 0 ? substr($path, strlen($root)) : $path;
	}

	// ##Content model
	// Expands dry includes the same way the engine does, so CMS tags that
	// live in shared layouts are counted for every page that uses them.
	function expand($html, $theme = null, $depth = 0) {
		if ($depth > 5) return $html;
		$self = $this;
		return preg_replace_callback('/<!-- dry\.([a-z0-9_\-\/]+)\.([a-z0-9_\-]+) (\/?)-->(?:(.*?)<!-- \/dry\.\1\.\2 -->)?/s', function ($m) use ($self, $theme, $depth) {
			$file = $self->theme_dir($theme).'/'.$m[1].$self->ext;
			if (!file_exists($file)) return '';
			$source = self::fragments_of($file);
			$start = "<!-- res.{$m[2]} -->"; $end = "<!-- /res.{$m[2]} -->";
			$a = strpos($source, $start); $b = strpos($source, $end);
			if ($a === false || $b === false) return '';
			return $self->expand(substr($source, $a + strlen($start), $b - $a - strlen($start)), $theme, $depth + 1);
		}, $html);
	}

	// The URL path the CMS uses to identify the page a view renders
	function slug_for_view($view) {
		$name = substr($view, 0, -strlen($this->ext));
		if ($name === config::get('default_view', 'index')) return 'home';
		if (preg_match('/^(?:(.*)\/)?([a-z0-9_]+)_item$/', $name, $m)) {
			return '/'.$m[2].'/'.$m[2].'_item';
		}
		return '/'.$name;
	}

	function url_for_view($view) {
		$slug = $this->slug_for_view($view);
		if ($slug === 'home') return '/';
		if (preg_match('#^/([a-z0-9_]+)/\1_item$#', $slug, $m)) return '/'.$m[1].'/'.$m[1].'_item/{id}';
		return $slug;
	}

	// Describes the CMS content of the theme: page fields per view and
	// collections with their fields. Defaults are the placeholder content
	// in the markup, which is what the CMS stores on first render.
	function content_model($theme = null) {
		require_once BASE.'models/cms/cms.php';
		$theme = $theme ?: $this->theme;
		$pages = array();
		$collections = array();
		$site = array();
		$cms = $this->model_info('cms');
		$reserved = $cms ? $cms['methods'] : array();
		foreach ($this->views($theme) as $view) {
			if (preg_match('#(^|/)_#', $view)) continue; // partials and _email/
			if (substr($view, -strlen($this->ext)) !== $this->ext) continue; // feeds
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
		// literal routes (controller::route('specials')->to('menu')) are pages too:
		// each URL keeps its own page fields
		foreach ($this->routes() as $route) {
			if (!preg_match('#^[a-z0-9_\-/]+$#', $route['pattern']) || $route['theme'] !== $theme) continue;
			foreach ($pages as $page) {
				if ($page['view'] !== $route['view'].$this->ext || !$page['fields']) continue;
				$slug = '/'.trim($route['pattern'], '/');
				$pages[] = array('view' => $page['view'], 'url' => $slug, 'slug' => $slug, 'type' => cms::page_type($slug), 'fields' => $page['fields']);
				break;
			}
		}
		ksort($collections);
		if ($site) {
			array_unshift($pages, array('view' => '*', 'url' => 'site', 'slug' => 'site', 'type' => 'sitepage', 'fields' => $site));
		}
		return array('pages' => $pages, 'collections' => $collections);
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
			if ($block['keyword'] === 'render' && strpos($block['ref'], 'cms.') === 0) return true;
			if (!empty($block['children']) && $this->uses_collections($block['children'])) return true;
		}
		return false;
	}

	protected function collect_content($blocks, &$fields, &$collections, $view, $reserved) {
		foreach ($blocks as $block) {
			$attribute = $block['keyword'] === 'print' ? self::attribute_call($block['ref']) : null;
			$ref = in_array($block['keyword'], array('print', 'render')) ? self::model_reference($attribute ? $attribute['ref'] : $block['ref']) : false;
			if ($ref && $ref['model'] === 'cms') {
				$call = template::parse_call($ref['method']);
				if ($call !== false && !in_array($call[0], $reserved)) {
					if ($block['keyword'] === 'print' && !isset($fields[$call[0]])) {
						$fields[$call[0]] = array('default' => $attribute ? template::get_attribute($block['inner'], $attribute['attribute']) : trim($block['inner']));
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
				if ($child['keyword'] === 'print') {
					$key = raster_inspector::data_key($child['ref']);
					if (!$key['builtin'] && strpos($key['key'], '.') === false && !isset($collection['fields'][$key['key']])) {
						if ($key['attribute'] !== null) {
							preg_match('/\s'.preg_quote($key['attribute'], '/').'\s*=\s*([\'"])(.*?)\1/is', $child['inner'], $m);
							$default = isset($m[2]) ? $m[2] : '';
						} else {
							$default = trim($child['inner']);
						}
						$collection['fields'][$key['key']] = array('default' => $default);
					}
				}
				// nested renders are separate collections
				if (!empty($child['children']) && $child['keyword'] !== 'render') $walk($child['children']);
			}
		};
		$walk($block['children']);
		// filters passed as arguments become fields too: render.cms.news('featured=1')
		if (!empty($arguments) && is_string($arguments[0])) {
			foreach (explode('&', $arguments[0]) as $pair) {
				$parts = explode('=', $pair, 2);
				if ($parts[0] !== '' && !in_array($parts[0], array('order', 'limit')) && !isset($collection['fields'][$parts[0]])) {
					$collection['fields'][$parts[0]] = array('default' => isset($parts[1]) ? $parts[1] : '');
				}
			}
		}
		unset($collection);
	}
}
