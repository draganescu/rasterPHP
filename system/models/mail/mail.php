<?php
/**
* Mail
*
* Emails are views: mail::send_view('email/welcome', 'ada@example.com',
* array('name' => 'Ada')) renders views/<theme>/email/welcome.html, uses its
* <title> as the subject, and the values as <!-- print.self.name -->.
*
* Where mail goes is one setting, RASTER_MAIL (or config mail):
*   log://                        write .eml files to application/data/mail/ (development default)
*   log:///path/to/folder         write them somewhere else
*   mail://                       PHP's mail() function (production default)
*   smtp://user:pass@host:587     SMTP with STARTTLS
*   smtps://user:pass@host:465    SMTP over TLS
* The sender is RASTER_MAIL_FROM (or config mail_from), e.g. "Site <hi@example.com>".
*
* In a web request mail goes out after the answer: send() checks and keeps
* the message, and send_kept() sends what was kept once the visitor has the
* page, so a slow mail server never holds a form. The command line sends at
* once.
*
* Many mails at once (a newsletter issue) share one SMTP connection:
*   mail::keep_open(); ... mail::send(...) ...; mail::close();
*/
class mail
{
	// seconds to wait for the SMTP server to connect or answer
	const TIMEOUT = 5;
	// a confirmation or reset link asked for again within this many seconds
	// is not mailed again
	const RESEND_AFTER = 300;

	public static $last_error = null;
	// between keep_open() and close(): whether to keep the connection, and
	// the one that is open (array(dsn, command))
	protected static $keep = false;
	protected static $open = null;
	// the messages a web request sends after its answer (array(to, subject, headers, body))
	protected static $kept = array();

	static function transport() {
		$dsn = getenv('RASTER_MAIL') ?: config::get('mail');
		if (!$dsn) $dsn = config::get('environment') === 'production' ? 'mail://' : 'log://';
		return $dsn;
	}

	// the transport without user, password or options, for printing; the
	// user and password run to the last '@' before the path, as parse_url()
	// (and so smtp_connect()) reads them, so an '@' in a password is cut too
	static function destination() {
		return preg_replace('#^([a-z]+://)[^/?\#]*@#i', '$1', strtok(static::transport(), '?'));
	}

	// the SMTP connection stays open for the mails that follow, until close()
	static function keep_open() {
		static::$keep = true;
	}

	static function close() {
		static::$keep = false;
		if (!static::$open) return;
		$command = static::$open[1];
		static::$open = null;
		$command(false, 0);
	}

	static function from() {
		$from = getenv('RASTER_MAIL_FROM') ?: config::get('mail_from');
		if (!$from && !util::trusted_links()) $from = 'site@localhost';
		if (!$from) $from = 'site@'.preg_replace('/:\d+$/', '', (string)config::get('host', 'localhost'));
		return static::clean_header($from);
	}

	static function clean_header($value) {
		return trim(str_replace(array("\r", "\n"), '', (string)$value));
	}

	static function address($from) {
		return preg_match('/<([^>]+)>/', $from, $m) ? $m[1] : $from;
	}

