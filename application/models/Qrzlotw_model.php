<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * QRZ.com "Import from LoTW" (QRZ Logbook pulls new confirmations from LoTW).
 *
 * PHP port of the reference implementation qrz_lotw.py:
 *   1. login at https://www.qrz.com (login page -> login-handshake -> /login)
 *   2. open https://logbook.qrz.com/logbook and read bookid/sbook
 *   3. GET https://logbook.qrz.com/?op=lotw_get_all&lotw_pw=...
 *   4. poll op=lotwchk until the import has finished
 *
 * The LoTW password is a dependency: users.user_lotw_password has to be set.
 * QRZ.com credentials live in users.user_qrzcom_name / _password / _totp_secret.
 */
class Qrzlotw_model extends CI_Model {

	private $qr_base      = 'https://www.qrz.com';
	private $logbook_base = 'https://logbook.qrz.com';
	private $user_agent   = 'Mozilla Chrome Safari';
	private $timeout      = 90;

	/**
	 * Users that have QRZ.com credentials configured. Optionally limited to
	 * a single user (used for the manual "sync now" button).
	 */
	public function get_users_to_sync($user_id = null) {
		$this->db->select('user_id, user_name, user_callsign, user_lotw_password, user_qrzcom_name, user_qrzcom_password, user_qrzcom_totp_secret');
		$this->db->from('users');
		$this->db->where("COALESCE(user_qrzcom_name, '') != ''", null, false);
		$this->db->where("COALESCE(user_qrzcom_password, '') != ''", null, false);
		if ($user_id !== null) {
			$this->db->where('user_id', $user_id);
		}
		$this->db->order_by('user_id', 'ASC');
		return $this->db->get()->result();
	}

	/**
	 * Run the QRZ LoTW import for a single user.
	 *
	 * @return array{ok:bool,message:string,log:string}
	 */
	public function sync_user($user) {
		if (!empty($user->user_callsign)) {
			$label = $user->user_callsign;
		} else {
			$label = $user->user_name;
		}

		// --- dependency checks -------------------------------------------------
		if (empty($user->user_lotw_password)) {
			return array(
				'ok' => false,
				'message' => sprintf(__("User %s: no LoTW password configured (Third Party Services), skipping."), $label),
				'log' => ''
			);
		}
		if (empty($user->user_qrzcom_name) || empty($user->user_qrzcom_password)) {
			return array(
				'ok' => false,
				'message' => sprintf(__("User %s: no QRZ.com credentials configured, skipping."), $label),
				'log' => ''
			);
		}

		try {
			$bookid = null;
			$sbook  = null;
			$log    = '';

			$ch = $this->open_curl();

			$this->login($ch, $user->user_qrzcom_name, $user->user_qrzcom_password, $user->user_qrzcom_totp_secret);

			$html = $this->_request($ch, 'GET', $this->logbook_base . '/logbook', null, array(), $this->timeout);
			list($bookid, $sbook) = $this->logbook_ids($html);

			log_message('debug', 'QRZLoTW: ' . $label . ' logged in (bookid=' . $bookid . ', sbook=' . $sbook . ')');

			$result = $this->_json_get($ch, $this->logbook_base . '/', array(
				'op'           => 'lotw_get_all',
				'bid'          => $bookid,
				'lotw_pw'      => $user->user_lotw_password,
				'sbook'        => $sbook,
				'lotw_dl_type' => 'new',
				'after_date'   => '',
				'before_date'  => '',
			), 300);

			if (($result['status'] ?? '') === 'ok') {
				$log = $this->poll_progress($ch, $sbook);
			}

			$message = $this->html_to_text((string)($result['msg'] ?? ''));
			if ($message === '') {
				$message = (string)($result['status'] ?? __('unknown response'));
			}

			$ok = (($result['status'] ?? '') === 'ok');

			curl_close($ch);

			return array(
				'ok'      => $ok,
				'message' => sprintf(__("User %s: %s"), $label, $message),
				'log'     => $log
			);
		} catch (Exception $e) {
			// curl handle is closed by destructor/GC; nothing sensitive is kept
			log_message('error', 'QRZLoTW: ' . $label . ': ' . $e->getMessage());
			return array(
				'ok'      => false,
				'message' => sprintf(__("User %s: QRZ LoTW import failed: %s"), $label, $e->getMessage()),
				'log'     => ''
			);
		}
	}

