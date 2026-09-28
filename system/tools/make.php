<?php
// #Make
//
// `raster make model <name> --from=<view>` writes a model for the records a
// form sends: the type (its fields read from the form's inputs), an empty
// check() for the rules and the form handler. The file is the site's own
// from then on; nothing depends on it staying as generated.
class raster_make {

	// names PHP keeps: a class can't be called any of these
	static $php_words = array('abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const', 'continue', 'declare', 'default', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include', 'instanceof', 'insteadof', 'interface', 'isset', 'list', 'match', 'namespace', 'new', 'or', 'print', 'private', 'protected', 'public', 'readonly', 'require', 'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield', 'int', 'float', 'bool', 'string', 'true', 'false', 'null', 'void', 'iterable', 'object', 'mixed', 'never', 'parent', 'self');

	public $inspector;

	function __construct($inspector = null) {
		$this->inspector = $inspector ?: new raster_inspector();
	}

	// the file's contents and what to do next; nothing is written
	function model($name, $view, $method = null) {
		if (!preg_match('/^[a-z][a-z0-9]*$/', (string)$name)) {
			throw new InvalidArgumentException("A model name is lowercase letters and digits, starting with a letter (not '$name')");
		}
		if (cms::reserved($name, 'collection')) throw new InvalidArgumentException("'$name' is a name the CMS keeps for itself");
		// a PHP word, or a class Raster or the site already has, would break
		// the site or quietly replace the bundled model
		$paths = controller::build_model_paths($name);
		if (in_array($name, self::$php_words, true) || file_exists($paths['system_path']) || (class_exists($name, false) && !file_exists($paths['app_path'])) || in_array($name, array('boot', 'config', 'controller', 'database', 'event', 'log', 'template', 'util', 'private_paths', 'raster_cache', 'records'), true)) {
			throw new InvalidArgumentException("'$name' is taken (by PHP or Raster); pick another name");
		}
		if ($method !== null && (!preg_match('/^[a-z][a-z0-9_]*$/', $method) || in_array($method, cms_records::$hooks, true) || in_array($method, self::$php_words, true))) {
			throw new InvalidArgumentException("'$method' can't be the form's method");
		}
		$view = ltrim((string)$view, '/');
		if ($view !== '' && substr($view, -strlen($this->inspector->ext)) !== $this->inspector->ext) $view .= $this->inspector->ext;
		$path = $this->inspector->theme_dir().'/'.$view;
		if ($view === '' || !is_file($path)) throw new InvalidArgumentException("There is no view '$view' in theme '{$this->inspector->theme}'");

		$form = $this->find_form(file_get_contents($path), $name, $method);
		$fields = array();
		foreach (template::instance()->constraints($form['html']) as $field => $rule) {
			// passwords are never stored as records, nor fields that only repeat another
			if ($rule['type'] === 'password' || preg_match('/_(again|confirm|confirmation)$/', $field)) continue;
			$fields[$field] = in_array($rule['type'], array('number', 'range')) ? 0 : '';
		}
		if (!$fields) throw new InvalidArgumentException("The form in $view has no fields to store");
		$method = $form['method'];
		if (in_array($method, cms_records::$hooks, true) || in_array($method, self::$php_words, true)) {
			throw new InvalidArgumentException("The form's method can't be called '$method'; rename it in the view");
		}
		$done = $name.'_sent';
		$lines = array();
		foreach ($fields as $field => $default) $lines[] = "\t\t\t\t".var_export($field, true).' => '.var_export($default, true).',';

		$code = "<?php\n"
			."// Made by `raster make model $name --from=$view`: the $name records the\n"
			."// form in $view sends. Every part of this file is yours to change.\n"
			."class $name\n{\n"
			."\t// what one $name record holds. The CMS stores them, shows them wherever a view\n"
			."\t// renders <!-- render.cms.$name -->, and lets editors and agents change them.\n"
			."\tstatic function types() {\n"
			."\t\treturn array('$name' => array(\n"
			."\t\t\t'fields' => array(\n".implode("\n", $lines)."\n\t\t\t),\n"
			."\t\t\t// who may send the form: visitor, member or editor\n"
			."\t\t\t'create' => 'visitor',\n"
			."\t\t\t// fields only this model sets, shown to editors but not editable\n"
			."\t\t\t'readonly' => array(),\n"
			."\t\t\t// 'confirm' => 'editor' adds a Confirm button for editors, run by\n"
			."\t\t\t// static function confirm(\$item, \$input) below\n"
			."\t\t\t'actions' => array(),\n"
			."\t\t));\n"
			."\t}\n\n"
			."\t// Every write passes here, from the form, the page editor, MCP or your\n"
			."\t// own code. \$after is the record as it would be stored (null when it\n"
			."\t// is deleted), \$before as it was (null when it is new). Return the names\n"
			."\t// of what is wrong: each is an alert the template words,\n"
			."\t// <!-- print.validation.alert('fully_booked') -->.\n"
			."\tstatic function check(\$type, \$after, \$before) {\n"
			."\t\t\$problems = array();\n"
			."\t\t// if (…) \$problems[] = 'fully_booked';\n"
			."\t\treturn \$problems;\n"
			."\t}\n\n"
			."\t// the form in $view: <!-- render.$name.$method -->\n"
			."\tfunction $method() {\n"
			."\t\treturn cms_records::submit('$name', '$done');\n"
			."\t}\n"
			."}\n";

		$next = array();
		if (!$form['owned']) $next[] = "Wrap the form in $view in <!-- render.$name.$method --> … <!-- /render.$name.$method -->";
		$next[] = "Thank the visitor in $view: <!-- print.validation.alert('$done') --><p>Thanks, we got it.</p><!-- /print.validation.alert('$done') -->";
		$next[] = "List the records on a page only editors can open: <!-- render.cms.$name('order=newest') --> … <!-- /render.cms.$name('order=newest') -->";
		$next[] = 'php bin/raster lint';
		return array(
			'file' => APPBASE.config::get('models_path', 'models')."/$name/$name.php",
			'fields' => array_keys($fields),
			'method' => $method,
			'code' => $code,
			'next' => $next,
		);
	}

	// The form the model is for: the one inside <!-- render.<name>.x -->, else
	// the only form in the view that no model handles yet
	protected function find_form($html, $name, $method) {
		list($blocks) = raster_inspector::blocks($this->inspector->expand($html));
		$owned = array();
		$walk = function ($blocks) use (&$walk, &$owned) {
			foreach ($blocks as $block) {
				if ($block['keyword'] === 'render' && stripos($block['inner'], '<form') !== false) {
					$ref = raster_inspector::model_reference($block['ref']);
					if ($ref) $owned[] = array('model' => $ref['model'], 'method' => template::parse_call($ref['method'])[0], 'html' => $block['inner']);
				}
				if (!empty($block['children'])) $walk($block['children']);
			}
		};
		$walk($blocks);
		foreach ($owned as $form) {
			if ($form['model'] === $name && ($method === null || $form['method'] === $method)) return $form + array('owned' => true);
		}
		preg_match_all('#<form\b.*?</form>#is', $html, $forms);
		$free = array();
		foreach ($forms[0] as $form) {
			$taken = false;
			foreach ($owned as $o) if (strpos($o['html'], $form) !== false) $taken = true;
			if (!$taken) $free[] = $form;
		}
		if (count($free) === 1) return array('model' => $name, 'method' => $method ?: 'send', 'html' => $free[0], 'owned' => false);
		if (!$free) throw new InvalidArgumentException("No form in the view is free for '$name': wrap the one you mean in <!-- render.$name.<method> -->");
		throw new InvalidArgumentException(count($free)." forms in the view; wrap the one you mean in <!-- render.$name.<method> --> first");
	}
}
