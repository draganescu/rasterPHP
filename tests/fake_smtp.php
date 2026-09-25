<?php
// A tiny SMTP server for tests:
//   php tests/fake_smtp.php <port> <transcript-file> [plain|starttls|smtps] [cert.pem] [host]
// plain never offers STARTTLS; starttls offers and performs it; smtps is TLS
// from the first byte. It accepts AUTH LOGIN and appends every session
// (commands and message) to the transcript file.
if ($argc < 3) exit("usage: fake_smtp.php <port> <file> [mode] [cert]\n");
$mode = isset($argv[3]) ? $argv[3] : 'plain';
$cert = isset($argv[4]) && $argv[4] !== '' ? $argv[4] : null;
$host = isset($argv[5]) ? $argv[5] : '127.0.0.1';
$context = stream_context_create(array('ssl' => array('local_cert' => $cert, 'verify_peer' => false, 'allow_self_signed' => true)));
$server = stream_socket_server('tcp://'.$host.':'.(int)$argv[1], $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!$server) exit("cannot listen: $errstr\n");
$log = $argv[2];
while ($client = @stream_socket_accept($server, -1)) {
	$session = "MODE: $mode\n";
	if ($mode === 'smtps' && !@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
		file_put_contents($log, $session."TLS FAILED\n---\n", FILE_APPEND);
		fclose($client);
		continue;
	}
	$say = function ($line) use ($client, &$session) {
		fwrite($client, $line."\r\n");
		$session .= "S: $line\n";
	};
	$say('220 fake.smtp ready');
	$in_data = false;
	$data = '';
	$secure = $mode === 'smtps';
	while (($line = fgets($client)) !== false) {
		if ($in_data) {
			if (rtrim($line, "\r\n") === '.') {
				$in_data = false;
				$session .= "DATA:\n".$data."\n";
				$say('250 queued');
				$data = '';
			} else {
				$data .= $line;
			}
			continue;
		}
		$session .= 'C: '.rtrim($line)."\n";
		$command = strtoupper(substr(trim($line), 0, 8));
		if (strpos($command, 'EHLO') === 0) {
			$offer = ($mode === 'starttls' && !$secure) ? "250-STARTTLS\r\n" : '';
			fwrite($client, "250-fake.smtp\r\n".$offer."250 AUTH LOGIN\r\n");
			$session .= 'S: 250 '.($offer ? 'STARTTLS ' : '')."AUTH LOGIN\n";
		} elseif ($command === 'STARTTLS') {
			$say('220 go ahead');
			$secure = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
			$session .= $secure ? "TLS STARTED\n" : "TLS FAILED\n";
			if (!$secure) break;
		} elseif (strpos($command, 'AUTH') === 0) {
			$session .= $secure ? "AUTH OVER TLS\n" : "AUTH IN PLAIN TEXT\n";
			$say('334 VXNlcm5hbWU6');
		} elseif (strpos($command, 'MAIL') === 0 || strpos($command, 'RCPT') === 0) {
			$say('250 ok');
		} elseif (strpos($command, 'DATA') === 0) {
			$say('354 go ahead');
			$in_data = true;
		} elseif (strpos($command, 'QUIT') === 0) {
			$say('221 bye');
			break;
		} elseif (preg_match('/^[A-Za-z0-9+\/=]+$/', trim($line))) {
			// the two AUTH LOGIN answers (base64 user and password)
			$say(strpos($session, 'S: 334 UGFzc3dvcmQ6') === false ? '334 UGFzc3dvcmQ6' : '235 authenticated');
		} else {
			$say('502 unknown');
		}
	}
	fclose($client);
	file_put_contents($log, $session."---\n", FILE_APPEND);
}
