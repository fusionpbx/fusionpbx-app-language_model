<?php

/**
 * language_model_openai class
 *
 */
class language_model_openai implements language_model_interface {

	private $api_key;
	public $api_url;
	public $api_model;
	public $stream;

	private $permission_read_url;

	public function __construct() {
		global $settings, $config;

		// Get the API settings
		$this->api_key = $settings->get('language_model', 'api_key', '');
		$this->api_url = $settings->get('language_model', 'api_url', '');
		$this->api_model = $settings->get('language_model', 'api_model', 'o4-mini');

		//get the config object if not already defined
		if (empty($config)) {
			$config = new config;
		}

		// URL reading is only permitted when the setting explicitly allows it
		$this->permission_read_url = $config->get('language_model.permissions.read.url', 'deny');
	}

	// Callback function to handle streaming data
	private function stream_callback($ch, $data) {
		// Process the data here (e.g., echo or write to a file)
		echo $data; // Example: output the data as it is received
		flush(); // Make sure the output is sent immediately
		ob_flush();
		return strlen($data); // Return the length of the data processed
	}

	/**
	 * Write a debug message to the language_model log file.
	 */
	private function debug_log(string $message): void {
		$log_file = '/var/log/fusionpbx/language_model_debug.log';
		$timestamp = date('Y-m-d H:i:s');
		$line = "[{$timestamp}] [openai] {$message}" . PHP_EOL;

		// Ensure the log directory exists
		$dir = dirname($log_file);
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}

