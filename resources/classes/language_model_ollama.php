<?php

/**
 * language_model_ollama class
 *
 */
class language_model_ollama implements language_model_interface {

	public $api_model;
	public $stream;

	private $api_url;
	private $api_key;
	private $permission_read_url;

	public function __construct() {
		// Define the global variables
		global $settings, $config;

		//get api settings
		$this->api_key = $settings->get('language_model', 'api_key', '');
		$this->api_url = $settings->get('language_model', 'api_url', '');

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
		$port = $parsed['host'] . ':' . ($parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80));
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
			unset($ch);
			return '';
		}

		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		unset($ch);

		if ($http_code < 200 || $http_code >= 300) {
			return '';
		}

		return (string) $content;
	}

	public function get_models() : array {

		// Set the default endpoint
		//if (empty($endpoint)) {
		//	$endpoint = '/api/generate';
		//}

		// Set default empty string
		$response = '';

		// Set the URL
		if (empty($this->api_url)) {
			$this->api_url = 'http://127.0.0.1:11434';
		}

		// Set the api url endpoint
		$api_url = $this->api_url . '/api/tags';

		// Initialize curl session
		$ch = curl_init();

		// Set curl options
		curl_setopt($ch, CURLOPT_URL, $api_url);
		if (!empty($json_data)) {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			"Content-Type: application/json"
		));

		// Set timeouts
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);

		// Enable verbose output for debugging
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		//$verbose_Log = fopen("curl_verbose_log.txt", "w");
		//curl_setopt($ch, CURLOPT_STDERR, $verbose_Log);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		// Execute the request. Note: The response will be empty if stream is true
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
			$this->api_model = $settings->get('language_model', 'api_model', 'mistral-nemo:latest');
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

		// Set the URL
		if (empty($this->api_url)) {
			$this->api_url = 'http://127.0.0.1:11434';
		}

		// Add the endpoint to the URL
		$api_url = $this->api_url . '/api/generate';

		//if (empty($endpoint) or $endpoint == 'default') {
		//	$endpoint = 'default';
		//	$api_url = $this->api_url . '/api/generate';
		//}
		//if ($endpoint == 'local') {
		//	$api_url = $this->api_url . '/api/tags';
		//}
		//if ($endpoint == 'active' or $endpoint == 'running') {
		//	$api_url = $this->api_url . '/api/ps';
		//}

		// Fetch URL content if provided
		$urls = [];
		if ($this->permission_read_url === 'allow') {

			// Extract a URL (if any) from the user's prompt text
			if (!empty($content['prompt']) && is_string($content['prompt'])) {
				$parse_result = $this->parse_read_prompt($content['prompt']);
				if (!empty($parse_result['url'])) {
					$urls[] = $parse_result['url'];
				}
			}

			// Add content urls (array or string) if provided
			if (!empty($content['urls'])) {
				if (is_array($content['urls'])) {
					$urls = array_merge($urls, $content['urls']);
				} elseif (is_string($content['urls'])) {
					$urls[] = $content['urls'];
				}
			}

			// De-duplicate while preserving order, drop non-string / blank entries
			$urls = array_values(array_unique(array_filter(
				$urls,
				static function ($u) { return is_string($u) && trim($u) !== ''; }
			)));
		}

		if (!empty($urls)) {
			$context_parts = [];

			foreach ($urls as $url) {
				if (!is_string($url) || trim($url) === '') {
					continue; // skip non-string / blank entries
				}

				$fetch_result = $this->fetch_url_content($url);
				if ($fetch_result !== '') {
					$context_parts[] = "[Context from {$url}]:\n" . $fetch_result;
				}
				// fetch failed → skip silently (graceful degradation)
			}

			if (!empty($context_parts)) {
				if (empty($content['prompt'])) {
					$content['prompt'] = '';
				}
				$content['prompt'] .= "\n\n" . implode("\n\n", $context_parts);
			}
		}

		// Prepare the request
		if (!empty($content['images'])) {
			$data['model'] = $this->api_model;
			$data['prompt'] = $content['prompt'];
			$data['stream'] = $stream;
			$data['images'] = $content['images'];
		}
		else {
			$data['model'] = $this->api_model;
			$data['prompt'] = $content['prompt'];
			$data['stream'] = $stream;
		}

		// Debug info
		//file_put_contents('/tmp/request.log', implode(' ', $data));

		// Convert data to JSON
		if (!empty($data)) {
			$json_data = json_encode($data);
		}

		// Initialize curl session
		$ch = curl_init();

		// Set curl options
		curl_setopt($ch, CURLOPT_URL, $api_url);
		if (!empty($json_data)) {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			"Content-Type: application/json"
		));

		// Set timeouts
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
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

		// Execute the request. Note: The response will be empty if stream is true
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
			//exit;

			// Decode the JSON response envelope returned by /api/generate
			// The model response is returned as-is in the "response" key,
			// regardless of its content format (text, JSON or XML)
			$decoded_response = json_decode($response, true);
			if (json_last_error() === JSON_ERROR_NONE
				&& is_array($decoded_response)
				&& array_key_exists('response', $decoded_response)
				&& is_string($decoded_response['response'])) {
				$response = $decoded_response['response'];
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

	/**
	 * Validate a caller-supplied chat API URL.
	 * Returns null when the URL is usable, or an error message to report
	 * when it is not (must be a well-formed http/https URL with a host).
	 * Private hosts are intentionally allowed: a self-hosted Ollama server
	 * is normally reached over the local network, and the override value
	 * comes from server-side settings, not from end-user input.
	 */
	private function validate_chat_api_url(string $url): ?string {
		$parsed = parse_url($url);
		if (!$parsed || empty($parsed['host'])) {
			return 'invalid api_url';
		}
		if (!in_array(strtolower($parsed['scheme'] ?? ''), ['http', 'https'], true)) {
			return 'api_url must use http or https';
		}
		return null;
	}

	/**
	 * chat
	 * Multi-message chat against Ollama /api/chat with optional native tool
	 * calling, thinking (reasoning) capture, and streaming with throttled
	 * progress callbacks. Used by the agent_code service (the /api/generate
	 * endpoint in request() above cannot do tool calling).
	 *
	 * @param string $model    Ollama model name
	 * @param array  $messages Messages in Ollama /api/chat format:
	 *                         ['role' => ..., 'content' => ..., 'name'?, 'tool_calls'?, 'images'?]
	 * @param array|null $tools Ollama 'tools' array (function schemas) or null
	 * @param array  $opts      ['temperature' => float, 'timeout' => int, 'api_url' => string,
	 *                          'think' => bool,        ask thinking models to reason (Ollama 'think')
	 *                          'stream' => bool,       use the streaming endpoint (default: with on_progress)
	 *                          'on_progress' => callable] invoked (throttled ~1s) while a stream
	 *                          arrives, with ['thinking' => string, 'content' => string,
	 *                          'completion_tokens' => int, 'done' => bool]
	 * @return array ['ok', 'content', 'tool_calls', 'thinking', 'prompt_tokens',
	 *               'completion_tokens', 'eval_duration', 'total_duration', 'error']
	 */
	public function chat(string $model, array $messages, ?array $tools = null, array $opts = []): array {

		// Default result so every path returns the same shape
		$result = [
			'ok' => false,
			'content' => '',
			'tool_calls' => [],
			'thinking' => '',
			'prompt_tokens' => 0,
			'completion_tokens' => 0,
			'eval_duration' => 0,
			'total_duration' => 0,
			'error' => '',
		];

		if (empty($messages)) {
			$result['error'] = 'no messages provided';
			return $result;
		}

		// Use the selected model or the configured default
		if (!empty($model)) {
			$this->api_model = $model;
		}
		if (empty($this->api_model)) {
			global $settings;
			$this->api_model = $settings->get('language_model', 'api_model', '');
		}
		if (empty($this->api_model)) {
			$result['error'] = 'no model selected';
			return $result;
		}

		// Build the message payloads (only the keys each role actually uses)
		$payload_messages = [];
		foreach ($messages as $m) {
			$d = [
				'role' => $m['role'] ?? 'user',
				'content' => $m['content'] ?? '',
			];
			if (!empty($m['name'])) {
				$d['name'] = $m['name'];
			}
			if (!empty($m['tool_calls'])) {
				$d['tool_calls'] = $m['tool_calls'];
			}
			if (!empty($m['images'])) {
				$d['images'] = $m['images'];
			}
			$payload_messages[] = $d;
		}

		// Prepare the request (same shape as Ollama /api/chat).
		// Streaming is on by default (live thinking + token feedback); a
		// caller can force the non-streaming path with 'stream' => false.
		$use_stream = !isset($opts['stream']) || (bool)$opts['stream'];
		$data = [
			'model' => $this->api_model,
			'messages' => $payload_messages,
			'stream' => $use_stream,
			'options' => [
				'temperature' => $opts['temperature'] ?? 0.3,
			],
		];
		if (!empty($tools)) {
			$data['tools'] = $tools;
		}
		//ask thinking models to reason (ignored by models without thinking)
		if (!empty($opts['think'])) {
			$data['think'] = true;
		}
		$json_data = json_encode($data);
		if ($json_data === false) {
			$result['error'] = 'could not encode chat payload';
			return $result;
		}

		// Set the URL (allow an override; default is the configured Ollama host)
		$api_url = $opts['api_url'] ?? $this->api_url;
		if (empty($api_url)) {
			$api_url = 'http://127.0.0.1:11434';
		}
		// Reject malformed or non-http(s) overrides (e.g. file:// or gopher://)
		$url_error = $this->validate_chat_api_url($api_url);
		if ($url_error !== null) {
			$result['error'] = $url_error;
			return $result;
		}
		$api_url = rtrim($api_url, '/') . '/api/chat';

		if ($use_stream) {
			$result = $this->chat_stream($api_url, $json_data, $tools, $opts, $result);
			return $result;
		}

		// Initialize curl session
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL => $api_url,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $json_data,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 3,
			CURLOPT_TIMEOUT => $opts['timeout'] ?? 300,
			CURLOPT_HTTPHEADER => array(
				"Content-Type: application/json"
			)
		]);

		// Execute the request
		$response = curl_exec($ch);
		$curl_error = curl_errno($ch) ? curl_error($ch) : '';
		$http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

		// Close curl session
		unset($ch);

		// Check for transport errors
		if ($curl_error !== '') {
			$result['error'] = 'curl error: ' . $curl_error;
			return $result;
		}

		// Check the HTTP status
		if ($http_code < 200 || $http_code >= 300) {
			$result['error'] = 'HTTP ' . $http_code . ' ' . substr((string)$response, 0, 300);
			return $result;
		}

		// Decode the response envelope
		$decoded = json_decode((string)$response, true);
		if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || !isset($decoded['message'])) {
			$result['error'] = 'bad response body: ' . substr((string)$response, 0, 300);
			return $result;
		}

		// Extract the reply content
		$content = $decoded['message']['content'] ?? '';
		if (!is_string($content)) {
			$content = '';
		}

		// Extract native tool calls (arguments may arrive as an object or a JSON string)
		$tool_calls = [];
		if (!empty($decoded['message']['tool_calls']) && is_array($decoded['message']['tool_calls'])) {
			foreach ($decoded['message']['tool_calls'] as $tc) {
				if (!is_array($tc) || !isset($tc['function']['name'])) {
					continue;
				}
				$arguments = $tc['function']['arguments'] ?? [];
				if (is_string($arguments)) {
					$arguments = json_decode($arguments, true);
					if (!is_array($arguments)) {
						$arguments = [];
					}
				}
				if (!is_array($arguments)) {
					$arguments = [];
				}
				$tool_calls[] = [
					'name' => $tc['function']['name'],
					'arguments' => $arguments,
				];
			}
		}

		// An empty reply with no tool calls counts as a failure
		if (trim($content) === '' && empty($tool_calls)) {
			$result['error'] = 'model returned an empty reply';
			return $result;
		}

		$result['ok'] = true;
		$result['content'] = trim($content);
		$result['tool_calls'] = $tool_calls;
		//reasoning models (qwen3, deepseek-r1, …) may carry their thinking here
		$thinking = $decoded['message']['thinking'] ?? '';
		$result['thinking'] = is_string($thinking) ? $thinking : '';
		$result['prompt_tokens'] = (int)($decoded['prompt_eval_count'] ?? 0);
		$result['completion_tokens'] = (int)($decoded['eval_count'] ?? 0);
		$result['eval_duration'] = (int)($decoded['eval_duration'] ?? 0);
		$result['total_duration'] = (int)($decoded['total_duration'] ?? 0);
		return $result;
	}

	/**
	 * chat_stream
	 * Streaming variant of chat(): Ollama /api/chat with 'stream' => true is
	 * newline-delimited JSON (one object per token/chunk). This accumulates
	 * the message content, the reasoning (thinking) and the native tool
	 * calls (their name/arguments arrive as string fragments that are
	 * concatenated per call index), and reports throttled progress through
	 * $opts['on_progress'] while the model thinks and generates.
	 *
	 * @param string $api_url   full /api/chat URL
	 * @param string $json_data encoded chat payload ('stream' already true)
	 * @param array|null $tools tool schemas (Ollama format), used to recover a
	 *                          tool name that some models stream without
	 * @param array  $opts      ['timeout' => int, 'on_progress' => callable]
	 * @param array  $result    base result (kept so every path has the same shape)
	 * @return array same shape as chat()
	 */
	private function chat_stream(string $api_url, string $json_data, ?array $tools, array $opts, array $result): array {

		$on_progress = isset($opts['on_progress']) && is_callable($opts['on_progress']) ? $opts['on_progress'] : null;

		//accumulated state (the writer closure updates it as chunks arrive)
		$acc = [
			'line' => '',              //partial NDJSON line buffer
			'content' => '',
			'thinking' => '',
			'tool_calls' => [],        //[['name' => '', 'arguments' => 'fragment…']]
			'prompt_tokens' => 0,
			'completion_tokens' => 0,
			'eval_duration' => 0,
			'total_duration' => 0,
			'done' => false,
			'saw_chunk' => false,
			'fatal' => '',
			'last_notify' => 0.0,
		];

		//one complete NDJSON line → accumulator (by reference: shared state)
		$process_line = function (string $line) use (&$acc): void {
			$line = trim($line);
			if ($line === '') {
				return;
			}
			$obj = json_decode($line, true);
			if (!is_array($obj)) {
				$acc['fatal'] = 'bad stream line: ' . substr($line, 0, 200);
				return;
			}
			if (!empty($obj['error'])) {
				$acc['fatal'] = is_string($obj['error']) ? $obj['error'] : 'stream error';
				return;
			}
			//final chunk: carries the authoritative token counts + timings
			if (!empty($obj['done'])) {
				$acc['done'] = true;
				if (isset($obj['prompt_eval_count'])) {
					$acc['prompt_tokens'] = (int)$obj['prompt_eval_count'];
				}
				if (isset($obj['eval_count'])) {
					$acc['completion_tokens'] = (int)$obj['eval_count'];
				}
				$acc['eval_duration'] = (int)($obj['eval_duration'] ?? $acc['eval_duration']);
				$acc['total_duration'] = (int)($obj['total_duration'] ?? $acc['total_duration']);
				return;
			}
			if (isset($obj['eval_count'])) {
				//every data chunk reports the running completion count
				$acc['completion_tokens'] = (int)$obj['eval_count'];
			}
			$msg = $obj['message'] ?? null;
			if (!is_array($msg)) {
				return; //keep-alive ping while the model is loading
			}
			$acc['saw_chunk'] = true;
			if (isset($msg['content']) && is_string($msg['content'])) {
				$acc['content'] .= $msg['content'];
			}
			if (isset($msg['thinking']) && is_string($msg['thinking'])) {
				$acc['thinking'] .= $msg['thinking'];
			}
			//tool calls stream as fragments: append name/arguments per index
			if (!empty($msg['tool_calls']) && is_array($msg['tool_calls'])) {
				foreach ($msg['tool_calls'] as $i => $tc) {
					if (!is_array($tc)) {
						continue;
					}
					if (!isset($acc['tool_calls'][$i])) {
						$acc['tool_calls'][$i] = ['name' => '', 'arguments' => ''];
					}
					$fn = $tc['function'] ?? [];
					if (isset($fn['name']) && is_string($fn['name'])) {
						$acc['tool_calls'][$i]['name'] .= $fn['name'];
					}
					if (isset($fn['arguments'])) {
						if (is_string($fn['arguments'])) {
							$acc['tool_calls'][$i]['arguments'] .= $fn['arguments'];
						} elseif (is_array($fn['arguments'])) {
							$j = json_encode($fn['arguments']);
							if ($j !== false) {
								$acc['tool_calls'][$i]['arguments'] .= $j;
							}
						}
					}
				}
			}
		};

		//throttled progress notification (at most once per second, always on done)
		$notify = function () use (&$acc, $on_progress): void {
			if ($on_progress === null) {
				return;
			}
			$now = microtime(true);
			if (!$acc['done'] && ($now - $acc['last_notify']) < 1.0) {
				return;
			}
			$acc['last_notify'] = $now;
			try {
				$on_progress([
					'thinking' => $acc['thinking'],
					'content' => $acc['content'],
					'completion_tokens' => (int)$acc['completion_tokens'],
					'done' => (bool)$acc['done'],
				]);
			} catch (Throwable $ex) {
				//progress bookkeeping must never break the transfer
			}
		};

		$writer = function ($ch, $data) use ($process_line, $notify, &$acc) {
			$acc['line'] .= (string)$data;
			$pos = 0;
			while (($nl = strpos($acc['line'], "\n", $pos)) !== false) {
				$process_line(substr($acc['line'], $pos, $nl - $pos));
				if ($acc['fatal'] !== '') {
					return 0; //abort the transfer
				}
				$pos = $nl + 1;
			}
			if ($pos > 0) {
				$acc['line'] = substr($acc['line'], $pos);
			}
			$notify();
			return strlen((string)$data);
		};

		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL => $api_url,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $json_data,
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_WRITEFUNCTION => $writer,
			CURLOPT_CONNECTTIMEOUT => 3,
			CURLOPT_TIMEOUT => $opts['timeout'] ?? 300,
			CURLOPT_HTTPHEADER => array(
				"Content-Type: application/json"
			)
		]);

		$response = curl_exec($ch);
		$curl_error = curl_errno($ch) ? curl_error($ch) : '';
		$http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		unset($ch);

		//a final line without a trailing newline still has to be processed
		if ($acc['fatal'] === '' && $acc['line'] !== '') {
			$process_line($acc['line']);
			$acc['line'] = '';
			$notify();
		}
		if ($acc['fatal'] !== '') {
			$result['error'] = $acc['fatal'];
			return $result;
		}
		if ($http_code < 200 || $http_code >= 300) {
			$result['error'] = 'HTTP ' . $http_code;
			return $result;
		}
		if ($curl_error !== '') {
			$result['error'] = 'curl error: ' . $curl_error;
			return $result;
		}
		if (!$acc['done']) {
			$result['error'] = $acc['saw_chunk'] ? 'stream ended before the model finished' : 'stream produced no data (timeout?)';
			return $result;
		}

		//assemble the reply: shape tool calls the same way chat() does
		$content = trim($acc['content']);
		$tool_calls = [];
		ksort($acc['tool_calls']);
		foreach ($acc['tool_calls'] as $tc) {
			$args = [];
			if ($tc['arguments'] !== '') {
				$decoded_args = json_decode($tc['arguments'], true);
				if (is_array($decoded_args)) {
					$args = $decoded_args;
				}
			}
			//some models stream the call without a name: recover it from the
			//argument keys against the provided tool schemas
			$name = $tc['name'] !== '' ? $tc['name'] : $this->match_tool_name($args, $tools);
			if ($name === '') {
				continue; //no name and no way to find one: not a usable call
			}
			$tool_calls[] = [
				'name' => $name,
				'arguments' => $args,
			];
		}
		if ($content === '' && empty($tool_calls)) {
			$result['error'] = 'model returned an empty reply';
			return $result;
		}

		$result['ok'] = true;
		$result['content'] = $content;
		$result['tool_calls'] = $tool_calls;
		$result['thinking'] = $acc['thinking'];
		$result['prompt_tokens'] = $acc['prompt_tokens'];
		$result['completion_tokens'] = $acc['completion_tokens'];
		$result['eval_duration'] = $acc['eval_duration'];
		$result['total_duration'] = $acc['total_duration'];
		return $result;
	}

	/**
	 * match_tool_name
	 * Some models stream a tool call with an empty name (Ollama 0.32.x).
	 * When that happens, pick the provided tool whose parameter names best
	 * match the call's argument keys; with a single tool defined, that tool
	 * wins even for an empty argument list.
	 *
	 * @param array      $args  decoded tool call arguments
	 * @param array|null $tools Ollama-format tool schemas
	 * @return string tool name, or '' when nothing matches
	 */
	private function match_tool_name(array $args, ?array $tools): string {
		if (empty($tools) || !is_array($tools)) {
			return '';
		}
		$keys = array_keys($args);
		if ($keys === []) {
			if (count($tools) === 1) {
				return (string)($tools[0]['name'] ?? '');
			}
			return '';
		}
		$best = '';
		$best_score = 0;
		foreach ($tools as $t) {
			if (!is_array($t)) {
				continue;
			}
			$props = array_keys((array)($t['parameters']['properties'] ?? []));
			$score = 0;
			foreach ($keys as $k) {
				if (in_array($k, $props, true)) {
					$score++;
				}
			}
			if ($score > $best_score) {
				$best_score = $score;
				$best = (string)($t['name'] ?? '');
			}
		}
		return $best;
	}

	/**
	 * Parses a user prompt to detect a "read/open" intent and extract an optional URL.
	 *
	 * @param string $prompt       The raw user input text.
	 * @param string|null $language ISO 639-1 code, or null to auto-detect.
	 * @return array{
	 *     intent: bool,
	 *     url: string|null,
	 *     language: string,
	 *     matched_keywords: string[]
	 * }
	 */
	public function parse_read_prompt(string $prompt, ?string $language = null): array {
		$result = [
			'intent'           => false,
			'url'              => null,
			'language'         => $language ?? 'auto',
			'matched_keywords' => [],
		];

		if (trim($prompt) === '') {
			return $result;
		}

		// Determine the language to use
		$language = $language ?? $this->detect_language($prompt);
		$result['language'] = $language;

		// Collect keywords for that language (with fallbacks)
		$keywords = $this->get_intent_keywords($language);

		// Detect read, open, view intent words
		$result['matched_keywords'] = $this->find_intent_keywords($prompt, $keywords);

		// Language heuristics are imperfect, so if the detected language
		// produced no match, fall back to the other known languages
		if (empty($result['matched_keywords'])) {
			foreach (['en', 'es', 'fr', 'de', 'it', 'pt', 'zh', 'ja', 'ko', 'nl'] as $fallback) {
				if ($fallback === $language) {
					continue;
				}
				$matches = $this->find_intent_keywords($prompt, $this->get_intent_keywords($fallback));
				if (!empty($matches)) {
					$result['matched_keywords'] = $matches;
					$result['language'] = $fallback;
					break;
				}
			}
		}

		if (empty($result['matched_keywords'])) {
			return $result; // No intent detected
		}

		$result['intent'] = true;

		// Extract an optional URL
		$result['url'] = $this->extract_url($prompt);

		return $result;
	}

	/**
	 * Language-specific keyword sets (lowercase for matching)
	 *
	 * @return string[]  Regex-safe keyword patterns for the given language.
	 */
	public function get_intent_keywords(string $language): array {
		$sets = [
			'en' => [
				'\bread\b', '\bopen\b', '\bview\b', '\blook at\b',
				'\bcheck out\b', '\bbrowse\b', '\bshow me\b', '\bfetch\b',
				'\bload\b', '\bdisplay\b', '\bget\b', '\bvisit\b',
				'\bsee\b', '\bshow\b', '\bpull\b', '\bfetch me\b',
			],
			'es' => [
				'\blee\b', '\bleer\b', '\babre\b', '\babrir\b',
				'\bmira\b', '\bver\b', '\bvisita\b', '\bcarga\b',
				'\bmostrame\b', '\bmuéstrame\b', '\bobtén\b', '\bobtener\b',
				'\brecupera\b', '\brecuperar\b', '\bdescarga\b', '\btrae\b',
			],
			'fr' => [
				'\blis\b', '\blire\b', '\bouvre\b', '\bouvrir\b',
				'\bregarde\b', '\bvoir\b', '\bvisite\b', '\bcharge\b',
				'\bmontre\b', '\bmoi\b', '\brécupère\b', '\brobtenir\b',
				'\bdescends\b', '\bva voir\b', '\bregarde ce\b',
			],
			'de' => [
				'\blies\b', '\blesen\b', '\böffne\b', '\böffnen\b',
				'\bschau\b', '\banschau\b', '\bbesuche\b', '\blade\b',
				'\bzeige\b', '\bmir\b', '\bhole\b', '\bholen\b',
				'\bhole dir\b', '\bholt\b', '\brufe auf\b',
			],
			'it' => [
				'\bleggi\b', '\bleggere\b', '\bapri\b', '\baprire\b',
				'\bguarda\b', '\bvedi\b', '\bvisita\b', '\bcarica\b',
				'\bmostrami\b', '\bottenere\b', '\bobtieni\b', '\brecupera\b',
			],
			'pt' => [
				'\bleia\b', '\bler\b', '\babra\b', '\babrir\b',
				'\bveja\b', '\bver\b', '\bvisite\b', '\bcarregue\b',
				'\bmostra\b', '\bobtenha\b', '\bobter\b', '\brecupere\b',
				'\bbusca\b',
			],
			'zh' => [
				'读取', '打开', '查看', '看看', '浏览',
				'获取', '加载', '显示', '访问', '拉取',
			],
			'ja' => [
				'読み取る', '開く', '見る', '閲覧する',
				'取得する', '表示する', '訪れる', '見せて',
			],
			'ko' => [
				'읽어', '열어', '보기', '열람', '조회',
				'가져오', '표시', '방문',
			],
			'nl' => [
				'\bles\b', '\blesen\b', '\bopen\b', '\bopener\b',
				'\bek\bbekijk\b', '\bekken\b', '\bzoek\b', '\bhaal\b',
				'\btoon\b', '\btonen\b', '\bbezoek\b', '\blaad\b',
			],
		];

		// Fallback: English keywords if language not in our list
		return $sets[$language] ?? $sets['en'];
	}

	/**
	 * Find which intent keywords appear in the prompt (case-insensitive).
	 *
	 * @param string[] $keywords  Array of regex fragments.
	 * @return string[]           Human-readable matched terms.
	 */
	public function find_intent_keywords(string $text, array $keywords): array {
		$matches = [];

		foreach ($keywords as $pattern) {
			// Wrap in a non-capturing group and anchor for word-boundary CJK
			$regex = '/(?<=^|[\s.,!?;])' . $pattern . '(?=[\s.,!?;:)]|$)/iu';

			if (preg_match($regex, $text, $m)) {
				$matches[] = $m[0];
			}
		}

		return $matches;
	}

	/**
	 * Extract the first valid URL found in the text.
	 * Supports http(s), ftp, and protocol-less domains.
	 *
	 * Normalizes to https:// if no scheme is present.
	 * @param string $text  Input text to search for a URL.
	 * @return string|null  The first matched URL, or null if none found.
	 */
	public function extract_url(string $text): ?string	{
		$pattern = '/
			(?:
				(?:https?|ftp):\/\/
				(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}
				(?:\/[^\s<>"\'|^`]*)?
			)
			|
			(?:
				(?<![\w.-])
				(?:www\.)?
				(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}
				(?:\/[^\s<>"\'|^`]*)?
			)
		/ix';

		if (preg_match($pattern, $text, $m)) {
			$url = $m[0];

			// Normalise: if no scheme, prepend https://
			if (!preg_match('/^[a-z]+:\/\//i', $url)) {
				$url = 'https://' . $url;
			}

			return $url;
		}

		return null;
	}

	/**
	 * Extract all valid URLs found in the text.
	 * The Ollama engine uses the first URL only.
	 *
	 * @return string[] Array of normalised URLs (https:// prepended when missing)
	 */
	public function extract_urls(string $text): array {
		$url = $this->extract_url($text);
		return $url !== null ? [$url] : [];
	}

	// ---------------------------------------------------------------------------
	// Simple language auto-detection (character-set heuristics)
	// ---------------------------------------------------------------------------
	public function detect_language(string $text): string {
		// Strip URLs / domain-like tokens first, since TLDs (e.g. .com, .de, .pt)
		// otherwise cause false positives in the word-based heuristics
		$text = preg_replace('/[a-z0-9-]+(?:\\.[a-z0-9-]+)*\\.[a-z]{2,}(?:\\/[^\\s]*)?/i', ' ', $text);

		$checks = [
			'zh' => '/[\x{4e00}-\x{9fff}]/u',
			'ja' => '/[\x{3040}-\x{309f}\x{30a0}-\x{30ff}]/u',
			'ko' => '/[\x{ac00}-\x{d7af}]/u',
			'de' => '/(ä|ö|ü|ß)/iu',
			'fr' => '/(\bje\b|\bvous\b|\bles\b|\bune\b|\bêtre\b|é|à|ç)/iu',
			'es' => '/(\blos\b|\bunas\b|\bcon\b|\bpor\b|\bqué\b|ñ)/iu',
			'pt' => '/(\bos\b|\bumas\b|\bcom\b|\bpelo\b|\bé\b|\bvocê\b)/iu',
			'it' => '/(\bel\b|\bli\b|\bcon\b|\bdella\b|\bessere\b)/iu',
			'nl' => '/(\bhet\b|\bmet\b|\bvoor\b|\bde\b|\ben\b)/iu',
		];

		foreach ($checks as $lang => $regex) {
			if (preg_match($regex, $text)) {
				return $lang;
			}
		}

		return 'en'; // default fallback
	}

}