	// Renders a view and sends it. Returns true when it was handed over (or,
	// in a web request, kept to send after the answer).
	static function send_view($view, $to, $vars = array(), $headers = array()) {
		if (!util::trusted_links()) {
			static::$last_error = 'Set RASTER_URL (or config site_url) to the site address before sending email from the site';
			log::error('Mail: '.static::$last_error);
			return false;
		}
		$html = controller::render_view($view, $vars);
		$subject = preg_match('/<title>(.*?)<\/title>/is', $html, $m) ? html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES) : 'Message from '.config::get('host');
		return static::send($to, $subject, $html, null, $headers);
	}

	static function text_version($html) {
		$html = preg_replace('#<(head|style|script|title)\b.*?</\1>#is', '', $html);
		$html = preg_replace_callback('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', function ($m) {
			$label = trim(strip_tags($m[2]));
			return $label === $m[1] ? $m[1] : $label.' ('.$m[1].')';
		}, $html);
		$html = preg_replace('#<(br|/p|/h[1-6]|/li|/div|/tr)\b[^>]*>#i', "\n", $html);
		$text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
		$text = preg_replace("/[ \t]+/", ' ', $text);
		$text = preg_replace("/^[ \t]+/m", "", $text);
		return trim(preg_replace("/\n\s*\n+/", "\n\n", $text));
	}

	// Sends a message, or in a web request keeps it for send_kept(). Returns
	// false for a recipient or transport that can't work, and true once the
	// message is handed over or kept; mail.failed says when a kept one
	// could not be sent.
	static function send($to, $subject, $html, $text = null, $headers = array()) {
		static::$last_error = null;
		$to = static::clean_header($to);
		if (!filter_var(static::address($to), FILTER_VALIDATE_EMAIL)) {
			static::$last_error = "Invalid recipient: $to";
			return false;
		}
		$scheme = strtolower((string)strstr(static::transport(), ':', true));
		if (!in_array($scheme, array('log', 'mail', 'smtp', 'smtps'), true)) {
			static::$last_error = "Unknown mail transport '".static::transport()."'";
			log::error('Mail: '.static::$last_error);
			event::dispatch('mail.failed', array('to' => $to, 'subject' => (string)$subject, 'error' => static::$last_error));
			return false;
		}
		$text = $text === null ? static::text_version($html) : $text;
		$boundary = 'raster-'.bin2hex(random_bytes(8));
		$host = preg_replace('/:\d+$/', '', (string)config::get('host', 'localhost'));
		$all = array(
			'From' => static::named(static::from()),
			'To' => static::named($to),
			'Subject' => static::encoded(static::clean_header($subject)),
			'Date' => date('r'),
			'Message-ID' => '<'.bin2hex(random_bytes(12)).'@'.$host.'>',
			'MIME-Version' => '1.0',
			'Content-Type' => 'multipart/alternative; boundary="'.$boundary.'"',
		);
		foreach ($headers as $name => $value) $all[static::clean_header($name)] = static::clean_header($value);
		$body = "--$boundary\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
			.chunk_split(base64_encode($text))
			."--$boundary\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
			.chunk_split(base64_encode($html))
			."--$boundary--\r\n";
		$message = array($to, (string)$subject, $all, $body);
		if (PHP_SAPI === 'cli' || static::$keep) return static::deliver($message);
		if (!static::$kept) register_shutdown_function(array(get_called_class(), 'send_kept'));
		static::$kept[] = $message;
		return true;
	}

	// Sends what a web request kept, after its answer: the session is
	// written (so the visitor's next request isn't held), the answer
	// finished, and the mails go out over one connection. A failure is
	// logged and sends mail.failed; once the server can't be reached, the
	// rest fail at once.
	static function send_kept() {
		if (!static::$kept) return;
		$kept = static::$kept;
		static::$kept = array();
		if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
		static::finish_answer();
		ignore_user_abort(true);
		$smtp = in_array(strtolower((string)strstr(static::transport(), ':', true)), array('smtp', 'smtps'), true);
		$down = null;
		static::keep_open();
		foreach ($kept as $message) {
			if ($down !== null) {
				static::failed($message, $down);
				continue;
			}
			// a failure that leaves no connection open was the server itself
			if (!static::deliver($message) && $smtp && !static::$open) $down = static::$last_error;
		}
		static::close();
	}

	// the visitor gets the whole answer now, while PHP goes on
	protected static function finish_answer() {
		if (function_exists('fastcgi_finish_request')) return fastcgi_finish_request();
		if (function_exists('frankenphp_finish_request')) return frankenphp_finish_request();
		if (function_exists('litespeed_finish_request')) return litespeed_finish_request();
		while (ob_get_level() > 0 && @ob_end_flush());
		flush();
	}

	// hands a message to the transport; true when it was accepted
	protected static function deliver($message) {
		list($to, $subject, $all, $body) = $message;
		static::$last_error = null;
		$dsn = static::transport();
		$scheme = strtolower((string)strstr($dsn, ':', true));
		try {
			switch ($scheme) {
				case 'log': $sent = static::via_log($all, $body); break;
				case 'mail': $sent = static::via_mail($all, $body); break;
				case 'smtp':
				case 'smtps': $sent = static::via_smtp($dsn, $all, $body); break;
				default: throw new RuntimeException("Unknown mail transport '$dsn'");
			}
			if (!$sent) throw new RuntimeException('The '.$scheme.' transport did not accept the message');
		} catch (Exception $e) {
			return static::failed($message, $e->getMessage());
		}
		event::dispatch('mail.sent', array('to' => $to, 'subject' => $subject));
		return true;
	}

	protected static function failed($message, $error) {
		static::$last_error = $error;
		log::error('Mail: '.$error);
		event::dispatch('mail.failed', array('to' => $message[0], 'subject' => $message[1], 'error' => $error));
		return false;
	}

	// text for a header, as encoded words of at most 75 characters when it
	// isn't plain ASCII
	protected static function encoded($text) {
		return mb_encode_mimeheader((string)$text, 'UTF-8', 'B');
	}

	// "Name <address>" with the name encoded; a bare address as it is
	protected static function named($value) {
		if (!preg_match('/^(.*?)\s*<([^>]+)>$/', $value, $m) || trim($m[1]) === '') return $value;
		return static::encoded(trim($m[1])).' <'.$m[2].'>';
	}

	// whether a link mailed at $stamp (a date and time) went out within
	// RESEND_AFTER seconds, so asking again sends nothing
	static function sent_lately($stamp) {
		$time = $stamp ? strtotime((string)$stamp) : false;
		return $time !== false && $time > time() - static::RESEND_AFTER && $time <= time() + 60;
	}

	protected static function header_block($headers, $skip = array()) {
		$lines = '';
		foreach ($headers as $name => $value) {
			if (!in_array($name, $skip)) $lines .= "$name: $value\r\n";
		}
		return $lines;
	}

	protected static function via_log($headers, $body) {
		// log:// writes to application/data/mail/, log:///some/dir to that folder
		$path = substr(static::transport(), strlen('log://'));
		$dir = $path !== '' ? rtrim($path, '/').'/' : APPBASE.'data/mail/';
		// two mails at once may both find no folder
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Can\'t create the mail folder '.$dir);
		// names sort in the order the mails were sent
		list($usec, $sec) = explode(' ', microtime());
		$file = $dir.date('Ymd-His', (int)$sec).'-'.substr($usec, 2, 6).'-'.bin2hex(random_bytes(2)).'.eml';
		file_put_contents($file, static::header_block($headers)."\r\n".$body);
		return true;
	}

	// -f gives sendmail the sender for bounces, when it is a plain address
	protected static function via_mail($headers, $body) {
		$from = static::address($headers['From']);
		$envelope = preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+$/', $from) ? '-f'.$from : '';
		return mail($headers['To'], $headers['Subject'], $body, static::header_block($headers, array('To', 'Subject')), $envelope);
	}

	protected static function via_smtp($dsn, $headers, $body) {
		$command = null;
		// a kept connection says RSET first; one the server has dropped is opened again
		if (static::$open && static::$open[0] === $dsn) {
			try {
				static::$open[1]('RSET', 250);
				$command = static::$open[1];
			} catch (Exception $e) {
				static::$open[1](false, 0);
				static::$open = null;
			}
		}
		if (!$command) $command = static::smtp_connect($dsn);
		try {
			$command('MAIL FROM:<'.static::address($headers['From']).'>', 250);
			$command('RCPT TO:<'.static::address($headers['To']).'>', 250);
			$command('DATA', 354);
			$data = static::header_block($headers)."\r\n".$body;
			$data = preg_replace('/^\./m', '..', $data);
			$command($data."\r\n.", 250);
		} finally {
			if (static::$keep) static::$open = array($dsn, $command);
			else $command(false, 0);
		}
		return true;
	}

	// connects, says EHLO, STARTTLS and logs in. Returns the function that
	// sends a line and checks the answer; $command(false, 0) says QUIT and hangs up.
	protected static function smtp_connect($dsn) {
		$parts = parse_url($dsn);
		$secure = $parts['scheme'] === 'smtps';
		$port = isset($parts['port']) ? $parts['port'] : ($secure ? 465 : 587);
		$user = isset($parts['user']) ? rawurldecode($parts['user']) : null;
		$pass = isset($parts['pass']) ? rawurldecode($parts['pass']) : null;
		$socket = @stream_socket_client(($secure ? 'ssl://' : 'tcp://').$parts['host'].':'.$port, $errno, $errstr, static::TIMEOUT);
		if (!$socket) throw new RuntimeException("SMTP connection failed: $errstr");
		stream_set_timeout($socket, static::TIMEOUT);
		$read = function () use ($socket) {
			$response = '';
			while (($line = fgets($socket, 515)) !== false) {
				$response .= $line;
				if (strlen($line) < 4 || $line[3] === ' ') break;
			}
			return $response;
		};
		$command = function ($line, $expect) use ($socket, $read) {
			if ($line === false) {
				@fwrite($socket, "QUIT\r\n");
				return fclose($socket);
			}
			if ($line !== null) fwrite($socket, $line."\r\n");
			$response = $read();
			if ((int)substr($response, 0, 3) !== $expect) {
				throw new RuntimeException('SMTP error after '.($line === null ? 'connect' : strtok($line, ' ')).': '.trim($response));
			}
			return $response;
		};
		$helo = preg_replace('/:\d+$/', '', (string)config::get('host', 'localhost'));
		$command(null, 220);
		$ehlo = $command("EHLO $helo", 250);
		$local = in_array($parts['host'], array('localhost', '127.0.0.1', '::1'));
		$insecure = isset($parts['query']) && strpos($parts['query'], 'insecure=1') !== false;
		if (!$secure && stripos($ehlo, 'STARTTLS') === false && !$local && !$insecure) {
			throw new RuntimeException('The SMTP server does not offer STARTTLS; use smtps:// or add ?insecure=1 if you really mean it');
		}
		if (!$secure && stripos($ehlo, 'STARTTLS') !== false) {
			$command('STARTTLS', 220);
			if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('STARTTLS failed');
			$command("EHLO $helo", 250);
		}
		if ($user !== null) {
			$command('AUTH LOGIN', 334);
			$command(base64_encode($user), 334);
			$command(base64_encode((string)$pass), 235);
		}
		return $command;
	}
}
