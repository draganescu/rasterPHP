<?php
/**
* Authentication
*
* Accounts for editors and site members. Every screen is a region in your
* own template; messages are alert blocks you write.
*
*   <!-- render.authentication.login -->     email (or username) + password form
*   <!-- render.authentication.register -->  sign up (members), name, email, password
*   <!-- render.authentication.forgot -->    asks for an email, sends a reset link
*   <!-- render.authentication.reset -->     new password form, opened from that link
*   <!-- render.authentication.account -->   change name, email, password
*   <!-- render.authentication.logout -->    a form with a logout button
*   <!-- render.authentication.me -->        the logged in user: name, email, role
*
*   <!-- print.if.logged_in --> ... <!-- /print.if.logged_in -->   also logged_out,
*   is_member, is_editor, is_admin
*
* Roles: admin (everything), editor (edits content), member (logs in).
* Settings (application/config/the_app.php):
*   config::set('registration')->to(false);          // turn sign up off
*   config::set('protected')->to(array('account' => 'member', 'members/' => 'member'));
*   config::set('login_page')->to('login');          // where protected pages send people
*   config::set('after_login')->to('account');       // where login goes (else ?next= or /)
* Emails: views _email/password_reset.html (print.self.reset_url, print.self.name).
*/
class authentication
{
	static $roles = array('member' => 1, 'editor' => 2, 'admin' => 3);
	protected static $user = false;

	// ##Accounts

	static function connect() {
		database::instance('cms');
		if (!database::configured()) throw new RuntimeException('No database for the '.config::get('environment').' environment');
		self::migrate();
	}

	static function table_ready() {
		try { return in_array('user', R::inspect()); } catch (Exception $e) { return false; }
	}

	// accounts from the first agent-friendly version lived in usersdata
	static function migrate() {
		static $done = false;
		if ($done) return;
		$done = true;
		try {
			$tables = R::inspect();
			if (!in_array('usersdata', $tables) || (in_array('user', $tables) && R::count('user') > 0) || database::$frozen) return;
			foreach (R::findAll('usersdata') as $old) {
				if (empty($old->username)) continue;
				$user = R::dispense('user');
				$user->username = (string)$old->username;
				$user->email = filter_var($old->username, FILTER_VALIDATE_EMAIL) ? strtolower($old->username) : '';
				$user->name = (string)$old->username;
				$user->password = (string)$old->password;
				$user->role = 'admin';
				$user->created_at = R::isoDateTime();
				R::store($user);
			}
		} catch (Exception $e) {
			log::warning('authentication: '.$e->getMessage());
		}
	}

	static function find($login) {
		if (!self::table_ready()) return null;
		$login = trim((string)$login);
		if ($login === '') return null;
		return R::findOne('user', ' LOWER(email) = ? OR username = ? ', array(strtolower($login), $login));
	}

	// Creates or updates an account. $login is an email or a username.
	static function save_user($login, $password, $role = 'member', $name = null) {
		self::connect();
		if (!isset(self::$roles[$role])) throw new InvalidArgumentException("Role must be one of: ".implode(', ', array_keys(self::$roles)));
		$user = self::find($login);
		if (!$user) {
			$user = R::dispense('user');
			$is_email = filter_var($login, FILTER_VALIDATE_EMAIL);
			$user->email = $is_email ? strtolower($login) : '';
			$user->username = $is_email ? '' : $login;
			$user->created_at = R::isoDateTime();
		}
		if ($name !== null || empty($user->name)) $user->name = $name !== null ? $name : preg_replace('/@.*$/', '', $login);
		$user->role = $role;
		$user->password = password_hash($password, PASSWORD_DEFAULT);
		R::store($user);
		return (int)$user->id;
	}

	// bookkeeping must never block a login, even on a schema that lacks
	// the columns (run `raster schema --apply` to add them)
	protected static function store_quietly($bean) {
		try {
			R::store($bean);
		} catch (Exception $e) {
			log::warning('authentication: '.$e->getMessage());
		}
	}

	// the columns this model uses, for `raster schema --apply`
	static function schema() {
		return array('user' => array(
			'email' => '', 'username' => '', 'name' => '', 'password' => '', 'role' => 'member',
			'created_at' => '', 'last_login' => '', 'failed_count' => 0, 'failed_at' => '',
			'reset_hash' => '', 'reset_expires' => '',
		));
	}

	static function has_users() {
		return self::table_ready() && R::count('user') > 0;
	}

	// id of the account, or false; also upgrades old md5 hashes
	static function check_login($login, $password) {
		$user = self::find($login);
		if (!$user || !is_string($password) || $password === '') return false;
		// five wrong passwords lock the account for 15 minutes
		if ((int)$user->failed_count >= 5 && strtotime((string)$user->failed_at) > time() - 900) return false;
		$hash = (string)$user->password;
		$ok = preg_match('/^[a-f0-9]{32}$/', $hash) ? hash_equals($hash, md5($password)) : password_verify($password, $hash);
		if (!$ok) {
			$user->failed_count = (int)$user->failed_count + 1;
			$user->failed_at = R::isoDateTime();
			self::store_quietly($user);
			return false;
		}
		if (preg_match('/^[a-f0-9]{32}$/', $hash) || password_needs_rehash($hash, PASSWORD_DEFAULT)) {
			$user->password = password_hash($password, PASSWORD_DEFAULT);
		}
		$user->failed_count = 0;
		$user->last_login = R::isoDateTime();
		self::store_quietly($user);
		return (int)$user->id;
	}