	// ---------------------------------------------------------------------------
	// QRZ.com login (mirrors qrz_lotw.py)
	// ---------------------------------------------------------------------------
	private function login($ch, $username, $password, $totp_secret = null) {
		$page = $this->_request($ch, 'GET', $this->qr_base . '/login', null, array(), $this->timeout);

		if (!preg_match("/loginTicket['\"]?\\s*:\\s*['\"]([0-9A-Za-z]+)['\"]/", $page, $m)) {
			throw new Exception(__("Could not find loginTicket on the QRZ login page"));
		}
		$ticket = $m[1];

		$step1 = $this->_post_json($ch, $this->qr_base . '/login-handshake', array(
			'loginTicket' => $ticket,
			'username'    => $username,
			'step'        => '1',
		), $this->qr_base . '/login');

		if (!empty($step1['error'])) {
			throw new Exception(sprintf(__("QRZ username rejected: %s"), $step1['message'] ?? ''));
		}

		$step2 = $this->_post_json($ch, $this->qr_base . '/login-handshake', array(
			'loginTicket' => $ticket,
			'username'    => $username,
			'password'    => $password,
			'step'        => '2',
		), $this->qr_base . '/login');

		if (!empty($step2['error'])) {
			throw new Exception(sprintf(__("QRZ login rejected: %s"), $step2['message'] ?? ''));
		}

		$code = '';
		if (!empty($step2['twofactor'])) {
			if (!empty($totp_secret)) {
				$code = $this->totp($totp_secret);
			}
			if ($code === '') {
				throw new Exception(__("QRZ.com requires a 2FA code, but no TOTP secret is configured for this user"));
			}
		}

		$this->_request($ch, 'POST', $this->qr_base . '/login', http_build_query(array(
			'login_ref' => '',
			'username'  => $username,
			'password'  => $password,
			'2fcode'    => $code,
			'target'    => $this->logbook_base,
			'flush'     => '1',
		)), array(
			'Content-Type: application/x-www-form-urlencoded',
			'Referer: ' . $this->qr_base . '/login',
			'Origin: ' . $this->qr_base,
		), $this->timeout);

		$logbook = $this->_request($ch, 'GET', $this->logbook_base . '/logbook', null, array(), $this->timeout);
		$effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

		if (strpos((string)$effective_url, '/login') !== false || strpos($logbook, 'id="login-form"') !== false) {
			throw new Exception(__("QRZ login failed: the QRZ session was not established"));
		}
		if (strpos($logbook, 'Logbook by QRZ.COM') === false && strpos($logbook, 'id="listop"') === false) {
			throw new Exception(__("QRZ login did not reach the QRZ Logbook page"));
		}
	}

	private function _post_json($ch, $url, $data, $referer) {
		$body = http_build_query($data);
		$response = $this->_request($ch, 'POST', $url, $body, array(
			'Accept: application/json, text/javascript, */*; q=0.01',
			'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
			'X-Requested-With: XMLHttpRequest',
			'Referer: ' . $referer,
			'Origin: ' . $this->qr_base,
		), $this->timeout);

		$json = json_decode($response, true);
		if (!is_array($json)) {
			throw new Exception(sprintf(__("Expected JSON from %s, got: %s"), $url, substr($response, 0, 300)));
		}
		return $json;
	}

	private function _json_get($ch, $url, $params, $timeout = null) {
		$url .= '?' . http_build_query($params);
		$response = $this->_request($ch, 'GET', $url, null, array(
			'Accept: application/json, text/javascript, */*; q=0.01',
			'X-Requested-With: XMLHttpRequest',
			'Referer: ' . $this->logbook_base . '/logbook',
		), $timeout === null ? $this->timeout : $timeout);

		$json = json_decode($response, true);
		if (!is_array($json)) {
			throw new Exception(sprintf(__("Expected JSON from the QRZ Logbook, got: %s"), substr($response, 0, 300)));
		}
		return $json;
	}

	private function _request($ch, $method, $url, $body = null, $headers = array(), $timeout = 90) {
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
		curl_setopt($ch, CURLOPT_ENCODING, '');
		curl_setopt($ch, CURLOPT_USERAGENT, $this->user_agent);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

		$response = curl_exec($ch);
		if ($response === false) {
			throw new Exception(sprintf(__("HTTP request to %s failed: %s"), $url, curl_error($ch)));
		}

		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($status >= 400) {
			throw new Exception(sprintf(__("HTTP %d from %s"), $status, $url));
		}

		return $response;
	}

