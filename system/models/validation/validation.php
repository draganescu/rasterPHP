<?php
/**
* Validation
*
* Rules come from the form's HTML and messages live in the template. The
* model only decides whether a message shows.
*
*   <!-- render.newsletter.signup -->
*   <form method="post">
*     <input type="email" name="email" required>
*     <!-- render.validation.field('email') -->
*     <p class="error">Please enter your email address.</p>
*     <!-- /render.validation.field('email') -->
*     <button>Subscribe</button>
*   </form>
*   <!-- print.validation.alert('subscribed') --><p>Thanks!</p><!-- /print.validation.alert('subscribed') -->
*   <!-- /render.newsletter.signup -->
*
* - field('email') shows its block when the email input breaks one of its
*   HTML constraints: required, type (email, url, number, date), minlength,
*   maxlength, min, max, pattern.
* - Rules HTML can't express are regions too: matches('password', 'password2'),
*   cant_be('name', 'admin'), accepted('terms'), or your own rules in
*   application/models/validation/rules/<rule>.php defining
*   validate_<rule>($value, ...$args) that returns true or false.
* - alert('name') blocks stay hidden until a model calls raise('name'), or
*   until the page is loaded with ?done=name (after util::done('name')).
*
* Blocks inside a render block are evaluated before it, so a form's model
* already knows the result of the regions inside the form. Models use:
*
*   $v = validation::get();
*   if (!$v->submitted()) return false;           // not sent: show the form
*   if (!$v->valid()) return template::instance()->form_state(); // show it again
*   ...                                            // do the work
*   util::done('subscribed');                      // redirect, show the alert
*/
class validation
{
	// true when any rule failed (kept for older models; use valid())
	public $invalid = false;
	// owner => field => failed rules
	protected $failures = array();
	protected $alerts = array();
	protected $raised = array();
	// the form of the tag being evaluated (from its method__fN name)
	protected $form_owner = null;

	static function get() {
		controller::load_model('validation');
		return controller::get_object('validation');
	}

	// the form that was posted (the model.method of its render block)
	static function posted_form() {
		if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') return null;
		return isset($_POST['raster_form']) ? (string)$_POST['raster_form'] : '';
	}

	// Was this form sent? $owner defaults to the block being rendered.
	function submitted($owner = null) {
		$posted = self::posted_form();
		if ($posted === null) return false;
		$owner = $owner === null ? template::instance()->current_call : $owner;
		if ($posted !== '') return $posted === $owner;
		// posts without the hidden field (scripts, tests, mail apps): the form
		// counts as sent when the post has one of its fields
		$forms = template::instance()->forms;
		if (!isset($forms[$owner]) || !$forms[$owner]) return true;
		return (bool)array_intersect(array_keys($forms[$owner]), array_keys($_POST));
	}

	// Checks every constraint of the form (HTML attributes plus the rule
	// regions inside it). Returns true when the post is valid.
	function valid($owner = null) {
		$owner = $owner === null ? template::instance()->current_call : $owner;
		if (!$this->submitted($owner)) return false;
		$forms = template::instance()->forms;
		$constraints = isset($forms[$owner]) ? $forms[$owner] : array();
		foreach ($constraints as $field => $rules) {
			foreach ($this->check_field($field, $rules) as $failed) {
				$this->fail($owner, $field, $failed);
			}
		}
		return empty($this->failures[$owner]);
	}

	// the fields that failed in a form, field => rules
	function errors($owner = null) {
		$owner = $owner === null ? template::instance()->current_call : $owner;
		return isset($this->failures[$owner]) ? $this->failures[$owner] : array();
	}

	protected function fail($owner, $field, $rule) {
		$this->invalid = true;
		if (!isset($this->failures[$owner][$field]) || !in_array($rule, $this->failures[$owner][$field])) {
			$this->failures[$owner][$field][] = $rule;
		}
	}

	static function value($field) {
		return isset($_POST[$field]) ? $_POST[$field] : null;
	}

	static function is_empty($value) {
		if (is_array($value)) return count(array_filter($value, function ($v) { return trim((string)$v) !== ''; })) === 0;
		return $value === null || trim((string)$value) === '';
	}

