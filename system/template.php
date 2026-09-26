<?php

// Raster's most notable feature is this templating class that parses html
// comments and makes possible the MVC pull system by exposing template injection
// methods for the data provided by the models. OMG thats a twisted way to put it.
class template {
	// this is just singleton boilerplate :)
	private static $instances = array();
  protected function __construct() {}
  protected function __clone() {}
  

  public $replace = array();
  public $template_data = '';

  // 
  public $current_config_setting = '';
  
  public $current_action = '';
  public $models = array();
  public $models_methods_render = array();
  public $models_methods_print = array();
  public $tpl_uri = 'su';
  
  public $views_path = '';
  public $theme = '';
  public $view_ext = '';
  public $base_uri = '';
  public $link_uri = '';
  
  public static $model = '';
  public $render_results = array();
  public $current_block = '';
  public $pad_uri = "";
  public $output = '';
  public $base_tag = '';
  public $current_params = array();
  // the view file being rendered, used in error messages
  public $view_file = '';
  // html, xml, json or txt: decides how printed values are escaped
  public $format = 'html';
  // values set with template::set('name')->to(...) for print.self and print.if
  public $vars = array();
  // post forms found in the view: owner (model.method of the render block
  // around the form) => field constraints read from the HTML
  public $forms = array();
  // for validation tags: tag reference => owners of the forms they sit in
  public $tag_owners = array();
  // form number => owner; validation tags inside form N are renamed
  // method__fN so identical tags in two forms stay apart
  public $form_owners = array();
  // the model.method of the block being processed
  public $current_call = '';
  // emails get absolute links and no <base> or scripts
  public $is_email = false;
  // ##Editor marks
  // For logged in editors the CMS marks what it prints: page fields,
  // collections, their items and item fields. Each mark is a pair of
  // comments (<!--raster:s 3--> … <!--raster:e 3-->), or one comment right
  // before a tag whose attribute holds the value (<!--raster:a 4-->). The
  // details (which field of which page or item) are in $marks, sent to the
  // editor script. null when nobody is editing.
  public $marks = null;
  // the attribute a print sets: print.@src.cms.photo
  public $current_attr = null;
  // a mark the model asked for, for the attribute print being processed
  public $pending_mark = null;

  function mark($info) {
    $this->marks[count($this->marks) + 1] = $info;
    return count($this->marks);
  }

  // sets (or with $append adds to) an attribute of the first tag in $html
  static function set_attribute($html, $attribute, $value, $append = false) {
    $value = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8', false);
    if (!preg_match('/<[a-zA-Z][^>]*>/s', $html, $tag, PREG_OFFSET_CAPTURE)) return $html;
    $open = $tag[0][0];
    $pattern = '/(\s'.preg_quote($attribute, '/').'\s*=\s*)(["\'])(.*?)\2/is';
    if (preg_match($pattern, $open)) {
      $changed = preg_replace_callback($pattern, function ($m) use ($value, $append) {
        return $m[1].'"'.($append ? trim($m[3].' '.$value) : $value).'"';
      }, $open, 1);
    } else {
      $changed = preg_replace('/\s*(\/?)>$/', ' '.$attribute.'="'.$value.'"$1>', $open, 1);
    }
    return substr_replace($html, $changed, $tag[0][1], strlen($open));
  }

  // the value of an attribute of the first tag in $html, or ''
  static function get_attribute($html, $attribute) {
    if (!preg_match('/<[a-zA-Z][^>]*>/s', $html, $tag)) return '';
    return preg_match('/\s'.preg_quote($attribute, '/').'\s*=\s*(["\'])(.*?)\1/is', $tag[0], $m) ? html_entity_decode($m[2], ENT_QUOTES, 'UTF-8') : '';
  }

  public function __set($name, $value) { $this->vars[$name] = $value; }
  public function __get($name) { return isset($this->vars[$name]) ? $this->vars[$name] : null; }
  public function __isset($name) { return isset($this->vars[$name]); }

  // Replaces the template singleton, used to render a second view (an email)
  // in the middle of a request. Returns the previous instance.
  static function swap($instance = null) {
  	$cls = class_exists('the_template') ? 'the_template' : 'template';
  	$previous = isset(self::$instances[$cls]) ? self::$instances[$cls] : null;
  	if ($instance === null) unset(self::$instances[$cls]);
  	else self::$instances[$cls] = $instance;
  	return $previous;
  }

  // Escapes a printed value for the view's format. HTML views print values
  // as they are (CMS content is HTML); feeds and JSON views are escaped.
  function escape($value) {
  	$value = (string)$value;
  	switch ($this->format) {
  		case 'xml': return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
  		case 'json': return substr(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1);
  		default: return $value;
  	}
  }

