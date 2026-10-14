<?php

/**
 * language_model class
 *
 * @method null download
 */
class language_model {

	/**
	 * declare private variables
	 */
	private $api_key;

	/** @var string $engine */
	private $engine;

	/** @var boolean $stream */
	private $stream;

	/**
	 * called when the object is created
	 *
	 * @param bool   $stream use the streaming endpoints when true
	 * @param string $engine override the configured engine (ollama, openai, llama, local)
	 */
	public function __construct($stream = false, string $engine = '') {
		//prepare to use the settings object
		global $settings;

		//set whether to stream or not
		$this->stream = $stream;

		//build the setting object and get the recording path
		$this->api_key = $settings->get('language_model', 'api_key', '');

		//an explicit engine wins; otherwise use the one configured in the settings
		$engine = $engine !== '' ? $engine : (string)$settings->get('language_model', 'engine', '');

		//only known engines are valid (prevents class injection via the engine name)
		if (!in_array($engine, ['openai', 'ollama', 'llama', 'local'], true)) {
			$engine = '';
		}
		$this->engine = $engine;
	}

	/**
	 * get_models
	 * get the list of available models
	 */
	public function get_models() : array {
		if (!empty($this->engine)) {
			//set the class interface to use the _template suffix
			$class_name = 'language_model_'.$this->engine;

			//create the object
			$object = new $class_name();

			//ensure the class has implemented the translate_interface interface
			if ($object instanceof language_model_interface) {
				$response = $object->get_models();
				return $response;
			}
		}
		return [];
	}

	/**
	 * request
	 */
	public function request($model, $content) : string {
		if (!empty($this->engine)) {
			//set the class interface to use the _template suffix
			$class_name = 'language_model_'.$this->engine;

			//create the object
			$object = new $class_name();

			//ensure the class has implemented the translate_interface interface
			if ($object instanceof language_model_interface) {
				$object->stream = $this->stream;
				//$object->set_target_language_model($this->target_language_model);
				//$object->set_translate_message($this->translate_message);
				$response = $object->request($model, $content);
				return $response;
			}
		}
		return '';
	}

	/**
	 * chat
	 * Non-streaming multi-message chat with optional tool calling.
	 * Dispatches to the configured engine (see language_model_ollama::chat).
	 *
	 * @param string $model    model name
	 * @param array  $messages Ollama /api/chat messages
	 * @param array|null $tools Ollama 'tools' array or null
	 * @param array  $opts     ['temperature' => float, 'timeout' => int, 'api_url' => string,
	 *                          'think' => bool, 'stream' => bool, 'on_progress' => callable]
	 * @return array ['ok', 'content', 'tool_calls', 'thinking', 'prompt_tokens',
	 *               'completion_tokens', 'eval_duration', 'total_duration', 'error']
	 */
	public function chat(string $model, array $messages, ?array $tools = null, array $opts = []): array {
		if (!empty($this->engine)) {
			//set the class interface to use the _template suffix
			$class_name = 'language_model_'.$this->engine;

			//create the object
			$object = new $class_name();

			//ensure the class has implemented the interface and supports chat
			if ($object instanceof language_model_interface && method_exists($object, 'chat')) {
				return $object->chat($model, $messages, $tools, $opts);
			}
		}
		return [
			'ok' => false,
			'content' => '',
			'tool_calls' => [],
			'thinking' => '',
			'prompt_tokens' => 0,
			'completion_tokens' => 0,
			'eval_duration' => 0,
			'total_duration' => 0,
			'error' => 'no language model engine configured',
		];
	}
}
