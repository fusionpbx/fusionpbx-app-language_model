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
		if (!empty($config)) {
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

		// Set the url
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

		// Set the url
		if (empty($this->api_url)) {
			$this->api_url = 'http://127.0.0.1:11434';
		}

		// Add the endpoint to the url
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
			//exit;

			// Decode and display JSON response if valid
			if (json_last_error() === JSON_ERROR_NONE) {
				if ($endpoint == 'default') {
					$decoded_response = json_decode($response, true);
					if (!empty($decoded_response['response'])) {
						$response = $decoded_response['response'];
						}
					}
			}

			// Check for JSON error
			if (json_last_error() !== JSON_ERROR_NONE) {
				$response = "JSON Decode Error: " . json_last_error_msg() . "\n";
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

		// --- Step 1: Determine the language to use ---
		$language = $language ?? $this->detect_language($prompt);
		$result['language'] = $language;

		// --- Step 2: Collect keywords for that language (with fallbacks) ---
		$keywords = $this->get_intent_keywords($language);

		// --- Step 3: Detect "read / open / view" intent ---
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

		// --- Step 4: Extract an optional URL ---
		$result['url'] = $this->extract_url($prompt);

		return $result;
	}

	// ---------------------------------------------------------------------------
	// Language-specific keyword sets (lowercase for matching)
	// ---------------------------------------------------------------------------

	/**
	 * @return string[]  Regex-safe keyword patterns for the given language.
	 */
	function get_intent_keywords(string $language): array {
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

	// ---------------------------------------------------------------------------
	// Matching helpers
	// ---------------------------------------------------------------------------

	/**
	 * Find which intent keywords appear in the prompt (case-insensitive).
	 *
	 * @param string[] $keywords  Array of regex fragments.
	 * @return string[]           Human-readable matched terms.
	 */
	function find_intent_keywords(string $text, array $keywords): array {
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
	 */
	function extract_url(string $text): ?string	{
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
	function detect_language(string $text): string {
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