	// the rules a field breaks, from its HTML constraints
	protected function check_field($field, $rules) {
		$value = self::value($field);
		$failed = array();
		if (!empty($rules['required']) && self::is_empty($value)) return array('required');
		if (self::is_empty($value) || is_array($value)) return $failed;
		$value = trim((string)$value);
		$type = isset($rules['type']) ? $rules['type'] : 'text';
		if ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) $failed[] = 'email';
		if ($type === 'url' && !filter_var($value, FILTER_VALIDATE_URL)) $failed[] = 'url';
		if (in_array($type, array('number', 'range'))) {
			if (!is_numeric($value)) $failed[] = 'number';
			else {
				if (isset($rules['min']) && is_numeric($rules['min']) && $value < $rules['min']) $failed[] = 'min';
				if (isset($rules['max']) && is_numeric($rules['max']) && $value > $rules['max']) $failed[] = 'max';
			}
		}
		if ($type === 'date') {
			$date = DateTime::createFromFormat('Y-m-d', $value);
			if (!$date || $date->format('Y-m-d') !== $value) $failed[] = 'date';
			else {
				if (!empty($rules['min']) && $value < $rules['min']) $failed[] = 'min';
				if (!empty($rules['max']) && $value > $rules['max']) $failed[] = 'max';
			}
		}
		if (isset($rules['minlength']) && mb_strlen($value) < (int)$rules['minlength']) $failed[] = 'minlength';
		if (isset($rules['maxlength']) && mb_strlen($value) > (int)$rules['maxlength']) $failed[] = 'maxlength';
		if (!empty($rules['pattern']) && is_string($rules['pattern'])) {
			$regex = '/^(?:'.str_replace('/', '\\/', $rules['pattern']).')$/u';
			if (@preg_match($regex, $value) === 0) $failed[] = 'pattern';
		}
		return $failed;
	}

	// the owners of the form around the tag being rendered
	protected function owners() {
		if ($this->form_owner !== null) return array($this->form_owner);
		$template = template::instance();
		$call = $template->current_call;
		return isset($template->tag_owners[$call]) ? $template->tag_owners[$call] : array();
	}

	// the sent form around this tag, or null
	protected function active_owner() {
		foreach ($this->owners() as $owner) {
			if ($this->submitted($owner)) return $owner;
		}
		return null;
	}

	protected function region_active() {
		return $this->active_owner() !== null;
	}

	// shows its block when the field breaks a constraint from the HTML
	function field($name, $rule = null) {
		$owner = $this->active_owner();
		if ($owner === null) return '';
		$forms = template::instance()->forms;
		if (isset($forms[$owner][$name])) {
			$failed = $this->check_field($name, $forms[$owner][$name]);
			foreach ($failed as $f) $this->fail($owner, $name, $f);
			if ($failed && ($rule === null || in_array($rule, $failed))) {
				return array(array('message' => false, 'field' => $name, 'rule' => $failed[0]));
			}
		}
		return '';
	}

	// rules that HTML can't express, as regions
	function matches($field, $other) {
		return $this->region(self::value($field) === self::value($other), $field, 'matches');
	}

	function cant_be($field, $forbidden) {
		return $this->region(self::is_empty(self::value($field)) || trim((string)self::value($field)) !== (string)$forbidden, $field, 'cant_be');
	}

	function accepted($field) {
		return $this->region(!self::is_empty(self::value($field)), $field, 'accepted');
	}

	// the names used by older Raster sites
	function not_empty($field) { return $this->region(!self::is_empty(self::value($field)), $field, 'required'); }
	function email_format($field) { $v = self::value($field); return $this->region(self::is_empty($v) || filter_var(trim((string)$v), FILTER_VALIDATE_EMAIL), $field, 'email'); }
	function are_the_same($a, $b) { return $this->matches($a, $b); }

	// application rules: application/models/validation/rules/<rule>.php
	function __call($rule, $args) {
		// tags inside form N arrive as rule__fN
		if (preg_match('/^([a-z][a-z0-9_]*)__f(\d+)$/', $rule, $m)) {
			$owners = template::instance()->form_owners;
			$previous = $this->form_owner;
			$this->form_owner = isset($owners[(int)$m[2]]) ? $owners[(int)$m[2]] : '';
			try {
				return call_user_func_array(array($this, $m[1]), $args);
			} finally {
				$this->form_owner = $previous;
			}
		}
		if (!preg_match('/^[a-z][a-z0-9_]*$/', $rule)) return '';
		$function = 'validate_'.$rule;
		if (!function_exists($function)) {
			$file = APPBASE.config::get('models_path', 'models').'/validation/rules/'.$rule.'.php';
			if (!is_file($file)) throw new RuntimeException("Unknown validation rule '$rule'; create $file with function $function(\$value, ...)");
			require_once $file;
		}
		if (!$this->region_active()) return '';
		$field = array_shift($args);
		array_unshift($args, self::value($field));
		return $this->region((bool)call_user_func_array($function, $args), $field, $rule);
	}

	protected function region($passed, $field, $rule) {
		$owner = $this->active_owner();
		if ($owner === null || $passed) return '';
		$this->fail($owner, $field, $rule);
		return array(array('message' => false, 'field' => $field, 'rule' => $rule));
	}

	// ##Alerts
	// <!-- print.validation.alert('name') -->message<!-- /print.validation.alert('name') -->
	// is hidden until raise('name') or ?done=name. It works wherever the
	// block is in the page, before or after the model that raises it.
	function alert($name) {
		$this->alerts[$name] = template::get('current_block');
		return '<!--raster-alert:'.$name.'-->';
	}

	function raise($name) {
		$this->raised[$name] = true;
		return '';
	}

	// bound to before_output
	function finalize() {
		if (empty($this->alerts)) return true;
		$template = template::instance();
		$done = util::get('done');
		foreach ($this->alerts as $name => $html) {
			$show = isset($this->raised[$name]) || $done === $name;
			$template->output = str_replace('<!--raster-alert:'.$name.'-->', $show ? $html : '', $template->output);
		}
		return true;
	}
}
