<?php

/**
 * Parses content according to settings
 */
class rssMBParser {

	/**
	 * Parse content
	 * 
	 * @global object $mb_feed_importer
	 * @param object $item Feed item
	 * @param string $feed_title Feed title
	 * @param boolean $strip_html whether to strip html tags
	 * @return type
	 */
	function _parse($item, $feed_title, $strip_html) {

		global $mb_feed_importer;

		// get the saved template
		$post_template = $mb_feed_importer->options['settings']['post_template'];

		// get the content
		$c = $item->get_content() != "" ? $item->get_content() : $item->get_description();
		$c = apply_filters('pre_rss_mb_parse_content', $c);

		$c = $this->escape_backreference($c);

		// $pubDate = $item->get_date();

		// if ( class_exists( 'PC' ) ) { 
		// 	PC::debug( $pubDate, 'Date' );
		// }


		// var_dump($pubDate);
		// print_r("Hello");

		// do all the replacements
		$parsed_content = preg_replace('/\{\$content\}/i', $c, $post_template);
		$parsed_content = preg_replace('/\{\$feed_title\}/i', $feed_title, $parsed_content);
		$parsed_content = preg_replace('/\{\$title\}/i', $item->get_title(), $parsed_content);
		//$parsed_content = preg_replace('/\{\$pub_date\}/i', $pubDate, $parsed_content);
		// check if we need an excerpt
		$parsed_content = $this->_excerpt($parsed_content, $c);


		// if ( class_exists( 'PC' ) ) { 
		// 	PC::debug( $parsed_content, 'Date' );
		// }

		// strip html, if needed
		if ($strip_html == 'true') {
			$parsed_content = strip_tags($parsed_content);
		}

		$parsed_content = preg_replace('/\{\$permalink\}/i', '<a href="' . esc_url($item->get_permalink()) . '" target="_blank">' . $item->get_title() . '</a>', $parsed_content);


		$parsed_content = apply_filters('after_rss_mb_parse_content', $parsed_content);

		//var_dump($parsed_content);
		return $parsed_content;
	}

	/*
	 *
	 * 	Escape $n backreferences
	 */
	function escape_backreference($x) {

		return preg_replace('/\$(\d)/', '\\\$$1', $x);
	}

	/**
	 * Checks and creates an excerpts
	 * 
	 * @param string $content Content
	 * @return string
	 */
	private function _excerpt($content, $c) {

		// if there's an excerpt placeholder
		preg_match('/\{\$excerpt\:(\d+)\}/i', $content, $matches);

		// if there's a wordcount
		$e_size = (is_array($matches) && !empty($matches)) ? $matches[1] : 0;

		// cut it down and replace the placeholder
		if ($e_size) {
			$trimmed_c = preg_replace('/<!--(.|\s)*?-->/', '', $c);
			// compulsorily strip html otherwise there'll be broken html all over
			$stripped_c = strip_tags($trimmed_c);
			$content = preg_replace('/\{\$excerpt\:\d+\}/i', wp_trim_words($stripped_c, $e_size), $content);
		}

		return $content;
	}

}
