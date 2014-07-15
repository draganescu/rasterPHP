<?php

/**
* Cms
*/
class cms
{
	
	private $page = NULL;
	private $page_name = NULL;
	private $page_variables = array();
	private $page_data = array();

	private $data_name = NULL;

	// custom cms routes for admin panels
	public function route() {
		include 'routes.php';
	}

	// Setup routes for the admin section
	public function setup()
	{
		session_start();

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

		if (strpos($slug, '/login') !== false || $slug == "/raster_guide") {
			return;
		}

		if (strpos($slug, '_item') !== false) {
			preg_match_all('%/([a-z]+)/([a-z]+)_(item)(s?)%', $slug, $matches);
			$slug = $matches[0][0];
		}

		if (controller::instance()->current_route == false) {
			return;
		}

		// creates the page and adds new fields if any
		$this->create_cms_data();


		$page_name = str_replace('/', '', $slug).'page';
		$page_name = str_replace('_', '', $page_name);

		$page = R::findOne($page_name, '1 ORDER BY id DESC');
		if (empty($page)) {
			$page = R::dispense($page_name);
			// default page properties
			$page->slug = $slug;
			$page->updated_at = R::isoDateTime();
			R::store($page);
		}

		$this->save_page();
		$this->save_data();
		$this->add_data();

		$this->page = $page;
		$this->page_name = $page_name;
		$this->slug = $slug;
	}

	public function __call($name, $arguments)
	{
			$template = template::instance();
			$fields = R::inspect($this->page_name);
			$page = R::findOne($this->page_name, '1 ORDER BY id DESC');
			$action = template::get('current_action');

			switch ($action) {
				case 'print':
					$this->page_variables[] = $name;
					if (!array_key_exists($name, $fields)) {
						$page->$name = trim(template::get('current_block'));
						R::store($page);
					}
					if (!empty($page->$name)) {
						return $page->$name;
					} else {
						return false;
					}

				case 'render':
					$filter_link_params = array();
					$this->page_data[] = $name;
					$this->data_name = $name.'data';
					$filters = array();

					extract(template::get('current_params'));
					foreach ($datastarts[1] as $key=>$value) {
						if(strpos($value, 'if.') !== false) continue;
						if(strpos($value, 'raster_filter') !== false) {
							$params = explode("raster_filter@", $value);
							$filter_link_params[$params[1]] = explode('@', $params[1]);
							continue;
						}
						list($property, $content) = $this->detect_data($key, $value);
						if ($property == "raster_detail_link") {
							continue;
						}
						$expected_properties[$property] = $content;
					}

					// pagination
					$page_size = config::get($name."_page_size");
					if ($page_size == '') {
						$page_size = config::get("raster_page_size");
						if ($page_size == '') {
							$page_size = 10;
						}
					}
					
					$page = util::param($name.'_page', false);
					if ($page) {
						$roffset = ($page-1)*$page_size;
					} else {
						$roffset = 0;
					}
					
					// filter by id
					if (util::param($name) == $name.'_item') {
						$filters['id'] = util::param('item');
					}

					// uri filters
					if (util::param($name) == $name.'_items') {
						$uri_segments = config::get('uri_segments');
						$start_key = array_search($name.'_items', $uri_segments);
						foreach ($uri_segments as $key => $value) {
							if ($key > $start_key) {
								if (($key - $start_key)%2 == 0) {
									$filters[$uri_segments[$key-1]] = $value;
								}
							}
						}
					}
					unset($filters[$name.'_page']); // removing the uri filter

					$data = R::findLast($this->data_name);

					if (empty($data)) {
						$item = R::dispense($this->data_name);
						foreach ($expected_properties as $property=>$content) {
							$item->$property = trim($content);
						}
						$item->updated_at = R::isoDateTime();
						$item->enabled = true;
						$id = R::store($item);
						$data = R::load($this->data_name, $id);
					} else {

						// param filters
						if (!empty($arguments)) {
								$filters = $this->make_filters($arguments[0], $expected_properties, $filters);
						}
						
						// we check for new fields just like for pages
						$fields = R::inspect($this->data_name);
						//if (count($expected_properties) > count($fields) - 3) {
							$latest = R::findOne($this->data_name, '1 ORDER BY id DESC');
							foreach ($expected_properties as $key => $value) {
								if (!array_key_exists($key, $fields)) {
									$latest->$key = trim($value);
								}
							}
							R::store($latest);
						//}


						$sql = '1 = 1';
						foreach ($filters as $key => $value) {
							$sql .= ' AND '.$key." = :".$key;
							$rb_filters[':'.$key] = $value;
						}	
						$filters["rlength"] = $page_size;
						$filters["roffset"] = $roffset;
						$sql .= ' LIMIT :roffset, :rlength';
						$data = R::find($this->data_name, $sql, $filters);
					}



					$data = R::exportAll( $data );

					// building auto detail links
					foreach ($data as $key => $item) {
						$base = config::get("link_uri");
						$detail_link = $base.substr($this->data_name, 0, -4).'/'.$name.'_item/'.$item['id'];
						$data[$key]['raster_detail_link'] = $detail_link;
						if (count($filter_link_params) > 0) {
							foreach ($filter_link_params as $at_key => $filters) {
								$filter_link = config::get('link_uri').$name.'/'.$name.'_items/';
								foreach ($filters as $field) {
									$filter_link .= $field.'/'.$item[$field].'/';
								}
								$data[$key]['raster_filter@'.$at_key] = $filter_link;
							}
						}
					}

					return $data;

				default:
					return false;
			}
	}

