<?php

/**
 * language_model_inworld class
 *
 * InWorld AI LLM gateway (https://api.inworld.ai).
 *
 * The API is OpenAI chat-completions compatible:
 *   - List models: GET  https://api.inworld.ai/llm/v1alpha/models
 *   - Chat:        POST https://api.inworld.ai/v1/chat/completions
 *
 * The language_model.api_key setting is a base64 encoded "username:password"
 * pair and is passed as-is in an HTTP Basic Authorization header for both
 * endpoints.
 *
 * A model name from the models list (e.g. "gemini-2.5-flash-lite") can be
 * sent directly; "auto" lets InWorld pick the best model (smart routing).
 *
 */
class language_model_inworld implements language_model_interface {

	private $api_key;
	public $api_url;
	public $api_model;
	public $stream;
	public $stream_logged = false;

	private $permission_read_url;

	public function __construct() {
		global $settings, $config;

		// Get the API settings
		$this->api_key = $settings->get('language_model', 'api_key', '');
		$this->api_url = $settings->get('language_model', 'api_url', '');
		$this->api_model = $settings->get('language_model', 'api_model', 'auto');

		//get the config object if not already defined
		if (empty($config)) {
			$config = new config;
		}

		// URL reading is only permitted when the setting explicitly allows it
		$this->permission_read_url = $config->get('language_model.permissions.read.url', 'deny');
	}

	// Callback function to handle streaming data
	private function stream_callback($ch, $data) {
		// Log the first chunk received so API errors are visible in the debug log
		if (empty($this->stream_logged)) {
			$this->stream_logged = true;
			$this->debug_log('STREAM FIRST CHUNK ('.strlen($data).') bytes: '.str_replace("\n", '', substr($data, 0, 300)));
		}
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
		static $can_log = null;
		$log_file = '/var/log/fusionpbx/language_model_debug.log';
		if ($can_log === null) {
			$can_log = is_file($log_file) ? is_writable($log_file) : is_writable(dirname($log_file));
		}
		if (!$can_log) {
			return;
		}
		$timestamp = date('Y-m-d H:i:s');
		$line = "[{$timestamp}] [inworld] {$message}" . PHP_EOL;

		// Ensure the log directory exists (not world-writable)
		$dir = dirname($log_file);
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		@chmod($dir, 0755);

		// Prompts may contain personal data: keep the log owner/group readable only
		@file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
		@chmod($log_file, 0640);
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

		// IPv6 checks: ::1, fe80::/10, fc00::/7 and IPv4-mapped forms
		if (strlen($packed) === 16) {
			$first_byte = unpack('C', $packed)[1];
			$second_byte = unpack('C', $packed, 1)[2];
			$is_loopback = ($packed === inet_pton('::1'));
			$is_link_local = ($first_byte === 0xfe && ($second_byte & 0xc0) === 0x80);
			$is_unique_local = (($first_byte & 0xfe) === 0xfc);
			if ($is_loopback || $is_link_local || $is_unique_local) {
				return '';
			}
			// Unmap IPv4-mapped IPv6 (::ffff:a.b.c.d) so the IPv4 checks below apply
			if (substr($packed, 0, 10) === str_repeat("\x00", 10) && substr($packed, 10, 2) === "\xff\xff") {
				$packed = substr($packed, 12);
			}
		}

		// IPv4 checks (also reached for unmapped IPv4-mapped IPv6 addresses)
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
			CURLOPT_FOLLOWLOCATION => false,	// No redirects (prevents bypass)
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => 15,
			CURLOPT_MAXFILESIZE => $max_bytes,	// Hard cap on response size
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
	 * The InWorld engine treats any URL found in the prompt as read intent.
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

	/**
	 * chat
	 * This engine does not support tool-calling chat; the agent_code service
	 * requires the ollama engine.
	 */
	public function chat(string $model, array $messages, ?array $tools = null, array $opts = []): array {
		return [
			'ok' => false,
			'content' => '',
			'tool_calls' => [],
			'thinking' => '',
			'prompt_tokens' => 0,
			'completion_tokens' => 0,
			'eval_duration' => 0,
			'total_duration' => 0,
			'error' => 'the ' . __CLASS__ . ' engine does not support chat with tool calling; use the ollama engine',
		];
	}

	// No intent keywords for the InWorld engine (all URLs are fetched)
	public function get_intent_keywords(string $language): array {
		return [];
	}

	// No intent keywords for the InWorld engine (all URLs are fetched)
	public function find_intent_keywords(string $text, array $keywords): array {
		return [];
	}

	// The InWorld engine does not auto-detect the language
	public function detect_language(string $text): string {
		return 'en';
	}

	public function get_models() : array {

		// Set the default empty array
		$response = [];

		// Return a readable error when no API key is configured
		if (empty($this->api_key)) {
			$response['error'] = "no InWorld API key configured\n";
			return $response;
		}

		// Set the url
		if (empty($this->api_url)) {
			$this->api_url = 'https://api.inworld.ai';
		}

		// Set the api url endpoint
		$api_url = $this->api_url . '/llm/v1alpha/models';

		// Initialize curl session
		$ch = curl_init();

		// Set curl options
		curl_setopt($ch, CURLOPT_URL, $api_url);

		// set the request headers (the API key is a base64 user:pass pair)
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Authorization: Basic '.$this->api_key,
			'Content-Type: application/json'
		));