  // Parses a method reference as written in a template tag:
  //   latest            -> array('latest', array())
  //   latest(3, 'news') -> array('latest', array(3, 'news'))
  // Only literals are allowed as arguments: numbers, quoted strings, true,
  // false and null. Returns false when the reference is malformed.
  static function parse_call($reference) {
  	$reference = trim($reference);
  	if (!preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*(?:\((.*)\))?$/s', $reference, $m)) {
  		return false;
  	}
  	$arguments = array();
  	if (isset($m[2]) && trim($m[2]) !== '') {
  		$tokens = token_get_all('<?php '.$m[2].';');
  		array_shift($tokens);
  		array_pop($tokens);
  		$expect_value = true;
  		$negative = false;
  		foreach ($tokens as $token) {
  			if (is_array($token) && $token[0] === T_WHITESPACE) continue;
  			if ($expect_value) {
  				if ($token === '-') {
  					if ($negative) return false;
  					$negative = true;
  					continue;
  				}
  				if (!is_array($token)) return false;
  				switch ($token[0]) {
  					case T_CONSTANT_ENCAPSED_STRING:
  						$quote = $token[1][0];
  						$inner = substr($token[1], 1, -1);
  						$arguments[] = $quote === "'" ? str_replace(array("\\'", '\\\\'), array("'", '\\'), $inner) : stripcslashes($inner);
  						break;
  					case T_LNUMBER:
  						$arguments[] = $negative ? -(int)$token[1] : (int)$token[1];
  						break;
  					case T_DNUMBER:
  						$arguments[] = $negative ? -(float)$token[1] : (float)$token[1];
  						break;
  					case T_STRING:
  						$word = strtolower($token[1]);
  						if ($word === 'true') $arguments[] = true;
  						elseif ($word === 'false') $arguments[] = false;
  						elseif ($word === 'null') $arguments[] = null;
  						else return false;
  						break;
  					default:
  						return false;
  				}
  				if ($negative && !in_array($token[0], array(T_LNUMBER, T_DNUMBER))) return false;
  				$negative = false;
  				$expect_value = false;
  			} else {
  				if ($token !== ',') return false;
  				$expect_value = true;
  			}
  		}
  		if ($expect_value) return false;
  	}
  	return array($m[1], $arguments);
  }

  // Raised when a template can't be rendered, for example when a block is
  // never closed. Run `php bin/raster lint` to see every problem at once.
  function fail($message) {
  	throw new RuntimeException($message.($this->view_file ? ' in '.$this->view_file : '').'. Run `php bin/raster lint` for details.');
  }
    
  // set data to be replaced in all templates
	function replace($what, $with, $where = ".*")
	{
		$this->replace[$where][] = array($what,$with);
	}
    
  static function parse($data) {
    	
    	$template = template::instance();
    	$template->template_data = $data;
    	
		foreach ($template->replace as $where => $replacements) {
			if(preg_match("%".$where."%", config::get('uri_string')))
			{
				foreach ($replacements as $value) {
					$template->template_data = str_replace($value[0], $value[1], $template->template_data);
				}
			}
		}
		
		$template->output = $template->template_data;
		$template->dry_template();
		$template->output = str_replace(array('/*-', '-*/'), array('<!--', '-->'), $template->output);
		
		
		$template->base_tag = $template->base_uri.$template->views_path.'/'.$template->theme.'/';
		
		$script = "<script>var BASE = ".json_encode((string)$template->link_uri, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES)."</script>";
		if(stripos($template->output,'<base') === false)
				$base = "<base href='".htmlspecialchars($template->base_tag, ENT_QUOTES)."' />\n".$script;
		else
			$base = $script;
		
		if ($template->format === 'html' && !$template->is_email) {
			$template->output = str_replace('<head>', "<head>\n".$base, $template->output);
		}
		
		// mock-up content goes first, so models inside it are never called
		$template->remove();
		$template->index_forms();

		$res = preg_match_all('/<!-- ((print|render)\.(([a-z,_,-,0-9]*)\.(.*?))) (\/?)-->/', $template->output, $methodstarts);
		$template->models = array_unique($methodstarts[4]);

		foreach ($methodstarts[2] as $k=>$v) {
			if($v == 'render')
				$template->models_methods_render[] = array($methodstarts[4][$k],$methodstarts[5][$k]);
			if($v == 'print')
				$template->models_methods_print[] = array($methodstarts[4][$k],$methodstarts[5][$k]);
		}
		// a model value in an attribute: <!-- print.@src.cms.photo --><img src="a.jpg"><!-- /print.@src.cms.photo -->
		preg_match_all('/<!-- print\.([@+][a-zA-Z0-9_\-:]+)\.([a-z0-9_\-]+)\.([^\s]+?) -->/', $template->output, $attrstarts);
		foreach ($attrstarts[0] as $k => $v) {
			$template->models[] = $attrstarts[2][$k];
			$template->models_methods_print[] = array($attrstarts[2][$k], $attrstarts[3][$k], $attrstarts[1][$k]);
		}
		$template->models = array_values(array_unique($template->models));

		$template->models_methods_render = array_reverse($template->models_methods_render);
		$template->models_methods_print = array_reverse($template->models_methods_print);
		
		return $template;
		
  }