	// ties a session to the password it was opened with, so changing the
	// password ends every other session
	static function fingerprint($hash) {
		return substr(hash('sha256', 'raster-session|'.(string)$hash), 0, 24);
	}

	static function log_in($id) {
		util::session(true);
		session_regenerate_id(true);
		$bean = R::load('user', (int)$id);
		$_SESSION['uid'] = (int)$id;
		$_SESSION['uid_check'] = self::fingerprint($bean->password);
		self::$user = false;
	}

	static function log_out() {
		util::session();
		if (session_status() === PHP_SESSION_ACTIVE) {
			$_SESSION = array();
			session_destroy();
		}
		if (PHP_SAPI !== 'cli' && !headers_sent()) {
			setcookie(session_name(), '', array('expires' => time() - 3600, 'path' => '/'));
		}
		self::$user = null;
	}

	// the logged in user as an array, or null
	static function user() {
		if (self::$user !== false) return self::$user;
		util::session();
		self::$user = null;
		if (empty($_SESSION['uid'])) return null;
		try {
			self::connect();
			$bean = self::table_ready() ? R::load('user', (int)$_SESSION['uid']) : null;
			if ($bean && $bean->id && isset($_SESSION['uid_check']) && hash_equals(self::fingerprint($bean->password), (string)$_SESSION['uid_check'])) {
				self::$user = array('id' => (int)$bean->id, 'name' => (string)$bean->name, 'email' => (string)$bean->email, 'username' => (string)$bean->username, 'role' => (string)$bean->role ?: 'member');
			}
		} catch (Exception $e) {
			log::warning('authentication: '.$e->getMessage());
		}
		return self::$user;
	}

	// can('member'), can('editor') (or 'edit'), can('admin')
	static function can($role) {
		if ($role === 'edit') $role = 'editor';
		$user = self::user();
		if (!$user || !isset(self::$roles[$role])) return false;
		$have = isset(self::$roles[$user['role']]) ? self::$roles[$user['role']] : 0;
		return $have >= self::$roles[$role];
	}

	static function url($page) {
		return rtrim(config::get('link_uri'), '/').'/'.ltrim((string)$page, '/');
	}

	// only paths on this site can be redirect targets
	static function safe_next($next) {
		$next = (string)$next;
		return preg_match('#^/(?!/)[^\s\\\\]*$#', $next) ? $next : '';
	}

	// ##Request hooks

	// bound to route_set: template flags and protected pages
	function setup() {
		$user = self::user();
		foreach (array('logged_in' => (bool)$user, 'logged_out' => !$user, 'is_member' => self::can('member'), 'is_editor' => self::can('editor'), 'is_admin' => self::can('admin')) as $flag => $value) {
			template::set($flag)->to($value);
		}
		$uri = '/'.ltrim((string)config::get('uri_string'), '/');
		foreach ((array)config::get('protected', array()) as $pattern => $role) {
			if (!preg_match('%^/'.ltrim($pattern, '/').'%', $uri)) continue;
			if (self::can($role)) continue;
			if ($user) {
				http_response_code(403);
				exit('You do not have access to this page.');
			}
			$this->redirect(self::url(config::get('login_page', 'login')).'?next='.rawurlencode(strtok($uri, '?')));
		}
		return true;
	}

	protected function redirect($location) {
		if (PHP_SAPI === 'cli') return;
		header('Location: '.$location, true, 303);
		exit;
	}

	protected function form_again($data = null) {
		return template::instance()->form_state($data);
	}

	// ##Regions

	// fields: login (or email), password
	function login() {
		$v = validation::get();
		if (!$v->submitted()) return false;
		if (!$v->valid()) return $this->form_again();
		self::connect();
		$login = util::post('login') !== false ? util::post('login') : (util::post('email') !== false ? util::post('email') : util::post('username'));
		$id = self::check_login($login, util::post('password'));
		if (!$id) {
			usleep(300000);
			$v->raise('login_failed');
			return $this->form_again();
		}
		self::log_in($id);
		$next = self::safe_next(util::get('next') ?: util::post('next'));
		$this->redirect($next !== '' ? rtrim(config::get('link_uri'), '/').$next : (config::get('after_login') ? self::url(config::get('after_login')) : config::get('link_uri')));
		return false;
	}

