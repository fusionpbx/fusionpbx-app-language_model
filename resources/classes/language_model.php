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
	 */
	public function __construct($stream = false) {
		//prepare to use the settings object
		global $settings;

		//set whether to stream or not
		$this->stream = $stream;

		//build the setting object and get the recording path
		$this->api_key = $settings->get('language_model', 'api_key', '');
		$this->engine = $settings->get('language_model', 'engine', '');
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

        /**
         * get_prompt_template
         */
        public function get_prompt_template(string $category) : string {
                global $database;

                $sql = "
                        select template_prompt
                        from v_language_model_prompt_templates
                        where template_category = :category
                        and template_enabled = true
                        order by template_name
                        limit 1
                ";

                $result = $database->select(
                        $sql,
                        ['category' => $category],
                        'column'
                );

                return $result ?: '';
        }

        /**
         * build_prompt
         */
        public function build_prompt(string $category, string $runtime_data) : string {
                $template = $this->get_prompt_template($category);

                if (empty($template)) {
                        return $runtime_data;
                }

                return str_replace('{{maintenance_data}}', $runtime_data, $template);
        }

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

}