  public function set_current_block($model, $method, $action, $attr = null) {
  	self::$model = $model;
  	$this->current_call = $model.'.'.$method;
  	$this->current_attr = null;
  	$this->pending_mark = null;
  	$this->current_params = array('pos1' => false, 'pos2' => 0, 'render_template' => '', 'datastarts' => array(array(), array(), array()));
  	if ($action == 'print' && $attr !== null) {
  		$this->current_action = 'print';
  		$this->current_attr = $attr;
  		$start = "<!-- print.$attr.$model.$method -->";
  		$end = "<!-- /print.$attr.$model.$method -->";
  		$pos1 = strpos($this->output, $start);
  		if ($pos1 === false) return false;
  		$endpos = strpos($this->output, $end, $pos1);
  		if ($endpos === false) $this->fail("Unclosed $start (expected $end)");
  		$inner = substr($this->output, $pos1 + strlen($start), $endpos - $pos1 - strlen($start));
  		// the model sees the attribute's value as the default
  		$this->current_block = self::get_attribute($inner, substr($attr, 1));
  		$this->current_params['render_template'] = $inner;
  		$this->current_params['pos1'] = $pos1;
  		$this->current_params['pos2'] = $endpos - $pos1 + strlen($end);
  		return;
  	}
  	if ($action == 'print') {
  		$this->current_action = 'print';
  		$isalt = false;
			$start = "<!-- print.$model.$method -->";
			$end = "<!-- /print.$model.$method -->";
			$alt = "<!-- print.$model.$method /-->";
			$pos1 = strpos($this->output, $start);
			if($pos1 === false)
			{
				$start = $alt;
				$end = $alt;
				$pos1 = strpos($this->output, $alt);
				$pos2 = strlen($alt);
				$isalt = true;
			}
			else
			{
				$endpos = strpos($this->output, $end, $pos1);
				if ($endpos === false) $this->fail("Unclosed $start (expected $end)");
				$pos2 = $endpos - $pos1 + strlen($end);
			}
			
			if($pos1 === false) return false;

			if(!$isalt)
			{
				$render_template = substr($this->output, $pos1+strlen($start), $pos2 - strlen($start) - strlen($end));
				$this->current_block = $render_template;
			}
			else
			{
				$this->current_block = '';
				$render_template = '';
			}
			$this->current_params['render_template'] = $render_template;
			$this->current_params['pos1'] = $pos1;
			$this->current_params['pos2'] = $pos2;
  	} elseif ($action == 'render') {
  		$this->current_action = 'render';
			$start = "<!-- render.$model.$method -->";
			$end = "<!-- /render.$model.$method -->";
			$pos1 = strpos($this->output, $start);
			if ($pos1 === false) return false;
			$endpos = strpos($this->output, $end, $pos1);
			if ($endpos === false) $this->fail("Unclosed $start (expected $end)");
			$pos2 = $endpos - $pos1 + strlen($end);
			
			$render_template = substr($this->output, $pos1+strlen($start), $pos2 - strlen($start) - strlen($end));
			$this->current_block = $render_template;
			$res = preg_match_all('/<!-- print\.([@\+,a-z,A-Z,_,-,\.,0-9]*) (\/?)-->/', $render_template, $datastarts);
			$this->current_params['render_template'] = $render_template;
			$this->current_params['pos1'] = $pos1;
			$this->current_params['pos2'] = $pos2;
			$this->current_params['datastarts'] = $datastarts;
  	}
  }
    