	// fields: name, email, password (and a matches('password', 'password_again') region if you like)
	function register() {
		if (config::get('registration', true) === false) return array();
		$v = validation::get();
		if (!$v->submitted()) return false;
		if (!$v->valid()) return $this->form_again();
		self::connect();
		$email = strtolower(trim((string)util::post('email')));
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$v->raise('email_invalid');
			return $this->form_again();
		}
		if (self::find($email)) {
			$v->raise('email_taken');
			return $this->form_again();
		}
		$password = (string)util::post('password');
		if (strlen($password) < (int)config::get('password_min_length', 8)) {
			$v->raise('password_short');
			return $this->form_again();
		}
		$name = trim((string)util::post('name'));
		$id = self::save_user($email, $password, 'member', $name !== '' ? $name : null);
		self::log_in($id);
		util::done('registered', config::get('after_login') ? self::url(config::get('after_login')) : config::get('link_uri'));
		return false;
	}

	// field: email. Always answers the same, so it can't tell who has an account.
	function forgot() {
		$v = validation::get();
		if (!$v->submitted()) return false;
		if (!$v->valid()) return $this->form_again();
		self::connect();
		$user = self::find(util::post('email'));
		if ($user && filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
			$token = bin2hex(random_bytes(24));
			$user->reset_hash = hash('sha256', $token);
			$user->reset_expires = date('Y-m-d H:i:s', time() + 3600);
			R::store($user);
			$reset = self::url(config::get('reset_page', 'reset')).'?token='.$token;
			if (!mail::send_view('_email/password_reset', $user->email, array('reset_url' => $reset, 'name' => $user->name))) {
				log::error('Password reset email failed: '.mail::$last_error);
			}
		}
		util::done('reset_sent');
		return false;
	}

	protected static function user_for_token($token) {
		if (!is_string($token) || !preg_match('/^[a-f0-9]{48}$/', $token) || !self::table_ready()) return null;
		$user = R::findOne('user', ' reset_hash = ? ', array(hash('sha256', $token)));
		if (!$user || strtotime((string)$user->reset_expires) < time()) return null;
		return $user;
	}

	// fields: password (and matches('password', 'password_again')); the token comes from ?token=
	function reset() {
		$v = validation::get();
		self::connect();
		$token = util::get('token') ?: util::post('token');
		$user = self::user_for_token($token);
		if (!$user) {
			$v->raise('reset_invalid');
			return array();
		}
		if (!$v->submitted()) return $this->form_again(array('token' => $token));
		if (!$v->valid()) return $this->form_again(array('token' => $token));
		$password = (string)util::post('password');
		if (strlen($password) < (int)config::get('password_min_length', 8)) {
			$v->raise('password_short');
			return $this->form_again(array('token' => $token));
		}
		$user->password = password_hash($password, PASSWORD_DEFAULT);
		$user->reset_hash = '';
		$user->reset_expires = '';
		$user->failed_count = 0;
		R::store($user);
		self::log_in($user->id);
		util::done('password_changed', config::get('after_login') ? self::url(config::get('after_login')) : config::get('link_uri'));
		return false;
	}

	// fields: name, email, password (new, optional), current_password
	function account() {
		$user = self::user();
		if (!$user) {
			$this->redirect(self::url(config::get('login_page', 'login')).'?next='.rawurlencode(strtok('/'.ltrim((string)config::get('uri_string'), '/'), '?')));
			return array();
		}
		$v = validation::get();
		if (!$v->submitted()) return $this->form_again(array('name' => $user['name'], 'email' => $user['email']));
		if (!$v->valid()) return $this->form_again();
		self::connect();
		$bean = R::load('user', $user['id']);
		$email = strtolower(trim((string)util::post('email')));
		$password = (string)util::post('password');
		$changes_login = ($email !== '' && $email !== $bean->email) || $password !== '';
		if ($changes_login && !password_verify((string)util::post('current_password'), (string)$bean->password)) {
			$v->raise('current_password_wrong');
			return $this->form_again();
		}
		if ($email !== '' && $email !== $bean->email) {
			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $v->raise('email_invalid'); return $this->form_again(); }
			if (self::find($email)) { $v->raise('email_taken'); return $this->form_again(); }
			$bean->email = $email;
		}
		if ($password !== '') {
			if (strlen($password) < (int)config::get('password_min_length', 8)) { $v->raise('password_short'); return $this->form_again(); }
			$bean->password = password_hash($password, PASSWORD_DEFAULT);
		}
		if (util::post('name') !== false) $bean->name = trim((string)util::post('name'));
		R::store($bean);
		// a new password ends other sessions; this one continues
		if ($password !== '') self::log_in($bean->id);
		util::done('account_saved');
		return false;
	}

	// a form with a button: <form method="post"><button>Log out</button></form>
	function logout() {
		if (!self::user()) return array();
		$v = validation::get();
		if (!$v->submitted()) return false;
		self::log_out();
		$this->redirect(config::get('link_uri'));
		return false;
	}

	// the logged in user: name, email, role
	function me() {
		$user = self::user();
		return $user ? array(array('name' => util::e($user['name'] ?: ($user['username'] ?: $user['email'])), 'email' => util::e($user['email']), 'role' => util::e($user['role']))) : array();
	}
}