		@file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
	}

	/**
	 * Safely fetch content from a URL.
	 * Only allows http/https, blocks private/internal addresses, enforces size limit.
	 */
	private function fetch_url_content(string $url, int $max_bytes = 524288): string {
		// Parse and validate the URL
		$parsed = parse_url($url);

		if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
			return '';
		}

		// Only allow http and https
		if (!in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
			return '';
		}

		// Resolve hostname and block private/internal addresses (SSRF protection)
		$ip = gethostbyname($parsed['host']);
		if ($ip === $parsed['host']) {
			// DNS resolution failed
			return '';
		}

		$packed = inet_pton($ip);
		if ($packed === false) {
			return '';
		}

		// IPv4 checks
		if (strlen($packed) === 4) {
			// 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 127.0.0.0/8, 169.254.0.0/16, 0.0.0.0/8
			$first_octet = unpack('C', $packed)[1];
			$second_octet = unpack('C', $packed, 1)[2];
			if ($first_octet === 10
				|| ($first_octet === 172 && $second_octet >= 16 && $second_octet <= 31)
				|| ($first_octet === 192 && $second_octet === 168)
				|| $first_octet === 127
				|| ($first_octet === 169 && $second_octet === 254)
				|| $first_octet === 0) {
				return '';
			}
		}

		// IPv6 checks: ::1, fe80::/10, fc00::/7
		if (strlen($packed) === 16) {
			$is_loopback = ($packed === inet_pton('::1'));
			$is_link_local = (unpack('n2', substr($packed, 0, 2))[1] === 0xfe80);
			$is_unique_local = (bindec(substr(dechex(unpack('n', $packed)[1]), 0, 1)) === 1
				&& (int)substr(dechex(unpack('n', $packed)[1]), 0, 1) >= 7);
			if ($is_loopback || $is_link_local || $is_unique_local) {
				return '';
			}
		}

		// Build the full URL with the resolved IP to prevent DNS-rebinding between check and fetch
		$replacement = $ip . ':' . ($parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80));
		$safe_url = str_replace($parsed['host'], $ip, $url);

		// If HTTPS, we need to set the Host header since the URL IP won't match the cert
		$headers = [];
		if (strtolower($parsed['scheme']) === 'https') {
			$host_header = $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
			$headers[] = "Host: $host_header";
			// Disable cert verification against IP (we validate the host via SNI below)
			// Alternatively, skip IP-substitution for HTTPS and rely on the SSRF check above.
			// For simplicity and safety, revert to original URL for HTTPS:
			$safe_url = $url;
		}

		// Fetch with curl
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL => $safe_url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,	   // No redirects (prevents bypass)
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => 15,
			CURLOPT_MAXFILESIZE => $max_bytes,	 // Hard cap on response size
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		]);

		$content = curl_exec($ch);
		if (curl_errno($ch)) {
			curl_close($ch);
			return '';
		}

		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($http_code < 200 || $http_code >= 300) {
			return '';
		}

		return (string) $content;
	}

	/**
	 * Extract all valid URLs found in the text.
	 * Supports http(s), ftp, and protocol-less domains.
	 *
	 * @return string[] Array of normalised URLs (https:// prepended when missing)
	 */
	function extract_urls(string $text): array {
		$pattern = '@
			(?:https?|ftp)://            # with protocol
			[a-z0-9.-]+                   # domain
			(?::\d+)?                     # optional port
			(?:/[^\s<>"\'|^`]*)?          # path + query + fragment
			|
			(?<![\w.-])                    # not preceded by word char, dot, or dash
			(?:www\.)?                    # optional www
			(?:                            # domain
			    [a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?
			    (?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*
			    \.[a-z]{2,63}
			)
			(?::\d+)?                     # optional port
			(?:/[^\s<>"\'|^`]*)?          # path + query + fragment
		@ix';

		if (!preg_match_all($pattern, $text, $m)) {
			return [];
		}

		$urls = [];
		foreach ($m[0] as $url) {
			if (!preg_match('/^[a-z]+:\/\//i', $url)) {
				$url = 'https://' . $url;
			}
			$urls[] = $url;
		}

		return array_values(array_unique($urls));
	}

	/**
	 * Extract the first valid URL found in the text.
	 *
	 * @return string|null URL (https:// prepended when missing) or null when none found
	 */
	public function extract_url(string $text): ?string {
		$urls = $this->extract_urls($text);
		return !empty($urls) ? $urls[0] : null;
	}

	/**
	 * Parse a prompt for a "read this URL" intent.
	 * The OpenAI engine treats any URL found in the prompt as read intent.
	 *
	 * @param string      $prompt   User prompt text
	 * @param string|null $language ISO 639-1 code, or null to auto-detect
	 *
	 * @return array intent, url, language and matched_keywords
	 */
	public function parse_read_prompt(string $prompt, ?string $language = null): array {
		$url = $this->extract_url($prompt);

		return [
			'intent'           => $url !== null,
			'url'              => $url,
			'language'         => $language ?? 'auto',
			'matched_keywords' => [],
		];
	}

	// No intent keywords for the OpenAI engine (all URLs are fetched)
	public function get_intent_keywords(string $language): array {
		return [];
	}

	// No intent keywords for the OpenAI engine (all URLs are fetched)
	public function find_intent_keywords(string $text, array $keywords): array {
		return [];
	}

	// The OpenAI engine does not auto-detect the language
	public function detect_language(string $text): string {
		return 'en';
	}

	public function get_models() : array {

		// Set the default endpoint
		//if (empty($endpoint)) {
		//	$endpoint = '/api/generate';
		//}

		// Set default empty string
		$response = '';

		// Set the url
		if (empty($this->api_url)) {
			$this->api_url = 'https://api.openai.com';
		}

		// Set the api url endpoint
		$api_url = $this->api_url . '/v1/models';

		// Initialize curl session
		$ch = curl_init();

		// Set curl options
		curl_setopt($ch, CURLOPT_URL, $api_url);
		if (!empty($json_data)) {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
		}

		// set the request headers
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Authorization: Bearer '.$this->api_key,
			'Content-Type: application/json'
		));

		// Set timeouts
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);

		// Enable verbose output for debugging
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		//$verbose_Log = fopen("curl_verbose_log.txt", "w");
		//curl_setopt($ch, CURLOPT_STDERR, $verbose_Log);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		// Execute the request Note: The response will be empty if stream is true
		$response = curl_exec($ch);

		// Check for errors
		if (curl_errno($ch)) {
			$response = [];
			$response['error']['error_message'] = curl_error($ch)."\n";
			$response['error']['error_code'] = curl_errno($ch)."\n";
			return $response;
		}

		// Output debugging info
		//echo "Debug Info:\n";
		//print_r($debug_info);

		// Output raw response
		//echo "\nRaw Response:\n" . $response . "\n";
		//exit;

		// Decode and display JSON response if valid
		if (json_last_error() === JSON_ERROR_NONE) {
			$decoded_response = json_decode($response, true);
			if (!empty($decoded_response['models'])) {
				$response = $decoded_response['models'];
			}
		}

		// Build the array of the list of models
		if (!empty($decoded_response['data'])) {
			$response = [];
			foreach($decoded_response['data'] as $row) {
				$response[]['model'] = $row['id'];
			}
		}

		// Check for JSON error
		if (json_last_error() !== JSON_ERROR_NONE) {
			$response['error'] = "JSON Decode Error: " . json_last_error_msg() . "\n";
		}

		// Close curl session
		unset($ch);

		// Close verbose log file
		//fclose($verbose_Log);

		return $response;
	}

	public function request(string $model, array $content) {

		// Define the global variables
		global $settings;

		// Return if content is empty
		if (empty($content)) {
			return '';
		}

		// Set the default model
		if (empty($model)) {
			$this->api_model = $settings->get('language_model', 'api_model', 'gpt-5.4-mini');
		}

		// Use the selected model
		if (!empty($model)) {
			$this->api_model = $model;
		}

		// Set default empty string
		$response = '';

		// Set to stream or not to stream
		$stream = $this->stream;

		// Turn off output buffering
		if ($stream) {
			while (ob_get_level() > 0) {
				ob_end_flush();
			}
			ob_implicit_flush(true);
		}

		// --- Debug: log the original prompt ---
		$this->debug_log("ORIGINAL PROMPT: " . ($content['prompt'] ?? '(empty)'));

		// URL reading is only permitted when the setting explicitly allows it
		$this->debug_log("URL READ PERMISSION: " . $this->permission_read_url);

		// Fetch URL content if one or more URLs are found in the AI prompt
		$urls = [];
		if ($this->permission_read_url === 'allow') {

			// Extract all URLs (if any) from the user's prompt text
			if (!empty($content['prompt']) && is_string($content['prompt'])) {
				$urls = $this->extract_urls($content['prompt']);
			}

			// Also honour a dedicated 'urls' key (array or string) for convenience
			if (!empty($content['urls'])) {
				if (is_array($content['urls'])) {
					$urls = array_merge($urls, $content['urls']);
				} elseif (is_string($content['urls'])) {
					$urls[] = $content['urls'];
				}
			}

			// De-duplicate while preserving order, drop blank entries
			$urls = array_values(array_unique(array_filter(
				$urls,
				static function ($u) { return is_string($u) && trim($u) !== ''; }
			)));
		}

		// --- Debug: log the detected URLs ---
		if (!empty($urls)) {
			$this->debug_log("DETECTED URLS (" . count($urls) . "): " . implode(', ', $urls));
		} else {
			$this->debug_log("DETECTED URLS: none found in prompt");
		}

		if (!empty($urls)) {
			$context_parts = [];

			foreach ($urls as $url) {
				$fetch_result = $this->fetch_url_content($url);
				if ($fetch_result !== '') {
					$this->debug_log("FETCH OK: {$url} (" . strlen($fetch_result) . " bytes)");
					$context_parts[] = "Content from {$url}:\n```\n" . $fetch_result . "\n```";
				} else {
					$this->debug_log("FETCH FAILED: {$url}");
				}
			}

			if (!empty($context_parts)) {
				if (empty($content['prompt'])) {
					$content['prompt'] = '';
				}
				$content['prompt'] .= "\n\n" . implode("\n\n", $context_parts);
			}
		}

		// --- Debug: log the final prompt (truncated to 2000 chars) ---
		$final_prompt = $content['prompt'] ?? '';
		$truncated = (strlen($final_prompt) > 2000)
			? substr($final_prompt, 0, 2000) . '... [TRUNCATED, total ' . strlen($final_prompt) . ' chars]'
			: $final_prompt;
		$this->debug_log("FINAL PROMPT (after URL injection): " . $truncated);

		// Prepare the request
		$data['model'] = $this->api_model;
		//$data['messages'][0]['role'] = 'developer';
		//$data['messages'][0]['content'] = '';
		$data['messages'][0]['role'] = 'user';
		$data['messages'][0]['content'] = $content['prompt'];
		$data['stream'] = $stream;

		/*
		curl "https://api.openai.com/v1/chat/completions" \
			-H "Content-Type: application/json" \
			-H "Authorization: Bearer $OPENAI_API_KEY" \
			-d '{
				"model": "gpt-4.1",
				"messages": [
					{
						"role": "developer",
						"content": "Talk like a pirate."
					},
					{
						"role": "user",
						"content": "Are semicolons optional in JavaScript?"
					}
				]
			}'
		*/

		// Convert data to JSON
		$json_data = json_encode($data);

		// Initialize curl session
		$ch = curl_init();

		// Set the url
		if (empty($this->api_url)) {
			$this->api_url = 'https://api.openai.com/v1/chat/completions';
		}

		// Set curl options
		curl_setopt($ch, CURLOPT_URL, $this->api_url);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);

		// Set the request headers
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Authorization: Bearer '.$this->api_key,
			'Content-Type: application/json'
		));

		// Set timeouts
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 300);

		// Enable verbose output for debugging
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		//$verbose_Log = fopen("curl_verbose_log.txt", "w");
		//curl_setopt($ch, CURLOPT_STDERR, $verbose_Log);

		// Stream the response
		if ($stream) {
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
			curl_setopt($ch, CURLOPT_WRITEFUNCTION, array($this, 'stream_callback'));
			//curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
			//	echo $data;
			//	flush();
			//	ob_flush();
			//});
		} else {
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		}

		// Execute the request Note: The response will be empty if stream is true
		$response = curl_exec($ch);

		// Debugging information
		$debug_info = array(
			"HTTP Code" => curl_getinfo($ch, CURLINFO_HTTP_CODE),
			"Total Time" => curl_getinfo($ch, CURLINFO_TOTAL_TIME),
			"Connect Time" => curl_getinfo($ch, CURLINFO_CONNECT_TIME),
			"Effective URL" => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
			"Content Type" => curl_getinfo($ch, CURLINFO_CONTENT_TYPE)
		);

		// Response Stream: false
		if (!$stream) {
			// Check for errors
			if (curl_errno($ch)) {
				echo "error ". curl_error($ch)."\n";
				echo "error code ".curl_errno($ch)."\n";
				return false;
			}

			// Output debugging info
			//echo "Debug Info:\n";
			//print_r($debug_info);

			// Output raw response
			//echo "\nRaw Response:\n" . $response . "\n";

			// Decode and display JSON response if valid
			if (json_last_error() === JSON_ERROR_NONE) {
				$decoded_response = json_decode($response, true);

				if (!empty($decoded_response['response'])) {
					$response = $decoded_response['response'];
				}
			}

			// Check for JSON error
			if (json_last_error() !== JSON_ERROR_NONE) {
				$response = "JSON Decode Error: " . json_last_error_msg() . "\n";
			}

			// Return response message content only
			if (!empty($decoded_response['choices'][0]['message']['content'])) {
				return $decoded_response['choices'][0]['message']['content'];
			}
		}

		// Close curl session
		unset($ch);

		//close verbose log file
		//fclose($verbose_Log);

		/*
		// show the result when there is an error
		if ($http_code == 200) {
			$response_array = json_decode($response, true);
			return urldecode($response_array['data']['translations'][0]['translatedText']);
		}
		else {
			echo "error ".$error."\n";
			echo "http_code ".$http_code."\n";
			if (strlen($response) < 500) {
				view_array(json_decode($response, true));
			}
			exit;
		}
		*/

		return $response;
	}

}