	private function open_curl() {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_COOKIEFILE, '');
		curl_setopt($ch, CURLOPT_COOKIEJAR, '');
		return $ch;
	}

	// ---------------------------------------------------------------------------
	// QRZ Logbook helpers
	// ---------------------------------------------------------------------------
	private function logbook_ids($html) {
		$scope = $html;
		if (preg_match('/<form\b[^>]*\bid=["\']listop["\'][^>]*>.*?<\/form>/is', $html, $m)) {
			$scope = $m[0];
		}

		$bookid = $this->_input_value($scope, 'bookid');
		$sbook  = $this->_input_value($scope, 'sbook');

		if ($bookid === null) {
			$bookid = $this->_input_value($html, 'bookid');
		}
		if ($sbook === null) {
			$sbook = $this->_input_value($html, 'sbook');
		}

		if ($bookid === null || $bookid === '' || !ctype_digit((string)$bookid)) {
			throw new Exception(__("Could not determine the QRZ Logbook bookid"));
		}

		return array($bookid, ($sbook !== null && $sbook !== '') ? $sbook : '0');
	}

	private function _input_value($html, $name) {
		if (!preg_match_all('/<input\b[^>]*>/i', $html, $tags)) {
			return null;
		}
		foreach ($tags[0] as $tag) {
			if (preg_match('/\bname=["\']' . preg_quote($name, '/') . '["\']/i', $tag)) {
				if (preg_match('/\bvalue=["\']([^"\']*)["\']/i', $tag, $v)) {
					return html_entity_decode($v[1], ENT_QUOTES, 'UTF-8');
				}
				return '';
			}
		}
		return null;
	}

	private function poll_progress($ch, $sbook) {
		$current = '';
		$chunks  = array();

		for ($i = 0; $i < 120; $i++) {
			$data = $this->_json_get($ch, $this->logbook_base . '/', array(
				'op'      => 'lotwchk',
				'sbook'   => (string)$sbook,
				'curtext' => $current,
			), $this->timeout);

			if (($data['update'] ?? '') === 'yes') {
				$raw     = (string)($data['newtext'] ?? '');
				$text    = str_replace(array('<--N-->', "<--R-->\r"), array("\n", "\r"), $raw);
				$chunks[] = $text;
				$current  = $raw;
			}

			if (($data['timer'] ?? '') !== 'yes') {
				break;
			}
			sleep(3);
		}

		return trim(implode("\n", $chunks));
	}

	private function html_to_text($value) {
		$value = preg_replace('/<br\s*\/?>/i', "\n", $value);
		$value = preg_replace('/<\/tr>/i', "\n", $value);
		$value = preg_replace('/<\/t[dh]>/i', ' | ', $value);
		$value = strip_tags($value);
		return trim(html_entity_decode($value, ENT_QUOTES, 'UTF-8'));
	}

	// ---------------------------------------------------------------------------
	// TOTP (RFC 6238, SHA1, 6 digits / 30s) - for QRZ.com 2FA
	// ---------------------------------------------------------------------------
	private function totp($secret) {
		$secret = strtoupper(preg_replace('/\s+/', '', (string)$secret));
		$secret .= str_repeat('=', (8 - strlen($secret) % 8) % 8);

		$key = $this->_base32_decode($secret);
		if ($key === '') {
			throw new Exception(__("Invalid TOTP secret"));
		}

		$counter = intdiv(time(), 30);
		$binary  = pack('N2', 0, $counter);
		$hash    = hash_hmac('sha1', $binary, $key, true);

		$offset = ord($hash[19]) & 0x0F;
		$value  = ((ord($hash[$offset]) & 0x7F) << 24)
		        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
		        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
		        | (ord($hash[$offset + 3]) & 0xFF);

		return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
	}

	private function _base32_decode($input) {
		$map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$input = rtrim($input, '=');
		$buffer = 0;
		$bitsLeft = 0;
		$output = '';

		for ($i = 0; $i < strlen($input); $i++) {
			$val = strpos($map, $input[$i]);
			if ($val === false) {
				continue;
			}
			$buffer = ($buffer << 5) | $val;
			$bitsLeft += 5;
			if ($bitsLeft >= 8) {
				$bitsLeft -= 8;
				$output .= chr(($buffer >> $bitsLeft) & 0xFF);
			}
		}

		return $output;
	}
}