  // ##Forms
  // Every post form gets three hidden fields:
  // - raster_form: the render block that owns the form (model.method), so
  //   models and validation know which form was sent
  // - raster_hp: a honeypot; bots fill it, people never see it
  // - csrf: the session token, when there is a session
  // Field constraints (required, type, minlength, maxlength, min, max,
  // pattern) are read from the inputs so validation can enforce them.
  function index_forms() {
  	$this->forms = array();
  	$this->tag_owners = array();
  	$html = $this->output;
  	if (stripos($html, '<form') === false) return;

  	// render blocks with their ranges
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

  	preg_match_all('/<form\b[^>]*>/i', $html, $opens, PREG_OFFSET_CAPTURE);
  	$forms = array();
  	foreach ($opens[0] as $open) {
  		if (!preg_match('/\bmethod\s*=\s*["\']?post/i', $open[0])) continue;
  		$close = stripos($html, '</form>', $open[1]);
  		$end = $close === false ? strlen($html) : $close;
  		// the innermost render block around the form, validation blocks excluded
  		$owner = ''; $best = -1;
  		foreach ($blocks as $block) {
  			if (strpos($block['ref'], 'validation.') === 0) continue;
  			if ($block['start'] < $open[1] && $block['end'] > $open[1] && $block['start'] > $best) {
  				$owner = $block['ref']; $best = $block['start'];
  			}
  		}
  		$forms[] = array('owner' => $owner, 'tag' => $open[0], 'at' => $open[1], 'end' => $end);
  		if ($owner !== '') {
  			$this->forms[$owner] = array_merge(isset($this->forms[$owner]) ? $this->forms[$owner] : array(), $this->constraints(substr($html, $open[1], $end - $open[1])));
  		}
  	}

  	// validation tags belong to the form they sit in
  	preg_match_all('/<!-- (?:print|render)\.validation\.([^ ]+(?:\([^)]*\))?) \/?-->/', $html, $vtags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
  	foreach ($vtags as $vtag) {
  		foreach ($forms as $form) {
  			if ($vtag[0][1] > $form['at'] && $vtag[0][1] < $form['end'] && $form['owner'] !== '') {
  				$this->tag_owners['validation.'.$vtag[1][0]][] = $form['owner'];
  			}
  		}
  	}

  	// add the hidden fields, from the last form to the first
  	$token = util::csrf_token();
  	$this->form_owners = array();
  	foreach ($forms as $n => $form) $this->form_owners[$n] = $form['owner'];
  	foreach (array_reverse($forms, true) as $n => $form) {
  		if ($form['owner'] !== '') {
  			// tie the validation tags inside this form to it
  			$region = substr($html, $form['at'], $form['end'] - $form['at']);
  			$region = preg_replace('/<!-- (\/?)(render|print)\.validation\.(?!alert\b)([a-z0-9_]+)(\(| \/?-->| -->)/', '<!-- $1$2.validation.$3__f'.$n.'$4', $region);
  			$html = substr($html, 0, $form['at']).$region.substr($html, $form['end']);
  		}
  		$hidden = "\n<input type=\"hidden\" name=\"raster_form\" value=\"".htmlspecialchars($form['owner'], ENT_QUOTES)."\">"
  			."\n<input type=\"text\" name=\"raster_hp\" value=\"\" tabindex=\"-1\" autocomplete=\"off\" aria-hidden=\"true\" style=\"position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden\">";
  		if ($token !== '') $hidden .= "\n<input type=\"hidden\" name=\"csrf\" value=\"".$token."\">";
  		$at = $form['at'] + strlen($form['tag']);
  		$html = substr($html, 0, $at).$hidden.substr($html, $at);
  	}
  	$this->output = $html;
  }

  // field constraints from the inputs of a form
  function constraints($form_html) {
  	$fields = array();
  	preg_match_all('/<(input|select|textarea)\b([^>]*)>/i', $form_html, $inputs, PREG_SET_ORDER);
  	foreach ($inputs as $input) {
  		$attributes = array();
  		preg_match_all('/([a-zA-Z\-]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/', $input[2], $pairs, PREG_SET_ORDER);
  		foreach ($pairs as $pair) {
  			if (isset($pair[4]) && $pair[4] !== '') $value = $pair[4];
  			elseif (isset($pair[3]) && $pair[3] !== '') $value = $pair[3];
  			elseif (isset($pair[2])) $value = $pair[2];
  			else $value = true; // a boolean attribute like required
  			$attributes[strtolower($pair[1])] = is_bool($value) ? true : html_entity_decode($value, ENT_QUOTES);
  		}
  		if (empty($attributes['name'])) continue;
  		$name = preg_replace('/\[\]$/', '', $attributes['name']);
  		if (in_array($name, array('raster_form', 'raster_hp', 'csrf'))) continue;
  		$type = strtolower($input[1]) === 'input' ? strtolower(isset($attributes['type']) ? $attributes['type'] : 'text') : strtolower($input[1]);
  		if (in_array($type, array('submit', 'button', 'reset', 'image'))) continue;
  		$rule = isset($fields[$name]) ? $fields[$name] : array('type' => $type);
  		foreach (array('required', 'minlength', 'maxlength', 'min', 'max', 'pattern') as $constraint) {
  			if (array_key_exists($constraint, $attributes)) $rule[$constraint] = $attributes[$constraint];
  		}
  		$fields[$name] = $rule;
  	}
  	return $fields;
  }

  function remove() {
    	$res = preg_match_all('/<!-- remove -->/', $this->output, $removesStarts);
		foreach ($removesStarts[0] as $key => $value) {
			$start = $value;
			$end = str_replace("<!-- ", "<!-- /", $value);
			$rpos1 = strpos($this->output, $start);
			if ($rpos1 === false) continue;
			$endpos = strpos($this->output, $end, $rpos1);
			if ($endpos === false) $this->fail("Unclosed <!-- remove --> (expected <!-- /remove -->)");
			$rpos2 = $endpos - $rpos1 + strlen($end);
			$this->output = substr_replace($this->output, "", $rpos1, $rpos2);
		}
  }
    
        
  public function _print($data, $model, $method) {
    	
  		extract($this->current_params);
  		if ($pos1 === false) return false;

			if ($this->current_attr !== null) {
				$tag = $render_template;
				if (!($data === false || $data === null || $data === '')) {
					if (!is_scalar($data)) $this->fail("print.{$this->current_attr}.$model.$method returned ".gettype($data)."; an attribute needs a string");
					$tag = self::set_attribute($tag, substr($this->current_attr, 1), $data, $this->current_attr[0] === '+');
				}
				if ($this->pending_mark !== null) $tag = '<!--raster:a '.$this->pending_mark.'-->'.$tag;
				$this->output = substr_replace($this->output, $tag, $pos1, $pos2);
				return 'attr';
			}

			if($model == 'session')
			{
				if(isset($_SESSION) && array_key_exists($method, $_SESSION))
					$this->output = substr_replace($this->output, $_SESSION[$method], $pos1, $pos2);
				else
					$this->output = substr_replace($this->output, "", $pos1, $pos2);
				return 'session';
			}

			if($model == 'self')
			{
				// values handed to a view (emails) are data, so they are escaped
				$value = (string)$this->$method;
				$value = $this->format === 'html' ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false) : $this->escape($value);
				$this->output = substr_replace($this->output, $value, $pos1, $pos2);
				return 'self';
			}

			// @TODO implement else
			if($model == 'if')
			{
				if($this->$method === true) 
					$this->output = substr_replace($this->output, $render_template, $pos1, $pos2);
				else
					$this->output = substr_replace($this->output, '', $pos1, $pos2);

				return 'if';
			}

			if($data === false || $data === null)
				$this->output = substr_replace($this->output, $render_template, $pos1, $pos2);
			elseif(is_scalar($data))
				$this->output = substr_replace($this->output, $this->escape($data), $pos1, $pos2);
			else
				$this->fail("print.$model.$method returned ".gettype($data)."; print needs a string (use render for lists)");

			unset($object);
    }
    
  public function render_results($model, $method, $index = 0)
	{
		if($index === false)
			return $this->render_results[$model][$method];
		else
			return $this->render_results[$model][$method][$index];
	}
	
