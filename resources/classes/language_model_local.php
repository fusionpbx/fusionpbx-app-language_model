<?php

/**
 * language_model_local class
 *
 */
class language_model_local implements language_model_interface {

	private $api_key;
	public $api_url;
	public $api_model;
	public $stream;

	public function __construct() {
		// Define the global variables
		global $settings;

		//get api settings
		$this->api_key = $settings->get('language_model', 'api_key', '');
		$this->api_url = $settings->get('language_model', 'api_url', '');
	}

	// Callback function to handle streaming data
	private function stream_callback($ch, $data) {
		// Process the data here (e.g., echo or write to a file)
		echo $data; // Example: output the data as it is received
		flush(); // Make sure the output is sent immediately
		ob_flush();
		return strlen($data); // Return the length of the data processed
	}

	public function get_models() : array {

		// Set the default endpoint
		//if (empty($endpoint)) {
		//	$endpoint = '/api/generate';
		//}

		// Set default empty string
		$response = [];

		// Set the url
		if (empty($this->api_url)) {
			$this->api_url = 'http://127.0.0.1:8787';
		}

		// Set the api url endpoint
		$api_url = $this->api_url . '/models';

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
		$http_response = curl_exec($ch);

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
		// echo "\nRaw Response:\n" . $response . "\n";
		// exit;

		// Decode and display JSON response if valid
		if (json_last_error() === JSON_ERROR_NONE) {
				$decoded_response = json_decode($http_response, true);

				// Build the language model array
				foreach($decoded_response['data'] as $row) {
					$response[]['model'] = $row['id'];
				}

				// if (!empty($decoded_response['data'])) {
				//	//$response = $decoded_response['data'];
				// }
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
$stream = false;
		// Turn off output buffering
		if ($stream) {
			while (ob_get_level() > 0) {
				ob_end_flush();
			}
			ob_implicit_flush(true);
		}

		// Set the url
		if (empty($this->api_url)) {
			$this->api_url = 'http://127.0.0.1:8787';
		}

		// Add the endpoint to the url
		$api_url = $this->api_url . '/completion';

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
				if ($endpoint = 'default') {
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

	// ---------------------------------------------------------------------------
	// URL reading support (required by language_model_interface)
	// The local engine does not fetch URLs, so these always report
	// no intent and no URLs
	// ---------------------------------------------------------------------------

	/**
	 * Parse a prompt for a "read this URL" intent.
	 *
	 * @param string      $prompt   User prompt text
	 * @param string|null $language ISO 639-1 code, or null to auto-detect
	 *
	 * @return array intent, url, language and matched_keywords (none detected)
	 */
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

	public function parse_read_prompt(string $prompt, ?string $language = null): array {
		return [
			'intent'           => false,
			'url'              => null,
			'language'         => $language ?? 'auto',
			'matched_keywords' => [],
		];
	}

	// No intent keywords for the local engine
	public function get_intent_keywords(string $language): array {
		return [];
	}

	// No intent keywords for the local engine
	public function find_intent_keywords(string $text, array $keywords): array {
		return [];
	}

	// The local engine does not extract URLs
	public function extract_url(string $text): ?string {
		return null;
	}

	// The local engine does not auto-detect the language
	public function detect_language(string $text): string {
		return 'en';
	}

	// The local engine does not extract URLs
	public function extract_urls(string $text): array {
		return [];
	}

}
