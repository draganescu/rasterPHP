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
		
		if(stripos($template->output,'<base') === false)
				$base = "<base href='".$template->base_tag."' />\n<script type='text/javascript'>var BASE = '".$template->link_uri."'</script>";
		else
			$base = "<script type='text/javascript'>var BASE = '".$template->link_uri."'</script>";
		
		$template->output = str_replace('<head>', "<head>\n".$base, $template->output);
		
		$res = preg_match_all('/<!-- ((print|render)\.(([a-z,_,-,0-9]*)\.(.*?))) (\/?)-->/', $template->output, $methodstarts);
		$template->models = array_unique($methodstarts[4]);

		foreach ($methodstarts[2] as $k=>$v) {
			if($v == 'render')
				$template->models_methods_render[] = array($methodstarts[4][$k],$methodstarts[5][$k]);
			if($v == 'print')
				$template->models_methods_print[] = array($methodstarts[4][$k],$methodstarts[5][$k]);
		}

		$template->models_methods_render = array_reverse($template->models_methods_render);
		$template->models_methods_print = array_reverse($template->models_methods_print);
		
		$template->remove();
		
		return $template;
		
  }

  public function set_current_block($model, $method, $action) {
  	self::$model = $model;
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
				$pos2 = strpos($this->output, $end) - $pos1 + strlen($end);
			}
			
			if($pos1 === false) return false;

			if(!$isalt)
			{
				$this->current_block = substr($this->output, $pos1+strlen($start), $pos2 - 2*strlen($end));
				$render_template = substr($this->output, $pos1+strlen($start), $pos2 - 2*strlen($end) + 1);
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
			$pos2 = strpos($this->output, $end) - $pos1 + strlen($end);
			
			$this->current_block = substr($this->output, $pos1+strlen($start), $pos2 - 2*strlen($end));

			$render_template = substr($this->output, $pos1+strlen($start), $pos2 - 2*strlen($end)+1);
			$res = preg_match_all('/<!-- print\.([@\+,a-z,A-Z,_,-,\.,0-9]*) (\/?)-->/', $render_template, $datastarts);
			$this->current_params['render_template'] = $render_template;
			$this->current_params['pos1'] = $pos1;
			$this->current_params['pos2'] = $pos2;
			$this->current_params['datastarts'] = $datastarts;
  	}
  }
    
  function remove() {
    	$res = preg_match_all('/<!-- remove -->/', $this->output, $removesStarts);
		foreach ($removesStarts[0] as $key => $value) {
			$start = $value;
			$end = str_replace("<!-- ", "<!-- /", $value);
			$rpos1 = strpos($this->output, $start);
			$rpos2 = strpos($this->output, $end) - $rpos1 + strlen($end);
			$this->output = substr_replace($this->output, "", $rpos1, $rpos2);
		}
  }
    
        
  public function _print($data, $model, $method) {
    	
  		extract($this->current_params);

			if($model == 'session')
			{
				if(array_key_exists($method, $_SESSION))
					$this->output = substr_replace($this->output, $_SESSION[$method], $pos1, $pos2);
				else
					$this->output = substr_replace($this->output, "", $pos1, $pos2);
				return 'session';
			}

			if($model == 'self')
			{
				$this->output = substr_replace($this->output, $this->$method, $pos1, $pos2);
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

			if($data === false)
				$this->output = substr_replace($this->output, $render_template, $pos1, $pos2);
			else
				$this->output = substr_replace($this->output, $data, $pos1, $pos2);

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

		$datastarts = super_unique($datastarts);
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
				$pos2 = strpos($loop, $end) - $pos1 + strlen($end);

				$this->dispatch('loop');
				
				$current_item = substr($loop, $pos1 + strlen($start), $pos2 - 2*strlen($end) + 1);
				$content = $item[$datastarts[1][$key]];

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
	
	public function form_state($data = null)
	{
		$this->current_block = preg_replace('/(<input(.*?)(text|hidden)(.*?))value="(.*?)"/',
											"$1",
											$this->current_block);
		
		if($data == null) $data = $_POST;
		$hidden = '';
		foreach($data as $key => $value)
		{
			if(is_array($value))
			{	
				foreach($value as $v)
				{
					$evalue = str_replace("/","\/",$v);
					$value = $v;
					
					$this->current_block = 	
					preg_replace('/<input(.*?)type="checkbox"(.*?)name="'.$key.'\[\]"(.*?)value="'.$evalue.'"/',
						'$0 checked="true"',
						$this->current_block, -1, $checkboxes);

					$this->current_block =
					preg_replace("/<select(.*?)name=\"".$key."\[\]\"(.*?)<option(.*?)value=\"".$evalue."\"/",
						"$0 selected=\"true\"",
						$this->current_block, -1, $selects);
				}
 			} else {
				$evalue = str_replace("/","\/",preg_quote($value));
				$this->current_block = preg_replace('/<input(.*?)type="text"(.*?)name="'.$key.'"/',
					'$0 value="'.$value.'"',
					$this->current_block, -1, $textfields);

				if($textfields == 0)
					$this->current_block = 
					preg_replace('/<input(.*?)type="radio"(.*?)name="'.$key.'\[\]"(.*?)value="'.$evalue.'"/',
					'$0 checked="true"',
					$this->current_block, -1, $radios);

				if($textfields == 0 && $radios == 0)
					$this->current_block = 
					preg_replace('/<input(.*?)type="checkbox"(.*?)name="'.$key.'"(.*?)value="'.$evalue.'"/',
					'$0 checked="true"',
					$this->current_block, -1, $checkboxes);
				
				if($textfields == 0 && $radios == 0 && $checkboxes == 0)
					$this->current_block = preg_replace("/<textarea(.*?)name=\"".$key."\"(.*?)>/ims",
						"$0".$value,
						$this->current_block, -1, $textareas);
				
				if($textfields == 0 && $radios == 0 && $checkboxes == 0 && $textareas == 0)
					$this->current_block =
					preg_replace("/<select(.*?)name=\"".$key."\"(.*?)<option(.*?)value=\"".$evalue."\"/ims",
						"$0 selected=\"true\"",
						$this->current_block, -1, $selects);
				
				if($textfields == 0 && $radios == 0 && $checkboxes == 0 && $textareas == 0 && $selects == 0)
					$this->current_block = preg_replace('/<input(.*?)type="hidden"(.*?)name="'.$key.'"/',
						'$0 value="'.$value.'"',
						$this->current_block, -1, $hiddens);
				
				$this->current_block = preg_replace('/class="spa_'.$key.'">(.*?)<\//',
						'class="spa_'.$key.'">'.$value.'</',
						$this->current_block);
			}
			$totals = array_sum(compact('textfields', 'textareas', 'selects', 'radios', 'checkboxes', 'hiddens'));
			if($totals == 0)
				$hidden .= '<input type="hidden" name="'.$key.'" value="'.$value.'" />' . "\n";

		}
		if($hidden != '')
			$this->current_block = preg_replace("/<form(.*?)>/ims", "\n $0 ". $hidden."\n", $this->current_block);

		return $this->current_block;
	}
	
	function get_parsed_items($data, $bit)
	{
		$ret = '';
		foreach($data as $item)
		{
			$html = $bit;
			foreach ($item as $key => $value) {

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
		
		foreach($data_arr as $data)
		{
			if(is_object($data))
				$data = (array) $data;

			if(!is_array($data))
				continue;

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
				if($rpos1 === false)
				{
					$end = $start;
					$rpos1 = strpos($rendered_tpl, $start);
					$rpos2 = $rpos1 + strlen($start);
				}
				else
					$rpos2 = strpos($rendered_tpl, $end) - $rpos1 + strlen($end);

				

			    $is_attr = false;
		            if(strpos($datastarts[1][$key], '@') !== false)
		            {
		               $is_attr = true;
		               $is_append = false;
		               $pointers = explode('.', str_replace('@','',$datastarts[1][$key]));
		               $datakey = $pointers[1];
		               $dataattr = $pointers[0];
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
		            
		            $current_item = substr($rendered_tpl, $rpos1 + strlen($start), $rpos2 - 2*strlen($end)+1);

		            if(is_array($data[$datakey]))
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

		            if(!array_key_exists($datakey, $data)) continue;
		              // $rendered_tpl = substr_replace($rendered_tpl, "missing_".$datakey, $rpos1, $rpos2);
		            else
		            {
		              if(!$is_attr && $data[$datakey] === false)
		                  $rendered_tpl = substr_replace($rendered_tpl, $current_item, $rpos1, $rpos2);
		              else
		              {
		                if($is_attr)
		                {
			                if($data[$datakey] === false)
								$attrchange = preg_replace("% ".$dataattr."(.*?)=(.*?)('|\")(.*?)('|\")%", ' ', $current_item);			                	
			                else {
			                	if($is_append)
				                	$attrchange = preg_replace("% ".$dataattr."(.*?)=(.*?)('|\")(.*?)('|\")%", " ".$dataattr.'="$4 '.str_replace('$', '\$', $data[$datakey]).'"', $current_item);
			                	else
				                	$attrchange = preg_replace("% ".$dataattr."(.*?)=(.*?)('|\")(.*?)('|\")%", " ".$dataattr.'="'.str_replace('$', '\$', $data[$datakey]).'"', $current_item);
			                }

			                $rendered_tpl = substr_replace($rendered_tpl, $attrchange, $rpos1, $rpos2);

		                }
		                else
		                {
		                	$rendered_tpl = substr_replace($rendered_tpl, $data[$datakey], $rpos1, $rpos2);
		                	$occurences = substr_count($render_tpl, $datastarts[0][$key]);
							if($occurences > 0)
							{
								for ($i=0; $i < $occurences; $i++) { 
									$value = $datastarts[0][$key];
									$start = $value;
									$end = str_replace("<!-- ", "<!-- /", $value);
									$rpos1 = strpos($rendered_tpl, $start);
									$rpos2 = strpos($rendered_tpl, $end) - $rpos1 + strlen($end);
									$rendered_tpl = substr_replace($rendered_tpl, $data[$datakey], $rpos1, $rpos2);
								}
							}
		              	}
		              }
		            }
			}
			$rendered_data .= "\n".$rendered_tpl;
		}

		$this->render_results[$model][$method][] = $rendered_data;

		if(!array_key_exists("__", $data_arr))
			$this->output = substr_replace($this->output, $rendered_data, $pos1, $pos2);
		else
			$this->output = substr_replace($this->output, "", $pos1, $pos2);
    }
    
    
    function view_path($view) {
    	return $this->views_path.DIRECTORY_SEPARATOR.$this->theme.DIRECTORY_SEPARATOR.$view.$this->view_ext;
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