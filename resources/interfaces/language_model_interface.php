<?php

//define the template class
interface language_model_interface {
	//public function set_source_language_model(string $source_language_model);
	//public function set_target_language_model(string $target_language_model);
	public function get_models() : array;
	public function request(string $model, array $content);

	// URL reading support
	// Engines that do not fetch URLs must return a no-intent result / no URLs
	public function parse_read_prompt(string $prompt, ?string $language = null): array;
	public function get_intent_keywords(string $language): array;
	public function find_intent_keywords(string $text, array $keywords): array;
	public function extract_url(string $text): ?string;
	public function detect_language(string $text): string;
	public function extract_urls(string $text): array;
}
