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
*/
class mail
{
	public static $last_error = null;

	static function transport() {
		$dsn = getenv('RASTER_MAIL') ?: config::get('mail');
		if (!$dsn) $dsn = config::get('environment') === 'production' ? 'mail://' : 'log://';
		return $dsn;
	}

	static function from() {
		$from = getenv('RASTER_MAIL_FROM') ?: config::get('mail_from');
		if (!$from && !util::trusted_links()) $from = 'site@localhost';
		if (!$from) $from = 'site@'.preg_replace('/:\d+$/', '', (string)config::get('host', 'localhost'));
		return self::clean_header($from);
	}

	static function clean_header($value) {
		return trim(str_replace(array("\r", "\n"), '', (string)$value));
	}

	static function address($from) {
		return preg_match('/<([^>]+)>/', $from, $m) ? $m[1] : $from;
	}

	// Renders a view and sends it. Returns true when it was handed over.
	static function send_view($view, $to, $vars = array(), $headers = array()) {
		if (!util::trusted_links()) {
			self::$last_error = 'Set RASTER_URL (or config site_url) to the site address before sending email from the site';
			log::error('Mail: '.self::$last_error);
			return false;
		}
		$html = controller::render_view($view, $vars);
		$subject = preg_match('/<title>(.*?)<\/title>/is', $html, $m) ? html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES) : 'Message from '.config::get('host');
		return self::send($to, $subject, $html, null, $headers);
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

	static function send($to, $subject, $html, $text = null, $headers = array()) {
		self::$last_error = null;
		$to = self::clean_header($to);
		if (!filter_var(self::address($to), FILTER_VALIDATE_EMAIL)) {
			self::$last_error = "Invalid recipient: $to";
			return false;
		}
		$text = $text === null ? self::text_version($html) : $text;
		$boundary = 'raster-'.bin2hex(random_bytes(8));
		$host = preg_replace('/:\d+$/', '', (string)config::get('host', 'localhost'));
		$all = array(
			'From' => self::from(),
			'To' => $to,
			'Subject' => '=?UTF-8?B?'.base64_encode(self::clean_header($subject)).'?=',
			'Date' => date('r'),
			'Message-ID' => '<'.bin2hex(random_bytes(12)).'@'.$host.'>',
			'MIME-Version' => '1.0',
			'Content-Type' => 'multipart/alternative; boundary="'.$boundary.'"',
		);
		foreach ($headers as $name => $value) $all[self::clean_header($name)] = self::clean_header($value);
		$body = "--$boundary\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
			.chunk_split(base64_encode($text))
			."--$boundary\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
			.chunk_split(base64_encode($html))
			."--$boundary--\r\n";

		$dsn = self::transport();
		$scheme = strtolower((string)strstr($dsn, ':', true));
		try {
			switch ($scheme) {
				case 'log': return self::via_log($all, $body);
				case 'mail': return self::via_mail($all, $body);
				case 'smtp':
				case 'smtps': return self::via_smtp($dsn, $all, $body);
				default: throw new RuntimeException("Unknown mail transport '$dsn'");
			}
		} catch (Exception $e) {
			self::$last_error = $e->getMessage();
			log::error('Mail: '.$e->getMessage());
			return false;
		}
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
		$path = substr(self::transport(), strlen('log://'));
		$dir = $path !== '' ? rtrim($path, '/').'/' : APPBASE.'data/mail/';
		if (!is_dir($dir)) mkdir($dir, 0775, true);
		// names sort in the order the mails were sent
		list($usec, $sec) = explode(' ', microtime());
		$file = $dir.date('Ymd-His', (int)$sec).'-'.substr($usec, 2, 6).'-'.bin2hex(random_bytes(2)).'.eml';
		file_put_contents($file, self::header_block($headers)."\r\n".$body);
		return true;
	}

	protected static function via_mail($headers, $body) {
		return mail($headers['To'], $headers['Subject'], $body, self::header_block($headers, array('To', 'Subject')));
	}

	protected static function via_smtp($dsn, $headers, $body) {
		$parts = parse_url($dsn);
		$secure = $parts['scheme'] === 'smtps';
		$port = isset($parts['port']) ? $parts['port'] : ($secure ? 465 : 587);
		$user = isset($parts['user']) ? rawurldecode($parts['user']) : null;
		$pass = isset($parts['pass']) ? rawurldecode($parts['pass']) : null;
		$socket = @stream_socket_client(($secure ? 'ssl://' : 'tcp://').$parts['host'].':'.$port, $errno, $errstr, 15);
		if (!$socket) throw new RuntimeException("SMTP connection failed: $errstr");
		stream_set_timeout($socket, 15);
		$read = function () use ($socket) {
			$response = '';
			while (($line = fgets($socket, 515)) !== false) {
				$response .= $line;
				if (strlen($line) < 4 || $line[3] === ' ') break;
			}
			return $response;
		};
		$command = function ($line, $expect) use ($socket, $read) {
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
		$command('MAIL FROM:<'.self::address($headers['From']).'>', 250);
		$command('RCPT TO:<'.self::address($headers['To']).'>', 250);
		$command('DATA', 354);
		$data = self::header_block($headers)."\r\n".$body;
		$data = preg_replace('/^\./m', '..', $data);
		$command($data."\r\n.", 250);
		fwrite($socket, "QUIT\r\n");
		fclose($socket);
		return true;
	}
}