		// Set timeouts
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		// Execute the request Note: The response will be empty if stream is true
		$response_body = curl_exec($ch);

		// Check for errors
		if (curl_errno($ch)) {
			$response['error']['error_message'] = curl_error($ch)."\n";
			$response['error']['error_code'] = curl_errno($ch)."\n";
			unset($ch);
			return $response;
		}

		// Decode the JSON response
		$decoded_response = json_decode($response_body, true);

		// Check for JSON error
		if (json_last_error() !== JSON_ERROR_NONE) {
			$response['error'] = "JSON Decode Error: " . json_last_error_msg() . "\n";
		}
		elseif (is_array($decoded_response)) {

			// "auto" lets InWorld pick the best model (smart routing)
			$response[] = [
				'model' => 'auto',
				'provider' => 'inworld',
			];

			// Build the array of the list of models
			if (!empty($decoded_response['models']) && is_array($decoded_response['models'])) {
				$seen = [];
				foreach($decoded_response['models'] as $row) {
					if (!is_array($row) || empty($row['model'])) {
						continue;
					}
					// Skip models the account does not support
					if (isset($row['isSupported']) && $row['isSupported'] !== true) {
						continue;
					}
					// The same model can be offered by multiple providers, only list it once
					if (isset($seen[$row['model']])) {
						continue;
					}
					$seen[$row['model']] = true;
					$response[] = [
						'model' => $row['model'],
						'provider' => $row['provider'] ?? '',
					];
				}
			}
			elseif (isset($decoded_response['error']) && !empty($decoded_response['error']['message'])) {
				// The API returned an error (e.g. invalid API key)
				$response['error'] = $decoded_response['error']['message'] . "\n";
			}
		}

		// Close curl session
		unset($ch);