	// parses the filters and adds new fields if any
	function make_filters($filters, &$expected_properties, &$data_filter) {
		$params = array();
		$fields = R::inspect($this->data_name);
		$latest = R::findOne($this->data_name, '1 ORDER BY id DESC');

		foreach (explode('&', $filters) as $k=>$chunk) {
	    $params[$k] = explode("=", $chunk);
	    $data_filter[$params[$k][0]] = $params[$k][1];
		}

		foreach ($data_filter as $key=>$value) {
			$expected_properties[$key] = $value;
		}
		return $data_filter;
	}

	function create_cms_data() {
		$settings = R::findOne('rasterdata', '1 ORDER BY id DESC');
		if (empty($settings)) {
			$settings = R::dispense('rasterdata');
			$settings->key_name = 'default_page_parameter';
			$settings->key_value = 'page';
			R::store($settings);

			$settings = R::dispense('rasterdata');
			$settings->key_name = 'default_URL_key';
			$settings->key_value = 'id';
			R::store($settings);
		}
		$users = R::findOne('usersdata', '1 ORDER BY id DESC');
		if (empty($users)) {
			$users = R::dispense('usersdata');
			$users->username = 'admin';
			$users->password = md5('admin');
			R::store($users);
		}
	}

	function get_page_variable($page, $variable) {
		$db = database::instance('cms');
		$data = R::findOne($page, '1 ORDER BY id DESC');
		return array(
			"type" => $data->getMeta('type'),
			"value" => $data->$variable
		);
	}


	function edit_data() {

		$db = database::instance('cms');
		$data_type = util::post('name').'data';

		
		$data = R::findAll($data_type);
		$fields = R::inspect($data_type);

		include BASE.'models/cms/editor/data.php';

		return false;
	}

	function edit_item() {

		$db = database::instance('cms');
		$item_type = util::post('name').'data';
		$item_id = util::post('did');

		
		$data = R::load($item_type, $item_id);
		$fields = R::inspect($item_type);

		include BASE.'models/cms/editor/item.php';

		return false;
	}

	function add_item() {

		$db = database::instance('cms');
		$item_type = util::post('name').'data';

		
		$data = R::dispense($item_type);
		$fields = R::inspect($item_type);

		include BASE.'models/cms/editor/add.php';

		return false;
	}

	function edit_variable() {
		extract($this->get_page_variable(util::post('page'), util::post('name')));
		include BASE.'models/cms/editor/page.php';
		return false;	
	}

	function style() {
		if (!util::param('output', false)) {
				return config::get('link_uri').'api/cms/style/output/true';
		}
		header("Content-Type: text/css");
		header("X-Content-Type-Options: nosniff");
		echo file_get_contents(BASE.'/views/cms_admin/style.css');
		return false;
	}

	public function css() {
		$file = util::param('raster_file', 'raster_cms');
		header("Content-Type: text/css");
		header("X-Content-Type-Options: nosniff");
		echo file_get_contents(BASE.'/models/cms/css/'.$file.'.css');
		return false;
	}

	public function script() {
		$file = util::param('raster_file', 'raster_cms');
		header("content-type: application/javascript");
		echo file_get_contents(BASE.'/models/cms/js/'.$file.'.js');
		return false;
	}

	public function logout() {
		if (util::param('logout') === 'fromraster') {
			session_destroy();
			util::redirect();
			exit;
		}
		return false;
	}

	public function login() {

		$this->logout();

		if (cms::loggedin()) {
			util::redirect();
		}

		$db = database::instance('cms');

		$this->default_check();
		
		$username = util::post('username');
		$password = md5(util::post('password'));

		$user = R::findOne('usersdata', ' username = ? AND password = ?', array( $username, $password ));
		if (empty($user)) {
			return false;
		}
		$_SESSION['uid'] = $user->id;
		session_write_close();
		util::redirect();
		exit;
	}

	function default_check() {
		if (R::count('usersdata') == 1) {
			$user = R::load('usersdata', 1);
		}
		if (!empty($user)) {
			if ($user->password == md5($user->username) && $user->username == 'admin') {
				$validation = controller::get_object('validation');
				echo $validation->raise('default_setup_detected');
				return true;
			}
		}
		return false;
	}

	static function loggedin() {
		return !empty($_SESSION['uid']);
	}

	function save_page() {
		if (!util::post('raster_action', false)) {
			return false;
		}
		if (util::post('raster_action', false) !== 'save_page') {
			return false;
		}
		$page = util::post('page_name');
		$variable = util::post('variable_name');
		$value = util::post('raster_page_value');
		$db = database::instance('cms');
		$data = R::findOne($page, '1 ORDER BY id DESC');
		$new_data = R::dup($data);
		$new_data->$variable = $value;
		R::store($new_data);
	}

