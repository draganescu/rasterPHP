<?php

class authentication
{
	
	var $paged_users = 50;

	function authentication()
	{
		$p = controller::instance();
		if(util::get('do') == 'logout' && util::no_post_data())
		{
			session_destroy();
			util::redirect('login');
			exit;
		}
	}

	
	function get_user_id()
	{
		if($_SESSION['uid'] != '')
			return array(
				0 => array("uid" => $_SESSION['uid'])
			);
		else
			return false;
	}

	function list_users()
	{
		$app = controller::instance();;
		$db = database::instance();
		//$db->escape(false);

		if($pagination)
		{
			$limit = $this->paged_users;
			$page = util::param("page", 1);
			return array("total"=>$db->fetch_total('customers'),"current"=>$page,"perpage"=>$limit);
		}

		$from = ($app->param('page', 1)-1) * $this->paged_users;
		return $db->get_users_from_to($from, $this->paged_users);
	}

	function register()
	{
		$app = controller::instance();
		$db = the::database();
		$validation = controller::get_object('validation');
		
		if(!util::post('register_action')) $validation->reset();
		if(util::post('login_action')) return '';
		
		if(util::no_post_data()  || !util::post('register_action')) return false;
		
		if($validation->invalid == true) return $app->form_state();

		$email = util::post('reg_email');
		$password = util::post('password');
		
		$existing = $db->get_by('users', 'email', $email);
		if($existing) return $validation->raise('email_exists') . $app->form_state();

		$db->add_user($email, $password);
		$user = array_pop($db->get_user($email, $password));

		$uid = $user['id'];

		$this->isin($uid,$email,$password);

		return $validation->raise('you_are_in');
		
	}	

	function forgot()
	{
		$app = my::app();
		$db  = my::database();
		$validation = controller::get_object('validation');

		if(util::no_post_data()) return false;
		if($validation->invalid) return $app->form_state();

		$email = util::post('email');
		$db->get_by('users', 'email', $email);

		if(!$user) return $validation->raise('details_sent');

		$Email = $details['0']['email'];
		$Password = $details['0']['password'];
		$Message = "Your credentials for Daily Grabbers";
		$Message = sprintf("Dear member,

			Here are your login detais:

		===========================
		Email: %s
		Password: %s
		===========================

		Warm wishes,
		Daily Grabbers
		", $Email, $Password);

		require_once 'Email.php';
		$email = new CI_Email;
			
		$email->initialize(
			array(
			'protocol' => 'mail',
			'mailtype' => 'text' //,
			// 'smtp_host' => 'localhost',
			// 'smtp_port' => '25',
			// 'smtp_timeout' => '20',
			// 'validate' => TRUE
			)
		);
		$email->from('admin@dailygrabbers.com');
		$email->to($Email);
		$email->subject($Subject);

		$email->message($Message);

		$return = $email->send();

		return $validation->raise('details_sent');
	}

	function account()
	{
		$app = my::app();
		$db = my::database();

		$uid = $this->get_user_id();
		if(!$uid) $app->route('login');

		$users = $db->get_by('users','id',$uid[0]['uid']);
		$users[0]['password_repeat'] = $users[0]['password'];

		if(util::no_post_data())
			return $app->form_state($users[0]);

		
	}

	function is_logged_in()
	{
		$app = my::app();
		//$app->logged_in = false;
		//$app->logged_out = true;

		if($_SESSION['loggedin'] == true)
		{
			//$app->logged_out = false;
			//$app->logged_in = true;
		}
		
		return $_SESSION['loggedin'];
	}

	function login()
	{

		$app = my::app();
		$db = my::database();
		$validation = controller::get_object('validation');
		
		//$app->logged_in = false;
		//$app->logged_out = true;

		if($_SESSION['loggedin'] == true)
		{
			//$app->logged_out = false;
			//$app->logged_in = true;
		}
		
		if(!util::post('login_action')) $validation->reset();
		if(util::post('register_action')) return '';
		
		if(util::no_post_data() || !util::post('login_action')) return $app->current_block;
		
		if($validation->invalid == true) return $app->form_state();

		$user = $db->get_user(util::post('email'), util::post('password'));
		if(!$user) return $validation->raise('bad_login') . $app->current_block;

		$existing = $db->get_by('users', 'id', $user[0]['id']);
		$this->isin($existing[0]['id'],$existing[0]['email'],$existing[0]['password']);

		//$app->logged_out = false;
		//$app->logged_in = true;

		return $validation->raise('success');
		
	}

	function isin($uid, $email, $password)
	{
		$_SESSION['loggedin'] = true;
		$_SESSION['uid'] = $uid;
		$_SESSION['email'] = $email;
	}
	
	function login_check()
	{
		$p = my::app();

		if(!preg_match("%account$%", $p->uri_string))
			return true;

		if(preg_match("|login|", $p->uri_string))
			return true;
		
		$p->loggedin = false;
		$p->loggedout = true;

		if(isset($_SESSION['loggedin']))
		{
			$p->loggedin = true;
			$p->loggedout = false;
			return true;
		}
		else
			$p->route("login");
		
	}
	
}
?>