		return $response;
	}

	public function request(string $model, array $content) {

		// Define the global variables
		global $settings;

		// Return if content is empty
		if (empty($content)) {
			return '';
		}

		// Return a readable error when no API key is configured
		if (empty($this->api_key)) {
			return "InWorld API key is not configured\n";
		}

		// Set the default model
		if (empty($model)) {
			$this->api_model = $settings->get('language_model', 'api_model', 'auto');
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

		// Debug: log the original prompt
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

		// Debug: log the detected URLs
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

		// Debug: log the final prompt (truncated to 2000 chars)
		$final_prompt = $content['prompt'] ?? '';
		$truncated = (strlen($final_prompt) > 2000)
			? substr($final_prompt, 0, 2000) . '... [TRUNCATED, total ' . strlen($final_prompt) . ' chars]'
			: $final_prompt;
		$this->debug_log("FINAL PROMPT (after URL injection): " . $truncated);

		// Prepare the request (OpenAI chat completions format)
		$data['model'] = $this->api_model;
		$data['messages'][0]['role'] = 'user';
		$data['messages'][0]['content'] = $content['prompt'];
		$data['stream'] = $stream;

		/*
		curl --request POST \
		  --url https://api.inworld.ai/v1/chat/completions \
		  --header 'Authorization: <api_key>' \
		  --header 'Content-Type: application/json' \
		  --data '{
		  "model": "auto",
		  "messages": [
		    {
		      "role": "user",
		      "content": "Hello!"
		    }
		  ],
		  "extra_body": {
		    "models": [
		      "openai/gpt-4o-mini",
		      "google-ai-studio/gemini-2.5-flash-lite"
		    ],
		    "sort": [
		      "price"
		    ]
		  }
		}'
		*/

		// Constrain the InWorld smart router when a caller supplies an
		// "extra_body" (e.g. "models" to restrict the candidates, "sort" to rank them)
		if (!empty($content['extra_body']) && is_array($content['extra_body'])) {
			$data['extra_body'] = $content['extra_body'];
		}

		// Convert data to JSON
		$json_data = json_encode($data);

		// Initialize curl session
		$ch = curl_init();

		// Set the url
		if (empty($this->api_url)) {
			$this->api_url = 'https://api.inworld.ai';
		}
		$api_url = $this->api_url . '/v1/chat/completions';

		// Set curl options
		curl_setopt($ch, CURLOPT_URL, $api_url);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);

		// Set the request headers (the API key is a base64 user:pass pair)
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Authorization: Basic '.$this->api_key,
			'Content-Type: application/json'
		));

		// Set timeouts
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 300);

		// Stream the response
		if ($stream) {
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
			curl_setopt($ch, CURLOPT_WRITEFUNCTION, array($this, 'stream_callback'));
		} else {
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		}

		// Execute the request Note: The response will be empty if stream is true
		$response = curl_exec($ch);

		// Debug: capture the transport result so silent API failures are diagnosable
		$http_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$curl_error = curl_errno($ch) ? curl_error($ch) : 'none';
		$this->debug_log("REQUEST SENT: model={$this->api_model} stream=".($stream ? 'true' : 'false')
			." http_code={$http_code} curl_error={$curl_error} key_len=" . strlen((string)$this->api_key));

		// Stream mode: when the API rejects the request (bad key, unknown model, ...)
		// the error body is not framed with "data:" so the web UI would silently
		// show nothing. Append an SSE error event the UI can display.
		if ($stream && $http_code >= 400) {
			$err = 'InWorld API error (HTTP ' . $http_code . ')';
			$this->debug_log("STREAM ERROR emitted to client: {$err}");
			echo 'data: ' . json_encode(['error' => $err]) . "\n\n";
			echo 'data: [DONE]' . "\n\n";
			flush();
			ob_flush();
		}

		// Stream mode: curl-level failure (DNS, proxy, TLS, timeout) sends no data at all
		if ($stream && curl_errno($ch) && empty($this->stream_logged)) {
			$err = 'InWorld request failed: ' . curl_error($ch);
			$this->debug_log("STREAM CURL FAILURE emitted to client: {$err}");
			echo 'data: ' . json_encode(['error' => $err]) . "\n\n";
			echo 'data: [DONE]' . "\n\n";
			flush();
			ob_flush();
		}

		// Response Stream: false
		if (!$stream) {
			// Check for errors
			if (curl_errno($ch)) {
				echo "error ". curl_error($ch)."\n";
				echo "error code ".curl_errno($ch)."\n";
				return false;
			}

			// Decode and display JSON response if valid
			$decoded_response = json_decode($response, true);

			// Check for JSON error
			if (json_last_error() !== JSON_ERROR_NONE) {
				$response = "JSON Decode Error: " . json_last_error_msg() . "\n";
			}

			// Report API errors (invalid key, unknown model, ...) in a readable form
			if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_response) && !empty($decoded_response['error'])) {
				if (is_string($decoded_response['error'])) {
					return $decoded_response['error'] . "\n";
				}
				if (is_array($decoded_response['error']) && !empty($decoded_response['error']['message'])) {
					return $decoded_response['error']['message'] . "\n";
				}
			}

			// Return response message content only
			if (!empty($decoded_response['choices'][0]['message']['content'])) {
				return $decoded_response['choices'][0]['message']['content'];
			}
		}

		// Close curl session
		unset($ch);

		return $response;
	}
}
