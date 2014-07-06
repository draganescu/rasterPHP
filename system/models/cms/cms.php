<?php

/**
* Cms
*/
class cms
{
	
	private $page = NULL;
	private $page_name = NULL;
	private $page_variables = array();

	private $data_name = NULL;

	// Setup routes for the admin section
	public function setup()
	{

		$db = database::instance('cms');

		if (config::get('cms_enabled') == false) {
			return;
		}

		$uri_string = config::get('uri_string');
		$index_file = config::get('index_file');
		$slug = str_replace($index_file, '', $uri_string);
		$slug = str_replace('//', '/', $slug);
		if ($slug == '/') {
			$slug = 'home';
		}

		$page_name = str_replace('/', '', $slug).'page';
		$page_name = str_replace('_', '', $page_name);

		$page = R::findOne($page_name, ' slug = ? ', array($slug));
		if (empty($page)) {
			$page = R::dispense($page_name);
			// default page properties
			$page->slug = $slug;
			$page->updated_at = R::isoDateTime();
			$page->parent = '/';
			$page->live = true;
			R::store($page);
		}

		$this->page = $page;
		$this->page_name = $page_name;
		$this->slug = $slug;
	}

	public function __call($name, $arguments)
	{
			$template = template::instance();
			$fields = R::inspect($this->page_name);
			$page = R::findOne($this->page_name, ' slug = ? ', array($this->slug));
			$action = template::get('current_action');

			switch ($action) {
				case 'print':
					if (!array_key_exists($name, $fields)) {
						$page->$name = template::get('current_block');
						R::store($page);
					}
					if (!empty($page->$name) && $page->live) {
						return $page->$name;
					} else {
						return false;
					}

				case 'render':
					$this->data_name = $name.'data';
					if (empty($arguments)) {
						$data = R::findAll($this->data_name);
					}
					if (empty($data)) {
						$item = R::dispense($this->data_name);
						extract(template::get('current_params'));
						foreach ($datastarts[1] as $key=>$value) {
							if(strpos($value, 'if.') !== false) continue;
							list($property, $content) = $this->detect_data($key, $value);
							$item->$property = $content;
						}
						$item->updated_at = R::isoDateTime();
						$id = R::store($item);
						$data = R::load($this->data_name, $id);
					}
					return R::exportAll( $data );
				default:
					return false;
			}
	}

	/**
	 * The sitemap functiom builds an associative array of pages and slugs
	 * based on relationships trough the parent field
	 */
	public function sitemap() {

	}

	private function detect_data($key, $value) {
		$parts = explode('.', $value);
		extract(template::get('current_params'));

	  $rendered_tpl = $render_template;
		$start = "<!-- print.".$value." -->";
		if($datastarts[2][$key] == '/')
			$end = $start;
		else
			$end = str_replace("<!-- ", "<!-- /", $start);

		$rpos1 = strpos($rendered_tpl, $start);
		if($rpos1 === false)
		{
			$end = $start;
			$rpos1 = strpos($rendered_tpl, $start);
			$rpos2 = $rpos1 + strlen($start);
		}
		else
			$rpos2 = strpos($rendered_tpl, $end) - $rpos1 + strlen($end);
		
		if (strpos($value, '@') !== false) {
			$dataattr = str_replace('@', '', $parts[0]);
			preg_match("% ".$dataattr."(.*?)=(.*?)('|\")(.*?)('|\")%", $rendered_tpl, $attribute_value);	
			$content = $attribute_value[4];
		} else {
			$content = substr($rendered_tpl, $rpos1 + strlen($start), $rpos2 - 2*strlen($end)+1);
		}
		$property = $parts[count($parts) -1];
		return array($property, $content);
	}
}