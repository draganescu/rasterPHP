<?php
// A tiny SMTP server for tests: php tests/fake_smtp.php <port> <transcript-file>
// It never offers STARTTLS, accepts AUTH LOGIN, and appends every session
// (commands and message) to the transcript file.
if ($argc < 3) exit("usage: fake_smtp.php <port> <file>\n");
$server = stream_socket_server('tcp://0.0.0.0:'.(int)$argv[1], $errno, $errstr);
if (!$server) exit("cannot listen: $errstr\n");
$log = $argv[2];
while ($client = @stream_socket_accept($server, -1)) {
	$session = '';
	$say = function ($line) use ($client, &$session) {
		fwrite($client, $line."\r\n");
		$session .= "S: $line\n";
	};
	$say('220 fake.smtp ready');
	$in_data = false;
	$data = '';
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
		$command = strtoupper(substr(trim($line), 0, 4));
		switch ($command) {
			case 'EHLO': fwrite($client, "250-fake.smtp\r\n250 AUTH LOGIN\r\n"); $session .= "S: 250 AUTH LOGIN\n"; break;
			case 'AUTH': $say('334 VXNlcm5hbWU6'); break;
			case 'MAIL':
			case 'RCPT': $say('250 ok'); break;
			case 'DATA': $say('354 go ahead'); $in_data = true; break;
			case 'QUIT': $say('221 bye'); break 2;
			default:
				// the two AUTH LOGIN answers (base64 user and password)
				if (preg_match('/^[A-Za-z0-9+\/=]+$/', trim($line))) {
					$say(strpos($session, 'S: 334 UGFzc3dvcmQ6') === false ? '334 UGFzc3dvcmQ6' : '235 authenticated');
				} else {
					$say('502 unknown');
				}
		}
	}
	fclose($client);
	file_put_contents($log, $session."---\n", FILE_APPEND);
}