	function _loop($html, $data, $name)
	{

		$this->current_action = 'loop';

		$lstart = $name;
		$lend = str_replace("<!-- ", "<!-- /", $name);
		$lpos1 = strpos($html, $lstart) + strlen($lstart);
		$lpos2 = strpos($html, $lend) - $lpos1;
		$tloop = substr($html, $lpos1, $lpos2);

		$res = preg_match_all('/<!-- print\.([@\+,a-z,A-Z,_,-,\.]*) (\/?)-->/', $html, $datastarts);

		$datastarts = util::unique_matches($datastarts);
		$return = '';
		foreach($data as $item)
		{
			$res = '';
			foreach ($datastarts[0] as $key => $value) {					

				if($res == '')
					$loop = $tloop;
				else
					$loop = $res;

				if(!array_key_exists($datastarts[1][$key], $item)) continue;

				$start = $value;
				if($datastarts[2][$key] == '/')
					$end = $value;
				else
					$end = str_replace("<!-- ", "<!-- /", $value);
				$pos1 = strpos($loop, $start);
				if ($pos1 === false) continue;
				$pos2 = strpos($loop, $end, $pos1) - $pos1 + strlen($end);

				event::dispatch('loop');
				
				$current_item = substr($loop, $pos1 + strlen($start), $pos2 - 2*strlen($end) + 1);
				$content = is_scalar($item[$datastarts[1][$key]]) ? $this->escape($item[$datastarts[1][$key]]) : '';

				$res = substr_replace($loop, $content, $pos1, $pos2);				
				$occurences = substr_count($res, $value);
				
				if($occurences > 1)
				{
					for ($i=0; $i < $occurences; $i++) { 
						$start = $value;
						$end = str_replace("<!-- ", "<!-- /", $value);
						$rpos1 = strpos($res, $start);
						$rpos2 = strpos($res, $end) - $rpos1 + strlen($end);
						$res = substr_replace($res, $content, $rpos1, $rpos2);
					}
				}
			}
			$return .= $res;
		}

		return $return;
	}
	