	function save_data() {
		if (!util::post('raster_action', false)) {
			return false;
		}
		if (util::post('raster_action', false) !== 'save_data') {
			return false;
		}
		$db = database::instance('cms');

		$data_type = util::post('data_name').'data';
		$did = util::post('data_id');
		$item = R::load($data_type, $did);

		$fields = R::inspect($data_type);
		foreach ($fields as $key => $value) {
			if ($key == 'id') {
				continue;
			}
			$item->$key = util::post($key);
		}
		R::store($item);
	}

	function add_data() {
		if (!util::post('raster_action', false)) {
			return false;
		}
		if (util::post('raster_action', false) !== 'add_data') {
			return false;
		}
		$db = database::instance('cms');

		$data_type = util::post('data_name').'data';
		$item = R::dispense($data_type);

		$fields = R::inspect($data_type);
		foreach ($fields as $key => $value) {
			if ($key == 'id') {
				continue;
			}
			$item->$key = util::post($key);
		}
		R::store($item);
	}

	public function buttons() {

		if (!cms::loggedin()) {
			return null;
		}

		return '
				<link rel="stylesheet" href="'.config::get('link_uri').'api/cms/css/raster_file/croppic">
				<link rel="stylesheet" href="'.config::get('link_uri').'api/cms/css">
				<script language="javascript">
					var Raster_Admin = {};
					Raster_Admin.page_data = '.json_encode($this->page_data).';
					Raster_Admin.page_variables = '.json_encode($this->page_variables).';
					Raster_Admin.page_name = '.json_encode($this->page_name).';
				</script>
				<script language="javascript" src="'.config::get('link_uri').'api/cms/script/raster_file/croppic.min"></script>
				<script language="javascript" src="'.config::get('link_uri').'api/cms/script"></script>
		';
	}

	public function upload_media() {
		$imagePath = BASE.'../'.config::get('raster_media_folder').'/';
		$allowedExts = array("gif", "jpeg", "jpg", "png", "GIF", "JPEG", "JPG", "PNG");
		$temp = explode(".", $_FILES["img"]["name"]);
		$extension = end($temp);

		if ( in_array($extension, $allowedExts)) {
		  if ($_FILES["img"]["error"] > 0) {
				 $response = array(
					"status" => 'error',
					"message" => 'ERROR Return Code: '. $_FILES["img"]["error"],
				);
				echo "Return Code: " . $_FILES["img"]["error"] . "<br>";
			} else {	
			  $filename = $_FILES["img"]["tmp_name"];
			  list($width, $height) = getimagesize( $filename );

			  move_uploaded_file($filename,  $imagePath . $_FILES["img"]["name"]);

			  $response = array(
					"status" => 'success',
					"url" => config::get('base_uri').config::get('raster_media_folder').'/'.$_FILES["img"]["name"],
					"width" => $width,
					"height" => $height
			  );
			}
		} else {
		   $response = array(
				"status" => 'error',
				"message" => 'something went wrong',
			);
		}
		  
		return $response;
	}

	public function crop_media() {

		$imagePath = BASE.'../'.config::get('raster_media_folder').'/';
		$imgUrl = 		util::post('imgUrl');
		$imgInitW = 	util::post('imgInitW');
		$imgInitH = 	util::post('imgInitH');
		$imgW = 			util::post('imgW');
		$imgH = 			util::post('imgH');
		$imgY1 = 			util::post('imgY1');
		$imgX1 = 			util::post('imgX1');
		$cropW = 			util::post('cropW');
		$cropH = 			util::post('cropH');

		$jpeg_quality = 100;

		$filename = "croppedImg_".time().md5($imgUrl);
		$output_filename = $imagePath."/".$filename;

		$what = getimagesize($imgUrl);
		switch(strtolower($what['mime']))
		{
			case 'image/png':
				$img_r = imagecreatefrompng($imgUrl);
				$source_image = imagecreatefrompng($imgUrl);
				$type = '.png';
				break;
			case 'image/jpeg':
				$img_r = imagecreatefromjpeg($imgUrl);
				$source_image = imagecreatefromjpeg($imgUrl);
				$type = '.jpeg';
				break;
			case 'image/gif':
				$img_r = imagecreatefromgif($imgUrl);
				$source_image = imagecreatefromgif($imgUrl);
				$type = '.gif';
				break;
			default: die('image type not supported');
		}

		$resizedImage = imagecreatetruecolor($imgW, $imgH);
		imagecopyresampled($resizedImage, $source_image, 0, 0, 0, 0, $imgW, 
		$imgH, $imgInitW, $imgInitH);	


		$dest_image = imagecreatetruecolor($cropW, $cropH);
		imagecopyresampled($dest_image, $resizedImage, 0, 0, $imgX1, $imgY1, $cropW, 
		$cropH, $cropW, $cropH);	


		imagejpeg($dest_image, $output_filename.$type, $jpeg_quality);

		$response = array(
		"status" => 'success',
		"url" => config::get('base_uri').config::get('raster_media_folder').'/'.$filename.$type 
		);
		return $response;
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