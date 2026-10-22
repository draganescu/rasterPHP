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
* Admin pages are the views 'protected' keeps for editors or admins. The
* in-page editor lists them, so they need no hidden menu.
* Emails: views _email/password_reset.html (print.self.reset_url, print.self.name).
* Login links: `php bin/raster login-link <email|username> [--next=/path]`
* prints a link that logs that account in once, within a minute, without
* a password (for a host that runs the site, such as a site builder).
*/
class authentication
{
	static $roles = array('member' => 1, 'editor' => 2, 'admin' => 3);
	protected static $user = false;

	// ##Accounts

	static function connect() {
		database::instance();
		if (!database::configured()) throw new RuntimeException('No database for the '.config::get('environment').' environment');
		self::migrate();
	}

	static function table_ready() {
		return cms_store::table_exists('user');
	}

	// accounts from the first agent-friendly version lived in usersdata
	static function migrate() {
		static $done = false;
		if ($done) return;
		$done = true;
		if (database::$frozen) return;
		try {
			$tables = R::inspect();
			if (!in_array('usersdata', $tables) || (in_array('user', $tables) && R::count('user') > 0)) return;
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

	// Creates or updates an account. $login is an email or a username. A
	// null $role or $password keeps what an existing account has; a new
	// account needs a password and is a member unless $role says otherwise.
	static function save_user($login, $password, $role = 'member', $name = null) {
		return self::store_user($login, $password, $role, $name, false);
	}

	// Creates an account, or answers null when one has that login already:
	// it never changes an existing account. Sign-up uses it.
	static function create_user($login, $password, $role = 'member', $name = null) {
		return self::store_user($login, $password, $role, $name, true);
	}

	// The password is hashed first, since that is slow. Looking for the
	// account and storing it then happen in one transaction, so two at once
	// can't both find nothing and both make one.
	protected static function store_user($login, $password, $role, $name, $create_only) {
		self::connect();
		if ($role !== null && !isset(self::$roles[$role])) throw new InvalidArgumentException("Role must be one of: ".implode(', ', array_keys(self::$roles)));
		$hash = $password !== null ? password_hash($password, PASSWORD_DEFAULT) : null;
		return cms_records::transaction(function () use ($login, $hash, $role, $name, $create_only) {
			$user = self::find($login);
			if ($user && $create_only) return null;
			if (!$user) {
				if ($hash === null) throw new InvalidArgumentException('A new account needs a password');
				if ($role === null) $role = 'member';
				$user = R::dispense('user');
				$is_email = filter_var($login, FILTER_VALIDATE_EMAIL);
				$user->email = $is_email ? strtolower($login) : '';
				$user->username = $is_email ? '' : $login;
				$user->created_at = R::isoDateTime();
			}
			if ($name !== null || empty($user->name)) $user->name = $name !== null ? $name : preg_replace('/@.*$/', '', $login);
			if ($role !== null) $user->role = $role;
			if ($hash !== null) $user->password = $hash;
			R::store($user);
			return (int)$user->id;
		});
	}

	// the columns this model uses, for `raster schema --apply`
	static function schema() {
		return array('user' => array(
			'email' => '', 'username' => '', 'name' => '', 'password' => '', 'role' => 'member',
			'created_at' => '', 'last_login' => '', 'failed_count' => 0, 'failed_at' => '',
			'reset_hash' => '', 'reset_expires' => '', 'login_hash' => '', 'login_expires' => '',
		));
	}

	// what a site with accounts needs: the reset email's view, the pages
	// that only work with PHP behind them (the sitemap and a static export
	// leave them out) and the settings that change them
	static function requires() {
		return array(
			'emails' => array('render.authentication.forgot' => array('_email/password_reset')),
			'pages' => array(config::get('login_page', 'login'), 'register', 'forgot', config::get('reset_page', 'reset'), 'account'),
			'settings' => array('registration', 'login_page', 'after_login', 'reset_page', 'password_min_length', 'protected'),
		);
	}

	static function has_users() {
		return self::table_ready() && R::count('user') > 0;
	}

	// id of the account, or false; also upgrades old md5 hashes
	static function check_login($login, $password) {
		$user = self::find($login);
		if (!$user || !is_string($password) || $password === '') return false;
		if (!self::password_ok($user, $password)) return false;
		$hash = (string)$user->password;
		$values = array('failed_count' => 0, 'last_login' => R::isoDateTime());
		if (preg_match('/^[a-f0-9]{32}$/', $hash) || password_needs_rehash($hash, PASSWORD_DEFAULT)) $values['password'] = password_hash($password, PASSWORD_DEFAULT);
		self::update_quietly($user->id, $values);
		return (int)$user->id;
	}

	// Whether $password is the account's. Five wrong passwords lock the
	// account for 15 minutes, for logins and the account form alike; once
	// 15 minutes have passed since the last one, counting starts again. The
	// try is counted before the slow check, read and written in the write
	// lock, so guesses sent at once each count. The caller sets
	// failed_count back to 0 when it was right.
	protected static function password_ok($user, $password) {
		$counted = true;
		try {
			$counted = cms_records::transaction(function () use ($user) {
				$fresh = R::load('user', (int)$user->id);
				$recent = (int)strtotime((string)$fresh->failed_at) > time() - 900;
				$count = $recent ? (int)$fresh->failed_count : 0;
				if ($count >= 5) return false;
				$fresh->failed_count = $count + 1;
				$fresh->failed_at = R::isoDateTime();
				R::store($fresh);
				return true;
			});
		} catch (Exception $e) {
			// a schema without the columns (`raster schema --apply` adds them)
			log::warning('authentication: the login lock can\'t count: '.$e->getMessage());
		}
		if (!$counted) return false;
		$hash = (string)$user->password;
		// Raster 1.x kept unsalted md5. Its first account was admin with the
		// password admin, which it had no screen to change: an old hash of
		// the account's own name never logs in (`raster user` sets a new one)
		if (preg_match('/^[a-f0-9]{32}$/', $hash)) return hash_equals($hash, md5($password)) && !hash_equals($hash, md5((string)$user->username));
		return password_verify($password, $hash);
	}

	// writes $values to the account, read again in the write lock, so what
	// another request changed meanwhile isn't written back as it was
	protected static function update_quietly($id, $values) {
		try {
			cms_records::transaction(function () use ($id, $values) {
				$user = R::load('user', (int)$id);
				foreach ($values as $key => $value) $user->$key = $value;
				R::store($user);
			});
		} catch (Exception $e) {
			log::warning('authentication: '.$e->getMessage());
		}
	}

	// ties a session to the password it was opened with, so changing the
	// password ends every other session
	static function fingerprint($hash) {
		return substr(hash('sha256', 'raster-session|'.(string)$hash), 0, 24);
	}

	// what the authentication.* events carry: id, email, name, role
	static function payload($id) {
		$bean = R::load('user', (int)$id);
		return array('id' => (int)$bean->id, 'email' => (string)$bean->email, 'name' => (string)$bean->name, 'role' => (string)$bean->role);
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
		$user = self::user();
		if ($user) event::dispatch('authentication.logged_out', array('id' => $user['id'], 'email' => $user['email'], 'name' => $user['name'], 'role' => $user['role']));
		util::session();
		if (session_status() === PHP_SESSION_ACTIVE) {
			$_SESSION = array();
			session_destroy();
		}
		// expired with the settings it was set with: a browser ignores the
		// expiry of a Secure cookie (or any __Host- one) sent without Secure
		if (PHP_SAPI !== 'cli' && !headers_sent()) {
			$params = session_get_cookie_params();
			setcookie(session_name(), '', array('expires' => time() - 3600, 'path' => $params['path'] ?: '/', 'domain' => $params['domain'],
				'secure' => $params['secure'], 'httponly' => $params['httponly'], 'samesite' => $params['samesite'] ?: 'Lax'));
		}
		self::$user = null;
	}

	// the logged in user as an array, or null
	static function user() {
		if (self::$user !== false) return self::$user;
		if (PHP_SAPI === 'cli' && defined('RASTER_RENDER_AS')) return self::$user = self::render_as(RASTER_RENDER_AS);
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

	// `raster render --as=…` (and MCP render_url's "as"): the page as that
	// person sees it, without a session. An account by email or username,
	// or a role (editor, admin, member) for someone with that role.
	// Command line only: the constant is never set for a web request. Never
	// in production, where it would show anyone's account without a password.
	protected static function render_as($who) {
		if (config::get('environment') === 'production') {
			fwrite(STDERR, "--as only works outside production: render as staff on a development copy of the site.\n");
			exit(2);
		}
		try {
			self::connect();
			$bean = self::find($who);
		} catch (Exception $e) {
			$bean = null;
		}
		if ($bean && $bean->id) {
			return array('id' => (int)$bean->id, 'name' => (string)$bean->name, 'email' => (string)$bean->email, 'username' => (string)$bean->username, 'role' => (string)$bean->role ?: 'member');
		}
		if (isset(self::$roles[$who])) {
			return array('id' => 0, 'name' => ucfirst($who), 'email' => '', 'username' => '', 'role' => $who);
		}
		fwrite(STDERR, "No account or role '$who'. Use an email or username (php bin/raster users), or editor, admin or member.\n");
		exit(2);
	}

	// can('member'), can('editor') (or 'edit'), can('admin')
	static function can($role) {
		if ($role === 'edit') $role = 'editor';
		$user = self::user();
		if (!$user || !isset(self::$roles[$role])) return false;
		$have = isset(self::$roles[$user['role']]) ? self::$roles[$user['role']] : 0;
		return $have >= self::$roles[$role];
	}

	// deprecated for util::url() (system/tools/deprecations.php)
	static function url($page) {
		return util::url($page);
	}

	// only paths on this site can be redirect targets
	static function safe_next($next) {
		$next = (string)$next;
		return preg_match('#^/(?!/)[^\s\\\\]*$#', $next) ? $next : '';
	}

	// ##Login links

	// A link that logs $login in, for `raster login-link`: the site's
	// address with ?raster_login=<token>, and &next= when $next is a path on
	// the site. It works once, within a minute. Like reset links, only the
	// token's sha256 is kept; a new link replaces one not used yet. Null when
	// there is no such account.
	static function login_link($login, $next = '') {
		self::connect();
		$user = self::find($login);
		if (!$user) return null;
		$token = bin2hex(random_bytes(24));
		$user->login_hash = hash('sha256', $token);
		$user->login_expires = date('Y-m-d H:i:s', time() + 60);
		R::store($user);
		$next = self::safe_next($next);
		return util::url('', $next !== '' ? array('raster_login' => $token, 'next' => $next) : array('raster_login' => $token));
	}

	// The id of the account a login link's token is for, or false. Finding
	// the token and clearing it happen in one transaction, so two requests
	// with one link can't both log in.
	protected static function use_login_token($token) {
		if (!is_string($token) || !preg_match('/^[a-f0-9]{48}$/', $token)) return false;
		try {
			self::connect();
			if (!self::table_ready()) return false;
			return cms_records::transaction(function () use ($token) {
				$user = R::findOne('user', ' login_hash = ? ', array(hash('sha256', $token)));
				if (!$user || strtotime((string)$user->login_expires) < time()) return false;
				$user->login_hash = '';
				// null, not '': in development MySQL has made the column a DATETIME
				$user->login_expires = null;
				$user->failed_count = 0;
				$user->last_login = R::isoDateTime();
				R::store($user);
				return (int)$user->id;
			});
		} catch (Exception $e) {
			log::warning('authentication: '.$e->getMessage());
			return false;
		}
	}

	// ?raster_login=<token>: logs in when the token is good, then asks for
	// the page again without it (or for ?next=), so the token leaves the
	// address bar. A bad or used token only does the redirect. The query
	// string keeps the page out of the page cache, both ways.
	protected function redeem_login_link() {
		$id = self::use_login_token($_GET['raster_login']);
		$next = '';
		if ($id) {
			self::log_in($id);
			event::dispatch('authentication.logged_in', self::payload($id));
			$next = self::safe_next(isset($_GET['next']) ? $_GET['next'] : '');
		}
		if ($next !== '') $this->redirect(rtrim(config::get('link_uri'), '/').$next);
		$query = $_GET;
		unset($query['raster_login'], $query['next']);
		$this->redirect(config::get('link_uri').ltrim(strtok((string)config::get('uri_string'), '?'), '/').($query ? '?'.http_build_query($query) : ''));
	}

	// ##Request hooks

	// bound to route_set: login links, template flags and protected pages
	function setup() {
		// the command line renders pages without a session to log in to
		if (isset($_GET['raster_login']) && PHP_SAPI !== 'cli') $this->redeem_login_link();
		$user = self::user();
		foreach (array('logged_in' => (bool)$user, 'logged_out' => !$user, 'is_member' => self::can('member'), 'is_editor' => self::can('editor'), 'is_admin' => self::can('admin')) as $flag => $value) {
			template::set($flag)->to($value);
		}
		$uri = '/'.ltrim((string)config::get('uri_string'), '/');
		// a pattern protects the URLs and the views it matches, in any case:
		// /Staff on a disk that ignores case still renders staff.html
		$view = '/'.controller::instance()->current_view;
		foreach ((array)config::get('protected', array()) as $pattern => $role) {
			$match = '%^/'.ltrim($pattern, '/').'%i';
			if (!preg_match($match, $uri) && ($view === '/' || !preg_match($match, $view))) continue;
			if (self::can($role)) continue;
			if ($user) {
				http_response_code(403);
				exit('You do not have access to this page.');
			}
			$this->redirect(util::url(config::get('login_page', 'login')).'?next='.rawurlencode(strtok($uri, '?')));
		}
		return true;
	}

	// on the command line only `raster render` follows it: the page ends
	// there with 303, and the address goes to stderr
	protected function redirect($location) {
		if (PHP_SAPI === 'cli' && !defined('RASTER_RENDER')) return;
		header('Location: '.$location, true, 303);
		if (PHP_SAPI === 'cli') fwrite(STDERR, "Location: $location\n");
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
		// the lock counts per account; this counts per address, so one
		// sender can't guess at many accounts, or try which ones exist:
		// over login_per_ip tries in an hour, none is checked
		$limit = config::get('login_per_ip', 20);
		if ($limit !== false && validation_spam::count_ip('authentication.login') > (int)$limit) {
			$v->raise('slow_down');
			return $this->form_again();
		}
		$login = util::post('login') !== false ? util::post('login') : (util::post('email') !== false ? util::post('email') : util::post('username'));
		$id = self::check_login($login, util::post('password'));
		if (!$id) {
			usleep(300000);
			event::dispatch('authentication.login_failed', array('login' => (string)$login));
			$v->raise('login_failed');
			return $this->form_again();
		}
		self::log_in($id);
		event::dispatch('authentication.logged_in', self::payload($id));
		$next = self::safe_next(util::get('next') ?: util::post('next'));
		$this->redirect($next !== '' ? rtrim(config::get('link_uri'), '/').$next : (config::get('after_login') ? util::url(config::get('after_login')) : config::get('link_uri')));
		return false;
	}

	// fields: name, email, password (and a matches('password', 'password_again') region if you like)
	function register() {
		if (config::get('registration', true) === false) return array();
		$v = validation::get();
		if (!$v->submitted()) return false;
		if (!$v->valid()) return $this->form_again();
		if ($reasons = validation_spam::holds($v->suspect(array()))) return $v->hold($reasons);
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
		// another sign-up with this email may have got there first
		$id = self::create_user($email, $password, 'member', $name !== '' ? $name : null);
		if (!$id) {
			$v->raise('email_taken');
			return $this->form_again();
		}
		self::log_in($id);
		event::dispatch('authentication.registered', self::payload($id));
		util::done('registered', config::get('after_login') ? util::url(config::get('after_login')) : config::get('link_uri'));
		return false;
	}

	// field: email. Always answers the same, so it can't tell who has an account.
	function forgot() {
		$v = validation::get();
		if (!$v->submitted()) return false;
		if (!$v->valid()) return $this->form_again();
		// a bot that asks too often gets the answer for an unknown address, so
		// it learns nothing and no mail goes out; other reasons don't matter
		// here, and too many from one IP only when spam_ip_blocks says so
		if (array_intersect(validation_spam::holds($v->suspect(array())), array('too_many_from_ip', 'too_many_from_email'))) {
			util::done('reset_sent');
			return false;
		}
		self::connect();
		$user = self::find(util::post('email'));
		// asked again within a few minutes: the link already sent still works
		$asked = $user && $user->reset_hash && $user->reset_expires ? strtotime((string)$user->reset_expires) - 3600 : false;
		if ($asked !== false && mail::sent_lately(date('Y-m-d H:i:s', $asked))) $user = null;
		if ($user && filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
			$token = bin2hex(random_bytes(24));
			$user->reset_hash = hash('sha256', $token);
			$user->reset_expires = date('Y-m-d H:i:s', time() + 3600);
			R::store($user);
			// through the site's the_mail, when it has one
			$mail = controller::model_class('mail');
			$reset = util::url(config::get('reset_page', 'reset'), array('token' => $token));
			if (!$mail::send_view('_email/password_reset', $user->email, array('reset_url' => $reset, 'name' => $user->name))) {
				log::error('Password reset email failed: '.$mail::$last_error);
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
		// null, not '': in development MySQL has made the column a DATETIME
		$user->reset_expires = null;
		$user->failed_count = 0;
		R::store($user);
		self::log_in($user->id);
		event::dispatch('authentication.password_changed', self::payload($user->id));
		util::done('password_changed', config::get('after_login') ? util::url(config::get('after_login')) : config::get('link_uri'));
		return false;
	}

	// fields: name, email, password (new, optional), current_password
	function account() {
		$user = self::user();
		if (!$user) {
			$this->redirect(util::url(config::get('login_page', 'login')).'?next='.rawurlencode(strtok('/'.ltrim((string)config::get('uri_string'), '/'), '?')));
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
		// counted against the account's lock, as logins are, so a session
		// left open can't be used to guess the password
		if ($changes_login) {
			if (!self::password_ok($bean, (string)util::post('current_password'))) {
				$v->raise('current_password_wrong');
				return $this->form_again();
			}
			$bean = R::load('user', $user['id']);
			$bean->failed_count = 0;
		}
		$new_email = $email !== '' && $email !== $bean->email;
		if ($new_email) {
			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $v->raise('email_invalid'); return $this->form_again(); }
			if (self::find($email)) { $v->raise('email_taken'); return $this->form_again(); }
		}
		if ($password !== '') {
			if (strlen($password) < (int)config::get('password_min_length', 8)) { $v->raise('password_short'); return $this->form_again(); }
			$bean->password = password_hash($password, PASSWORD_DEFAULT);
		}
		if (util::post('name') !== false) $bean->name = trim((string)util::post('name'));
		// the email is looked for again with the store, so two members can't
		// both take one
		$saved = cms_records::transaction(function () use ($bean, $email, $new_email) {
			if ($new_email) {
				$taken = self::find($email);
				if ($taken && (int)$taken->id !== (int)$bean->id) return false;
				$bean->email = $email;
			}
			R::store($bean);
			return true;
		});
		if (!$saved) { $v->raise('email_taken'); return $this->form_again(); }
		// a new password ends other sessions; this one continues
		if ($password !== '') self::log_in($bean->id);
		event::dispatch('authentication.account_saved', self::payload($bean->id));
		if ($password !== '') event::dispatch('authentication.password_changed', self::payload($bean->id));
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

	// ##Admin pages
	// The views 'protected' keeps for editors or admins, whoever is asking:
	// url, view, title (the view's <title>) and role, the strictest pattern
	// that matches the page. Views that need more (_item views) are left out.
	static function all_admin_pages() {
		require_once BASE.'tools/inspector.php';
		$inspector = new raster_inspector();
		$link = rtrim((string)config::get('link_uri'), '/');
		$protected = (array)config::get('protected', array());
		$pages = array();
		foreach ($inspector->views() as $view) {
			if (preg_match('#(^|/)_#', $view) || substr($view, -strlen($inspector->ext)) !== $inspector->ext) continue;
			$name = substr($view, 0, -strlen($inspector->ext));
			if (preg_match('/_item$/', $name)) continue;
			$role = null;
			foreach ($protected as $pattern => $needs) {
				if ($needs === 'edit') $needs = 'editor';
				if (!isset(self::$roles[$needs]) || !preg_match('%^/'.ltrim($pattern, '/').'%', '/'.$name)) continue;
				if ($role === null || self::$roles[$needs] > self::$roles[$role]) $role = $needs;
			}
			if ($role === null || self::$roles[$role] < self::$roles['editor']) continue;
			$pages[] = array(
				'url' => $name === config::get('default_view', 'index') ? $link.'/' : $link.'/'.$name,
				'view' => $name,
				'title' => self::view_title($inspector->theme_dir().'/'.$view, $name),
				'role' => $role,
			);
		}
		usort($pages, function ($a, $b) { return strcasecmp($a['title'], $b['title']); });
		return $pages;
	}

	// the text of a view's <title>, with the mock-up text of any annotation in it
	protected static function view_title($file, $name) {
		$html = (string)@file_get_contents($file);
		$title = '';
		if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
			$title = preg_replace('#<!--.*?-->#s', '', $m[1]);
			$title = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($title), ENT_QUOTES, 'UTF-8')));
		}
		return $title !== '' ? $title : ucfirst(str_replace(array('_', '-', '/'), ' ', $name));
	}
}