	// Fills the form in the current block with $data (or the posted values):
	// value for inputs, checked for checkboxes and radios, selected for
	// options, the text of textareas. Passwords are never filled in.
	// Keys without a field become hidden inputs (only for $data you pass).
	public function form_state($data = null)
	{
		$explicit = $data !== null;
		if (!$explicit) $data = $_POST;
		$data = (array)$data;
		foreach (array('raster_form', 'raster_hp', 'csrf') as $internal) unset($data[$internal]);
		$used = array();
		$attr = function ($tag, $name) {
			return preg_match('/\s'.$name.'\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $m) ? html_entity_decode($m[1] !== '' ? $m[1] : (isset($m[2]) && $m[2] !== '' ? $m[2] : (isset($m[3]) ? $m[3] : '')), ENT_QUOTES) : null;
		};
		$without = function ($tag, $name) {
			return preg_replace('/\s'.$name.'(\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?(?=[\s>\/])/i', '', $tag);
		};
		$lookup = function ($name) use ($data, &$used) {
			$key = preg_replace('/\[\]$/', '', (string)$name);
			if (!array_key_exists($key, $data)) return array(false, null);
			$used[$key] = true;
			return array(true, $data[$key]);
		};
		$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

		$block = preg_replace_callback('/<input\b[^>]*>/i', function ($m) use ($attr, $without, $lookup, $e) {
			$tag = $m[0];
			$name = $attr($tag, 'name');
			if ($name === null || in_array($name, array('raster_form', 'raster_hp', 'csrf'))) return $tag;
			$type = strtolower((string)$attr($tag, 'type') ?: 'text');
			if (in_array($type, array('password', 'submit', 'button', 'reset', 'image', 'file'))) return $tag;
			list($found, $value) = $lookup($name);
			if (!$found) return $tag;
			if ($type === 'checkbox' || $type === 'radio') {
				$tag = $without($tag, 'checked');
				$own = (string)$attr($tag, 'value');
				$on = is_array($value) ? in_array($own, array_map('strval', $value), true) : ((string)$value === ($own === '' ? 'on' : $own));
				return $on ? preg_replace('/\s*\/?>$/', ' checked$0', $tag) : $tag;
			}
			if (is_array($value)) return $tag;
			$tag = $without($tag, 'value');
			return preg_replace('/\s*\/?>$/', ' value="'.$e($value).'"$0', $tag);
		}, $this->current_block);

		$block = preg_replace_callback('/(<textarea\b[^>]*>)(.*?)(<\/textarea>)/is', function ($m) use ($attr, $lookup, $e) {
			list($found, $value) = $lookup($attr($m[1], 'name'));
			return $found && !is_array($value) ? $m[1].$e($value).$m[3] : $m[0];
		}, $block);

		$block = preg_replace_callback('/(<select\b[^>]*>)(.*?)(<\/select>)/is', function ($m) use ($attr, $without, $lookup) {
			list($found, $value) = $lookup($attr($m[1], 'name'));
			if (!$found) return $m[0];
			$values = array_map('strval', (array)$value);
			$options = preg_replace_callback('/<option\b[^>]*>/i', function ($o) use ($attr, $without, $values) {
				$tag = $without($o[0], 'selected');
				return in_array((string)$attr($tag, 'value'), $values, true) ? preg_replace('/>$/', ' selected>', $tag) : $tag;
			}, $m[2]);
			return $m[1].$options.$m[3];
		}, $block);

		foreach ($data as $key => $value) {
			if (is_scalar($value)) {
				$block = preg_replace('/class="spa_'.preg_quote($key, '/').'">(.*?)<\//', 'class="spa_'.$key.'">'.$e($value).'</', $block);
			}
		}

		if ($explicit) {
			$hidden = '';
			foreach ($data as $key => $value) {
				if (isset($used[$key]) || !is_scalar($value)) continue;
				$hidden .= '<input type="hidden" name="'.$e($key).'" value="'.$e($value).'">'."\n";
			}
			if ($hidden !== '') $block = preg_replace('/<form\b[^>]*>/i', "$0\n".$hidden, $block, 1);
		}

		$this->current_block = $block;
		return $block;
	}
	
	function get_parsed_items($data, $bit)
	{
		$ret = '';
		foreach($data as $item)
		{
			$html = $bit;
			foreach ($item as $key => $value) {
				$is_append = false;

				// simple replacement
				$start = "<!-- print.$key -->";
				$end = "<!-- /print.$key -->";
				
				$occurences = substr_count($html, $start);// echo $start."|".$occurences;
				for ($i=0; $i < $occurences; $i++) { 
					$pos1 = strpos($html, $start);
					$pos2 = strpos($html, $end) - $pos1 + strlen($end);
					$html = substr_replace($html, $value, $pos1, $pos2);
				}
				
				// attr substitution
				$res = preg_match_all('/<!-- print\.([@\+,a-z,A-Z,_,\-,\.]*)\.'.$key.' -->/', $html, $datastarts);
				foreach ($datastarts[0] as $key => $v) {
					if(strpos($datastarts[1][$key], '@') !== false)
		            {
		               
		               $is_append = false;
		               $pointers = explode('.', str_replace('@','',$datastarts[1][$key]));
		               $datakey = $pointers[1];
		               $dataattr = $pointers[0];
		            }
		            elseif(strpos($datastarts[1][$key], '+') !== false)
		            {
		               $is_append = true;
		               $pointers = explode('.', str_replace('+','',$datastarts[1][$key]));
		               $datakey = $pointers[1];
		               $dataattr = $pointers[0];
		            }

		            if($is_append)
	                	$html = preg_replace("% ".$dataattr."(.*?)=(.*?)('|\")(.*?)('|\")%", " ".$dataattr.'="$4 '.$value.'"', $html);
                	else
	                	$html = preg_replace("% ".$dataattr."(.*?)=(.*?)('|\")(.*?)('|\")%", " ".$dataattr.'="'.$value.'"', $html);
	                $html = str_replace($v, '', $html);
	                $html = str_replace(str_replace('<!-- ', '<!-- /', $v), '', $html);
				}
			}
			$ret .= $html;
		}

		return $ret;
	}
    
   public function _render($data_arr, $model, $method) {
    
    extract($this->current_params);
    $rendered_data = "";
    if ($pos1 === false) return false;

		if($data_arr === false)
		{
			$this->output = substr_replace($this->output, $render_template, $pos1, $pos2);
			return $data_arr;
		}

		if(is_string($data_arr))
		{
			$this->output = substr_replace($this->output, $data_arr, $pos1, $pos2);
			return $data_arr;
		}

		if(!is_array($data_arr)) return false;

		// editor marks for CMS collections
		$marking = $this->marks !== null && $model === 'cms' && !array_key_exists('__', $data_arr);
		if ($marking) {
			$call = self::parse_call($method);
			$collection = $call ? $call[0] : $method;
		}
		
		foreach($data_arr as $data)
		{
			if(is_object($data))
				$data = (array) $data;

			if(!is_array($data))
				continue;

			$item_mark = null;
			if ($marking && isset($data['id'])) {
				$item_mark = $this->mark(array(
					'kind' => 'item', 'collection' => $collection, 'id' => (int)$data['id'],
					'enabled' => isset($data['enabled']) ? (string)$data['enabled'] : '1',
					'published_at' => isset($data['published_at']) ? (string)$data['published_at'] : '',
					'values' => array_filter($data, function ($v, $k) { return is_scalar($v) && strpos($k, 'raster_') !== 0; }, ARRAY_FILTER_USE_BOTH),
				));
			}

			$rendered_tpl = $render_template;
			foreach ($datastarts[0] as $key => $value) {

				//not very elegant but it is a special case that has to be out of the loop
				// this should be moved in the regexp above
				if(strpos($value, '.if.') !== false) continue;
				
				$start = $value;
				if($datastarts[2][$key] == '/')
					$end = $value;
				else
					$end = str_replace("<!-- ", "<!-- /", $value);

				$rpos1 = strpos($rendered_tpl, $start);
				// already replaced (the same tag appears more than once)
				if($rpos1 === false) continue;
				else
					$rpos2 = strpos($rendered_tpl, $end) - $rpos1 + strlen($end);

				

			    $is_attr = false;
		            if(strpos($datastarts[1][$key], '@') !== false)
		            {
		               $is_attr = true;
		               $is_append = false;
		               $pointers = explode('.', $datastarts[1][$key]);
		               $datakey = $pointers[1];
		               $dataattr = str_replace('@', '', $pointers[0]);
		            }
		            elseif(strpos($datastarts[1][$key], '+') !== false)
		            {
		               $is_attr = true;
		               $is_append = true;
		               $pointers = explode('.', str_replace('+','',$datastarts[1][$key]));
		               $datakey = $pointers[1];
		               $dataattr = $pointers[0];
		            }
		            else
		                $datakey = $datastarts[1][$key];
		            
		            $current_item = ($start === $end) ? '' : substr($rendered_tpl, $rpos1 + strlen($start), $rpos2 - 2*strlen($end)+1);

		            if(array_key_exists($datakey, $data) && is_array($data[$datakey]))
		            {
		            	$loop = $this->_loop($render_template, $data[$datakey], $datastarts[0][$key]);
		            	$rendered_tpl = substr_replace($rendered_tpl, $loop, $rpos1, $rpos2);
		            	$occurences = substr_count($rendered_tpl, $datastarts[0][$key]);
									if($occurences > 0)
									{
										for ($i=0; $i < $occurences; $i++) { 
											$value = $datastarts[0][$key];
											$start = $value;
											$end = str_replace("<!-- ", "<!-- /", $value);
											$rpos1 = strpos($rendered_tpl, $start);
											$rpos2 = strpos($rendered_tpl, $end) - $rpos1 + strlen($end);

											$loop = $this->_loop($rendered_tpl, $data[$datakey], $datastarts[0][$key]);
							        $rendered_tpl = substr_replace($rendered_tpl, $loop, $rpos1, $rpos2);
										}
									}
		            	continue;
		            }
		            
		            if(!array_key_exists($datakey, $data)) {	
		            	continue;
		              // $rendered_tpl = substr_replace($rendered_tpl, "missing_".$datakey, $rpos1, $rpos2);
		            }
		            else
		            {
		              if(!$is_attr && $data[$datakey] === false)
		                  $rendered_tpl = substr_replace($rendered_tpl, $current_item, $rpos1, $rpos2);
		              else
		              {
		                if($is_attr)
		                {
		                	if (is_string($data[$datakey])) $data[$datakey] = htmlspecialchars($data[$datakey], ENT_QUOTES, 'UTF-8', false);
			                // an empty value keeps the mock-up's attribute (a new image field, say)
			                if($data[$datakey] === null || $data[$datakey] === '')
								$attrchange = $current_item;
			                elseif($data[$datakey] === false)
								$attrchange = preg_replace("% ".$dataattr."(.*?)=(.*?)('|\")(.*?)('|\")%", ' ', $current_item);			                	
			                else {
			                	if($is_append)
				                	$attrchange = preg_replace("% ".$dataattr."(.*?)=(.*?)('|\")(.*?)('|\")%", " ".$dataattr.'="$4 '.str_replace('$', '\$', $data[$datakey]).'"', $current_item);
			                	else
				                	$attrchange = preg_replace("% ".$dataattr."(.*?)=(.*?)('|\")(.*?)('|\")%", " ".$dataattr.'="'.str_replace('$', '\$', $data[$datakey]).'"', $current_item);
			                }

			                if ($item_mark !== null && strpos($datakey, 'raster_') !== 0) {
			                	$attrchange = '<!--raster:a '.$this->mark(array('kind' => 'item_attr', 'item' => $item_mark, 'field' => $datakey, 'attr' => $dataattr)).'-->'.$attrchange;
			                }
			                $rendered_tpl = substr_replace($rendered_tpl, $attrchange, $rpos1, $rpos2);

		                }
		                else
		                {
		                	$printed = $this->escape($data[$datakey]);
		                	if ($item_mark !== null && is_scalar($data[$datakey]) && strpos($datakey, 'raster_') !== 0) {
		                		$field_mark = $this->mark(array('kind' => 'item_field', 'item' => $item_mark, 'field' => $datakey));
		                		$printed = '<!--raster:s '.$field_mark.'-->'.$printed.'<!--raster:e '.$field_mark.'-->';
		                	}
		                	$rendered_tpl = substr_replace($rendered_tpl, $printed, $rpos1, $rpos2);
		                	$occurences = substr_count($rendered_tpl, $datastarts[0][$key]);
							if($occurences > 0)
							{
								for ($i=0; $i < $occurences; $i++) { 
									$value = $datastarts[0][$key];
									$start = $value;
									$rpos1 = strpos($rendered_tpl, $start);
									if ($rpos1 === false) break;
									if (substr($value, -5) === ' /-->') {
										// self-closing: <!-- print.url /--> may appear several times
										$rpos2 = strlen($value);
									} else {
										$end = str_replace("<!-- ", "<!-- /", $value);
										$endpos = strpos($rendered_tpl, $end, $rpos1);
										if ($endpos === false) break;
										$rpos2 = $endpos - $rpos1 + strlen($end);
									}
									$rendered_tpl = substr_replace($rendered_tpl, $printed, $rpos1, $rpos2);
								}
							}
		              	}
		              }
		            }
			}
			// keys the row doesn't have keep their mock-up content, without the tags
			$rendered_tpl = preg_replace_callback('/<!-- \/?print\.([@+][a-zA-Z0-9_\-:]+\.)?([A-Za-z0-9_\-]+) \/?-->/', function ($m) {
				return $m[2] === '' ? $m[0] : '';
			}, $rendered_tpl);
			if ($item_mark !== null) $rendered_tpl = '<!--raster:s '.$item_mark.'-->'.$rendered_tpl.'<!--raster:e '.$item_mark.'-->';
			// rows of a text view are lines; rows of a JSON view are list items
			if ($this->format === 'txt') $rendered_tpl = trim($rendered_tpl, "\r\n");
			$rendered_data .= ($this->format === 'json' && $rendered_data !== '' ? ',' : '')."\n".$rendered_tpl;
		}

		$this->render_results[$model][$method][] = $rendered_data;

		if ($marking) {
			// the whole list, with the template's mock-up item for new ones
			$filters = array();
			if ($call && isset($call[1][0]) && is_string($call[1][0])) {
				foreach (explode('&', $call[1][0]) as $pair) {
					$pair = explode('=', $pair, 2);
					if ($pair[0] !== '' && !in_array($pair[0], array('order', 'limit'))) $filters[$pair[0]] = isset($pair[1]) ? $pair[1] : '';
				}
			}
			$mockup = $this->mockup($render_template);
			$list_mark = $this->mark(array('kind' => 'collection', 'collection' => $collection, 'filters' => $filters, 'fields' => $mockup['fields']));
			$rendered_data = '<!--raster:s '.$list_mark.'-->'.$rendered_data.'<template data-raster-mockup="'.$list_mark.'">'.$mockup['html'].'</template><!--raster:e '.$list_mark.'-->';
		}

		if(!array_key_exists("__", $data_arr))
			$this->output = substr_replace($this->output, $rendered_data, $pos1, $pos2);
		else
			$this->output = substr_replace($this->output, "", $pos1, $pos2);
    }
    
    
    // A collection's template turned into the editor's model for new items:
    // fields become <!--raster:m name-->default<!--raster:/m-->, and
    // attribute fields <!--raster:ma name attr--> before their tag.
    function mockup($html) {
      $fields = array();
      $html = preg_replace_callback('/<!-- print\.([@+])([a-zA-Z0-9_\-:]+)\.([A-Za-z0-9_\-@]+) -->(.*?)<!-- \/print\.\1\2\.\3 -->/s', function ($m) use (&$fields) {
        if (strpos($m[3], 'raster_') === 0) return $m[4];
        $fields[$m[3]] = self::get_attribute($m[4], $m[2]);
        return '<!--raster:ma '.$m[3].' '.$m[2].'-->'.$m[4];
      }, $html);
      $html = preg_replace_callback('/<!-- print\.([A-Za-z0-9_\-]+) -->(.*?)<!-- \/print\.\1 -->/s', function ($m) use (&$fields) {
        if (strpos($m[1], 'raster_') === 0) return $m[2];
        if (!isset($fields[$m[1]])) $fields[$m[1]] = trim($m[2]);
        return '<!--raster:m '.$m[1].'-->'.$m[2].'<!--raster:/m-->';
      }, $html);
      $html = preg_replace_callback('/<!-- print\.([A-Za-z0-9_\-]+) \/-->/', function ($m) use (&$fields) {
        if (!isset($fields[$m[1]])) $fields[$m[1]] = '';
        return '<!--raster:m '.$m[1].'--><!--raster:/m-->';
      }, $html);
      // anything else (nested model calls) keeps its mock-up content
      $html = preg_replace('/<!-- \/?(print|render)\.[^ ]+ \/?-->/', '', $html);
      return array('html' => $html, 'fields' => $fields);
    }

    function view_path($view) {
    	return APPBASE.config::get('views_path').DIRECTORY_SEPARATOR.$this->theme.DIRECTORY_SEPARATOR.$view.$this->view_ext;
    }
    
    function dry_template(){

    	$this->current_action = 'dry';
		event::dispatch('before_drying');

		$this->output = preg_replace('/<!-- (\/?)res\.([a-z,_,-]*) -->/', "", $this->output);
		$res = preg_match_all('/<!-- dry\.([a-z,_,-,\/]*)\.([a-z,_,-]*) (\/?)-->/', $this->output, $datastarts);
		$loaded_files = array();
		arsort($datastarts);
		foreach ($datastarts[0] as $key => $value) {					

			$start = $value;
			if($datastarts[3][$key] == '/')
				$end = $value;
			else
				$end = str_replace("<!-- ", "<!-- /", $value);
				
			$pos1 = strpos($this->output, $start);
			$pos2 = strpos($this->output, $end) - $pos1 + strlen($end);

			$file = $datastarts[1][$key];
			
			$path = $this->view_path($file);
						
			if(!file_exists($path)) {
				event::dispatch('dried_'.$file);
				$data = "";
			} else {
				if(!array_key_exists($file,$loaded_files))
					$loaded_files[$file] = file_get_contents($path);

				$data = $loaded_files[$file];
			}

			$drystart = "<!-- res.".$datastarts[2][$key]." -->";
			$dryend = "<!-- /res.".$datastarts[2][$key]." -->";
			$drypos1 = strpos($data, $drystart) + strlen($drystart);
			$drypos2 = strpos($data, $dryend) - $drypos1;

			$data = substr($data, $drypos1, $drypos2);

			event::dispatch('dried_'.$file);

			$this->output = substr_replace($this->output, $data, $pos1, $pos2);

		}
		event::dispatch('after_drying');
    }
    
    public static function set($property)
	{
		$template = template::instance();
		$template->current_config_setting = $property;
		return $template;		
	}
	
	public function to($value)
	{
		$var = $this->current_config_setting;
		$this->$var = $value;
		return $this;
	}
	
	public static function get($varname) {
		$template = template::instance();
		return $template->$varname;
	}
	
    public static function instance()
    {
        $cls = __CLASS__;
        if( class_exists('the_' . $cls) ) $cls = 'the_' . $cls;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new $cls;
        }
        return self::$instances[$cls];
    }

}