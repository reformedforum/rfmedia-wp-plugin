<?php
/*
Plugin Name:  Reformed Forum Media
Version: 2.0
Plugin URI: http://www.reformedforum.com/
Description: handles the attachment of media files to rss feeds
Author: Camden Bucey
Author URI: http://www.reformedforum.org/
*/

// Include the getid3 library to parse media files
//include("getid3/getid3.php");



function rfmedia_action_rss2_ns() {
	echo 'xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"' . "\n\t";
	echo 'xmlns:media="http://search.yahoo.com/mrss/"' . "\n\t";
}
add_action('rss2_ns', 'rfmedia_action_rss2_ns');

function rfmedia_has_media() {
	global $post;
	global $wpdb;

	$results = rfmedia_get_media($post->post_name);

	if ( !empty($results) ) {
		return true;
	}

	return false;
}

function rfmedia_get_assets($postname) {
	global $wpdb;

	$results = $wpdb->get_results( $wpdb->prepare( "SELECT a.*, t.name type FROM assets a, types t WHERE a.type_id = t.id AND tag = %s AND active = 1", $postname ), OBJECT );

	return $results;
}

function rfmedia_get_media($postname) {
	global $post;

	// Prefer the canonical audio URL stored in postmeta (Captivate for migrated
	// episodes, Libsyn/S3/external for the rest). Single source of truth post-migration.
	$pid = ( $post && isset($post->post_name) && $post->post_name === $postname )
	       ? $post->ID : rfmedia_post_id_by_slug($postname);

	if ( $pid ) {
		$audio_url = get_post_meta($pid, 'rf_podcast_audio_url', true);
		if ( !empty($audio_url) ) {
			$m = new stdClass();
			$m->tag          = $postname;
			$m->url          = $audio_url;
			$m->download_url = $audio_url;
			$m->mime_type    = 'audio/mpeg';
			// Captivate uses dynamic ad insertion => byte length is not fixed; emit 0.
			$m->filesize     = ( strpos($audio_url, 'captivate.fm') !== false )
			                   ? 0 : (int) get_post_meta($pid, 'rf_audio_filesize', true);
			$m->duration     = get_post_meta($pid, 'rf_audio_duration', true);
			$m->type         = 'audio';
			$m->active       = 1;
			$m->thumbnail_url= '';
			return array($m);
		}
	}

	// FALLBACK: legacy assets table (un-migrated / non-podcast-post episodes).
	$results = rfmedia_get_assets($postname);
	$media = array();
	foreach ($results as $asset) {
		$asset->download_url = $asset->url;
		array_push($media, $asset);
	}
	return $media;
}

// Resolve a podcast/post episode id from its slug.
function rfmedia_post_id_by_slug($slug) {
	$p = get_posts( array( 'name' => $slug, 'post_type' => array('podcast','post'),
		'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ) );
	return $p ? (int) $p[0] : 0;
}

function rfmedia_build_download_link($result, $method = "web") {
	// create download link
	if ( !empty($result->download_url) ) {
		return $result->download_url;
	} else {
		return $result->url;
	}
}

/**
 * Extract a YouTube video ID from any common URL shape.
 */
function rfmedia_youtube_id($url) {
	if ( preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|v/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', (string) $url, $m) ) {
		return $m[1];
	}
	return '';
}

/**
 * Extract a Vimeo numeric ID from any common URL shape.
 */
function rfmedia_vimeo_id($url) {
	if ( preg_match('~vimeo\.com/(?:video/|channels/[^/]+/|groups/[^/]+/videos/)?(\d+)~', (string) $url, $m) ) {
		return $m[1];
	}
	return '';
}

/**
 * Self-contained player styles, emitted once per request.
 */
function rfmedia_player_styles() {
	static $printed = false;
	if ( $printed ) {
		return '';
	}
	$printed = true;
	return '<style id="rfmp-styles">'
		. '.rfmp{margin:0 0 1.75rem;}'
		. '.rfmp-video{position:relative;width:100%;padding-top:56.25%;background:#000;border-radius:10px;overflow:hidden;margin:0 0 1.25rem;}'
		. '.rfmp-video iframe{position:absolute;top:0;left:0;width:100%;height:100%;border:0;}'
		. '.rfmp-audio{display:flex;align-items:center;flex-wrap:wrap;gap:.75rem 1rem;margin:0 0 1.25rem;padding:1rem 1.15rem;background:#f7f7f7;border:1px solid rgba(28,28,27,.12);border-radius:10px;}'
		. '.rfmp-audio__label{font:600 .72rem/1 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;text-transform:uppercase;letter-spacing:.16em;color:#595448;}'
		. '.rfmp-audio__player{flex:1 1 300px;min-width:0;height:40px;}'
		. '.rfmp-audio__dl{font:500 .82rem/1 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#a8353a;text-decoration:none;white-space:nowrap;}'
		. '.rfmp-audio__dl:hover{text-decoration:underline;}'
		. '</style>';
}

/**
 * Prepend an attractive video embed (YouTube preferred, Vimeo fallback) and a
 * styled audio player to single podcast content, driven by the rf_* postmeta.
 */
function rfmedia_add_player($content) {
	global $post;

	if ( ! $post || get_post_type($post->ID) != 'podcast' || ! is_single($post->ID) ) {
		return $content;
	}
	if ( strpos($content, 'class="rfmp"') !== false ) { // guard: don't inject twice.
		return $content;
	}

	$audio   = trim( (string) get_post_meta($post->ID, 'rf_podcast_audio_url', true) );
	$youtube = trim( (string) get_post_meta($post->ID, 'rf_youtube_url', true) );
	$vimeo   = trim( (string) get_post_meta($post->ID, 'rf_vimeo_url', true) );

	// Resolve a video embed URL (YouTube preferred, Vimeo fallback).
	$embed = '';
	$yid   = $youtube ? rfmedia_youtube_id($youtube) : '';
	if ( $yid ) {
		$embed = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($yid) . '?rel=0&modestbranding=1';
	} else {
		$vid = $vimeo ? rfmedia_vimeo_id($vimeo) : '';
		if ( $vid ) {
			$embed = 'https://player.vimeo.com/video/' . rawurlencode($vid);
		}
	}

	// Don't double up if the post body already EMBEDS a video (an actual iframe).
	// A mere link to YouTube in the show notes must NOT count. This filter runs at
	// priority 10, after core's autoembed (priority 8), so bare URLs on their own
	// line have already been converted to iframes by the time we look.
	$has_video_in_content = (bool) preg_match(
		'~<iframe[^>]+src=["\']?[^"\'>]*(?:youtube\.com/embed|youtube-nocookie\.com/embed|youtu\.be/|player\.vimeo\.com)~i',
		$content
	);

	$html = '';

	if ( $embed && ! $has_video_in_content ) {
		$html .= '<div class="rfmp-video"><iframe src="' . esc_url($embed) . '" title="' . esc_attr(get_the_title($post)) . '" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div>';
	}

	if ( $audio && stripos($content, '<audio') === false && stripos($content, 'wp-audio-shortcode') === false ) {
		$html .= '<div class="rfmp-audio">'
			. '<span class="rfmp-audio__label">Listen</span>'
			. '<audio class="rfmp-audio__player" controls preload="none" src="' . esc_url($audio) . '"></audio>'
			. '<a class="rfmp-audio__dl" href="' . esc_url($audio) . '" download>Download MP3</a>'
			. '</div>';
	}

	if ( '' !== $html ) {
		$content = rfmedia_player_styles() . '<div class="rfmp">' . $html . '</div>' . $content;
	}

	return $content;
}
add_action('the_content', 'rfmedia_add_player');

function rfmedia_check_type($type) {
	if ( in_array( $type, array('audio', 'video-large', 'video-medium', 'video-small') ) ) {
		return true;
	} else {
		return false;
	}
}

function rfmedia_format_type($type) {
	if (rfmedia_check_type($type)) {

		$m = preg_split('/-/', $type);
		if (count($m) > 1) $type = $m[0] . " (" . $m[1] . ")";

		return $type;

	} else {
		return null;
	}
}

function rfmedia_title_rss($text) {
	return rfmedia_show_name('program', false);
}
add_filter('wp_title_rss', 'rfmedia_title_rss');

function rfmedia_action_rss2_media() {
	global $post;

	$results = rfmedia_get_media($post->post_name);


	if (!empty($results)) {

		if (count($results) > 1) echo '<media:group>';

		// check url for flag that lets us know which mediatype should be <media:content isDefault="true" /> as well as <enclosure />
		if (!empty($_GET['type'])) {
			$defaultFiletype = $_GET['type'];
		} else {
			$defaultFiletype = "audio";
		}

		foreach($results as $result) {


			// create download link
			$result->url = rfmedia_build_download_link($result, 'feed');

			if ($result->type == $defaultFiletype) {
				$isDefault = "true";
				$enclosure = $result; // save this for the enclosure which comes later
				$enclosure->url = $result->url;
			} else {
				$isDefault = "false";
			}

			if (count($results) > 1) {
				echo '<media:content';
				echo '	expression="full"
						fileSize="' . $result->filesize . '"
						isDefault="' . $isDefault . '"
						type="' . $result->mime_type . '"
						url="' . $result->url . '">';

				if (!empty($result->thumbnail_url)) {
					echo '<media:thumbnail url="' . $result->thumbnail_url . '" height="" width="" />';
				}

				echo '</media:content>';
			}
		}

		if (count($results) > 1) echo '</media:group>';


		if (!empty($enclosure)) {

			if ( empty($enclosure->filesize) ) { $enclosure->filesize = 0; }

			// add enclosure tag - data is in $enclosure
			echo '<enclosure
					url="' . $enclosure->url . '"
					length="' . $enclosure->filesize . '"
					type="' . $enclosure->mime_type . '" />';

			// add iTunes items
			//$cleanContent = "<![CDATA[" . rfmedia_strip_html_tags(html_entity_decode($post->post_content)) . "]]>";
			if ( !empty($enclosure->duration) ) echo '<itunes:duration>' . $enclosure->duration . '</itunes:duration>';
			echo '<itunes:subtitle>' . substr(rfmedia_custom_trim_excerpt($cleanContent), 0, 255) . '</itunes:subtitle>';
			echo '<itunes:summary>' . substr($cleanContent, 0, 255) . '</itunes:summary>';


			$post_categories = wp_get_post_categories( $post->ID );
			$cats = array();

			foreach($post_categories as $c) {
				$cat = get_category( $c );
				if (strlen(implode(", ", $cats)) <= (253 - strlen($cat->name))) {
					array_push($cats, $cat->name);
				}
			}

			echo '<itunes:keywords>' . preg_replace('/\s+/', '', implode(", ", $cats)) . '</itunes:keywords>';
			echo '<itunes:author>Reformed Forum</itunes:author>';
			echo '<itunes:explicit>false</itunes:explicit>';
			echo '<itunes:block>no</itunes:block>';


		}
	}
}
add_action('rss2_item', 'rfmedia_action_rss2_media');

function rfmedia_action_rss2_channel() {
    echo '<image><title>' . rfmedia_show_name('program', false) . '</title>';
    echo '<url>' . get_show_image(null, 144) . '</url>';
    echo '<link>' . rfmedia_show_link('program', false) . '</link>';
    echo '<width>144</width><height>144</height>';
    echo '<description>' . rfmedia_show_name('program', false) . ' podcast art.</description></image>';
    echo '<itunes:image href="' . get_show_image() . '" />';
    echo '<itunes:author>Reformed Forum</itunes:author>';
	echo '<itunes:explicit>no</itunes:explicit>';
	echo '<itunes:owner>
			<itunes:name>Reformed Forum</itunes:name>
			<itunes:email>mail@reformedforum.org</itunes:email>
		</itunes:owner>';
	echo '<itunes:category text="Religion &amp; Spirituality"><itunes:category text="Christianity"/></itunes:category>';
}
//add_action('rss2_head', 'rfmedia_action_rss2_channel');
///add_action('rss_head', 'rfmedia_action_rss2_channel');

// Channel-level iTunes elements for the locally-generated feeds (category/topic/program).
// Static + safe on any feed; clears the "missing itunes:category / itunes:explicit" warnings.
function rfmedia_rss2_channel_itunes() {
	echo '<itunes:explicit>false</itunes:explicit>';
	echo '<itunes:category text="Religion &amp; Spirituality"><itunes:category text="Christianity"/></itunes:category>';
}
add_action('rss2_head', 'rfmedia_rss2_channel_itunes');

if (!function_exists('rfmedia_strip_html_tags')) {
	/**
	 * Remove HTML tags, including invisible text such as style and
	 * script code, and embedded objects.  Add line breaks around
	 * block-level tags to prevent word joining after tag removal.
	 */
	function rfmedia_strip_html_tags( $text ) {
		$text = preg_replace(
			array(
			  // Remove invisible content
				'@<head[^>]*?>.*?</head>@siu',
				'@<style[^>]*?>.*?</style>@siu',
				'@<script[^>]*?.*?</script>@siu',
				'@<object[^>]*?.*?</object>@siu',
				'@<embed[^>]*?.*?</embed>@siu',
				'@<applet[^>]*?.*?</applet>@siu',
				'@<noframes[^>]*?.*?</noframes>@siu',
				'@<noscript[^>]*?.*?</noscript>@siu',
				'@<noembed[^>]*?.*?</noembed>@siu',
			  // Add line breaks before and after blocks
				'@</?((address)|(blockquote)|(center)|(del))@iu',
				'@</?((div)|(h[1-9])|(ins)|(isindex)|(p)|(pre))@iu',
				'@</?((dir)|(dl)|(dt)|(dd)|(li)|(menu)|(ol)|(ul))@iu',
				'@</?((table)|(th)|(td)|(caption))@iu',
				'@</?((form)|(button)|(fieldset)|(legend)|(input))@iu',
				'@</?((label)|(select)|(optgroup)|(option)|(textarea))@iu',
				'@</?((frameset)|(frame)|(iframe))@iu',
			),
			array(
				' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ',
				"\n\$0", "\n\$0", "\n\$0", "\n\$0", "\n\$0", "\n\$0",
				"\n\$0", "\n\$0",
			),
			$text );
		return strip_tags( $text );
	}
}

if (!function_exists('rfmedia_custom_trim_excerpt')) {
	function rfmedia_custom_trim_excerpt($text) { // Fakes an excerpt if needed
		global $post;
		if ( '' == $text ) {
			$text = get_the_content('');

			$text = strip_shortcodes( $text );

			//$text = apply_filters('the_content', $text);
			$text = str_replace(']]>', ']]&gt;', $text);
			$text = strip_tags($text);
			$text = html_entity_decode($text);
			$text = urldecode($text);
			// Replace non-alphanumeric characters with space
			$text = preg_replace('/[^A-Za-z0-9]/', ' ', $text);
			// Replace Multiple spaces with single space
			$text = preg_replace('/ +/', ' ', $text);
			// Trim the string of leading/trailing space
			$text = trim($text);

			$excerpt_length = apply_filters('excerpt_length', 30);
			$words = explode(' ', $text, $excerpt_length + 1);
			if (count($words) > $excerpt_length) {
				array_pop($words);
				array_push($words, '...');
				$text = implode(' ', $words);
			}
		}
		return $text;
	}
}

/**
 * Generate an excerpt that preserves inline HTML (links, emphasis, etc.)
 * while trimming to a specified word count and closing any open tags.
 *
 * Hooks into wp_trim_excerpt so Elementor Posts widgets and standard
 * the_excerpt() calls will use this instead of the default.
 */
if (!function_exists('rfmedia_html_excerpt')) {
	function rfmedia_html_excerpt($text, $raw_excerpt = '') {
		// If a manual excerpt is set, use it as-is
		if ($raw_excerpt !== '') {
			return $raw_excerpt;
		}

		$text = get_the_content('');
		$text = strip_shortcodes($text);
		$text = apply_filters('the_content', $text);
		$text = str_replace(']]>', ']]&gt;', $text);

		$allowed_tags = '<a><em><strong><i><b><span><br><sup><sub>';
		$excerpt_length = apply_filters('excerpt_length', 55);
		$excerpt_more = apply_filters('excerpt_more', ' [&hellip;]');

		// Strip everything except allowed inline tags
		$text = strip_tags($text, $allowed_tags);

		// Split into words while preserving HTML tags
		$words = preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

		$word_count = 0;
		$output = '';

		foreach ($words as $piece) {
			// Check if this piece is only whitespace/delimiter
			if (preg_match('/^\s+$/', $piece)) {
				$output .= $piece;
				continue;
			}

			// Count visible words (ignore HTML-only tokens)
			$stripped = strip_tags($piece);
			if (trim($stripped) !== '') {
				$word_count++;
				if ($word_count > $excerpt_length) {
					break;
				}
			}

			$output .= $piece;
		}

		// Close any open HTML tags
		$output = force_balance_tags($output);

		if ($word_count > $excerpt_length) {
			$output .= $excerpt_more;
		}

		return $output;
	}
	add_filter('wp_trim_excerpt', 'rfmedia_html_excerpt', 10, 2);
}

if (!function_exists('getImage')) {
	function getImage($id) {
		global $post, $wpdb;

		$img = '';

		if ( has_post_thumbnail($id) ) { // TODO: Problem, has_post_thumbnail() returns true, but get_the_post_thumbnail($id) returns an empty string
			//$image = get_the_post_thumbnail($id);

			$thumbId = get_post_thumbnail_id($id);
			if (!empty($thumbId)) {
				$qry = 'SELECT * FROM wp_postmeta WHERE meta_key = \'_wp_attached_file\' AND post_id = ' . $thumbId;
				$results = $wpdb->get_results( $qry );
				if (!empty($results)) {
					$img = '/files/' . $results[0]->meta_value;
				}
			}
			//preg_match('/<\s*img [^\>]*src\s*=\s*[\""\']?([^\""\'\s>]*)/i', $image, $match);
			//$img = $match[1];
		}

		if (empty($img)) {
			$img = get_show_image($id);
		}

		return $img;
	}
}

if (!function_exists('get_show_image')) {
	function get_show_image($id = null, $size = 1400) {
		global $post;
		$code = ( $id == null ) ? get_query_var('term') : get_show_code($id);

		// Per-program covers (downloaded from Captivate, self-hosted on reformedforum.org).
		// $code is the 'programs' taxonomy slug; unmapped programs use default.jpg.
		$base  = 'https://reformedforum.org/wp-content/uploads/rf-podcast-art/';
		$known = array( 'ctc', 'tsp', 'proclaimingchrist', 'rmr', 'reformedacademy' );
		$file  = in_array( $code, $known, true ) ? $code : 'default';

		return $base . $file . '.jpg';
	}
}

if (!function_exists('get_show_code')) {
	function get_show_code($postId) {
		global $post;

		$terms = get_the_terms($post->ID, 'programs');

		if (!empty($terms)) {

			foreach ($terms as $term) {
				return $term->slug;
			}

		}

		return null;
	}
}

if (!function_exists('rfmedia_get_the_show_link')) {
	function rfmedia_get_the_show_link() {
		global $post;
		$terms = get_the_terms($post->ID, 'programs');
		$str = '';

		foreach ($terms as $term) {
			$str .= '<a href="http://reformedforum.org/programs/' . $term->slug . '">' . $term->name . '</a>';
		}

		return $str;
	}
}

if (!function_exists('rfmedia_show_link')) {
	function rfmedia_show_link($type = 'podcast', $echo = true) {
		global $post;
		$termvar = get_query_var('term');

		if ( $type == 'podcast' ) {
			$terms = get_the_terms($post->ID, 'programs');
			foreach ($terms as $term) {
				echo '<a href="http://reformedforum.org/programs/' . $term->slug . '">' . $term->name . '</a>';
			}
		} elseif ( !empty($termvar) && $termvar != 'post-format-audio' && $termvar != 'post-format-video') {
			$term = get_term_by( 'slug', $termvar, get_query_var('taxonomy') );
			return 'http://reformedforum.org/programs/' . $term->slug;
		} else {
			return 'http://reformedforum.org/';
		}
	}

	add_shortcode( 'rfmedia_show_link', 'rfmedia_show_link' );
}

if (!function_exists('rfmedia_get_the_show_name')) {
	function rfmedia_get_the_show_name($type = 'podcast') {
		global $post;
		$termvar = get_query_var('term');

		if ( $type == 'podcast' ) {
			$terms = get_the_terms($post->ID, 'programs');
			foreach ($terms as $term) {
				return $term->name;
			}
		} elseif ( !empty($termvar) && $termvar != 'post-format-audio' && $termvar != 'post-format-video') {
			$term = get_term_by( 'slug', $termvar, get_query_var('taxonomy') );
			return $term->name;
		} else {
			return 'Reformed Forum';
		}
	}
}

if (!function_exists('rfmedia_show_name')) {
	function rfmedia_show_name($type = 'podcast', $echo = true) {
		global $post;
		$termvar = get_query_var('term');

		if ( $type == 'podcast' ) {
			$terms = get_the_terms($post->ID, 'programs');
			foreach ($terms as $term) {
				echo $term->name;
			}
		} elseif ( !empty($termvar) && $termvar != 'post-format-audio' && $termvar != 'post-format-video') {
			$term = get_term_by( 'slug', $termvar, get_query_var('taxonomy') );
			return $term->name;
		} else {
			return 'Reformed Forum';
		}
	}
}

if (!function_exists('rfmedia_show_description')) {
	function rfmedia_show_description($type = 'post', $echo = true) {
		global $post;
		$termvar = get_query_var('term');

		if ( $type == 'post' ) {
			$terms = get_the_terms($post->ID, 'programs');
			foreach ($terms as $term) {
				echo $term->description;
			}
		} elseif ( !empty($termvar) && $termvar != 'post-format-audio' && $termvar != 'post-format-video') {
			$term = get_term_by( 'slug', $termvar, get_query_var('taxonomy') );
			return $term->description;
		}

		return null;
	}

	add_shortcode( 'rfmedia_show_description', 'rfmedia_show_description' );
}

if (!function_exists('podcast_info')) {
	function podcast_info($content) {
		global $post;

		if ( !preg_match('/Participants:/', $content) ) { // for some reason this function sometimes fires twice, this prevents adding the podcast info twice
			$terms = get_the_terms($post->ID, 'programs');
			if ( is_array($terms) ) $program = array_pop($terms);

			if ( !empty($program) ) {

				$category = get_the_term_list(get_the_ID(), 'people', '', '<span class="sep">,</span> ' , '' );
				if (!empty($category)) $content .= "<p class='podcast-participants'>Participants: " . $category . "</p>";

				if ( is_single() ) {
					$content .= '<div class="podcast-info">';
					$content .= '<a href="/programs/' . $program->slug . '">';
						// print the image
						$content .= '<img src="' . get_show_image($post->ID, 300) . '" width="150" height="auto" />';
					$content .= '</a>';
					$content .= '<p>' . $program->description . ' <a href="/programs/' . $program->slug . '">Browse more episodes</a> from this program or subscribe to the <a href="/programs/' . $program->slug . '/feed">podcast feed</a>.</p>';
					$content .= '</div>';
				}
			}
		}

		return $content;
	}
	add_action('the_content', 'podcast_info');
}

if (!function_exists('has_rf_thumbnail')) {
	// this function exists so that child themes can opt not to display an image if there is no post thumbnail, it gets used in the various display functions
	function has_rf_thumbnail() {
		global $post;

		$test = false;
		$test = has_post_thumbnail();
		if (!$test && get_post_type($post->ID) != "podcast") {
			return false;
		} else {
			return true;
		}
	}
}

if (!function_exists('rf_thumb_wide_check')) {
	function rf_thumb_wide_check() {
		global $post;

		$post_thumbnail_id = get_post_thumbnail_id();
		$src = wp_get_attachment_image_src( $post_thumbnail_id, 'full' );

		if ( !empty($src) ) {
			$width = $src[1];
			$height = $src[2];

			if ( ( $width / $height) >= 1.6) { // For wide aspect images, use the "content" size, otherwise use the square thumbnail.
				return true;
			}
		}

		return false;
	}
}

if (!function_exists('rf_thumb')) {
	function rf_thumb($size = 'thumbnail', $attributes = array()) {
		global $post;

		$thumbs = array(
			'thumbnail' => array('width'=>150, 'height'=>150),
			'post-thumbnail' => array('width'=>150, 'height'=>150),
			'slider' => array('width'=>2000, 'height'=>500),
			'content' => array('width'=>768, 'height'=>400),
			'small' => array('width'=>54, 'height'=>54)
		);

		$test = false;
		$test = has_post_thumbnail();

		if ( $test ) {
			if ($size == 'check') {
				// check for size dimensions
				if ( rf_thumb_wide_check() ) {
					$size = 'content';
				} else {
					$size = 'thumbnail';
				}
			}

			the_post_thumbnail($size, $attributes);

		} elseif ($post->post_type == 'podcast') {
			$img = getImage($post->ID);

			// print the image
			echo '<img src="' . get_option('home') . '/wp-content/themes/rf4/scripts/timthumb.php?src=' . $img . htmlentities('&w=' . $thumbs[$size]['width'] . '&h=' . $thumbs[$size]['height'] . '&zc=1&q=100') . '" ';

			// add its specified attributes
			foreach ($attributes as $key=>$value) {
				echo $key . '="' . $value . '" ';
			}

			echo '/>';
		}
	}
}

if (!function_exists('custom_trim_excerpt')) {
	function custom_trim_excerpt($text) { // Fakes an excerpt if needed
		global $post;
		if ( '' == $text ) {
			$text = get_the_content('');

			$text = strip_shortcodes( $text );

			//$text = apply_filters('the_content', $text);
			$text = str_replace(']]>', ']]&gt;', $text);
			$text = strip_tags($text);
			$excerpt_length = apply_filters('excerpt_length', 30);
			$words = explode(' ', $text, $excerpt_length + 1);
			if (count($words) > $excerpt_length) {
				array_pop($words);
				array_push($words, '… <a href="' . get_permalink() . '">Read more&rarr;</a>');
				$text = implode(' ', $words);
			}
		}
		return $text;
	}
}

if (!function_exists('custom_feed_request')) {
	function custom_feed_request($qv) { // adds custom post types back into main feed (i.e. reformedforum.org/feed)
		if (isset($qv['feed']) && !isset($qv['post_type']))
			$qv['post_type'] = array('post', 'podcast'); // this eliminates books from the main feed
			return $qv;
	}
	add_filter('request', 'custom_feed_request');
}

if (!function_exists('namespace_add_custom_types')) {
	function namespace_add_custom_types( $query ) {
	  if ( is_archive() && !is_author() && empty( $query->query_vars['suppress_filters'] ) && empty($query->query_vars['post_type']) ) {
		$query->set( 'post_type', array(
		 'post', 'podcast', 'book'
			));
		  return $query;
		}
	}
	add_filter( 'pre_get_posts', 'namespace_add_custom_types' );
}

/**
 * Include posts from authors in the search results where
 * either their display name or user login matches the query string
 *
 * @author danielbachhuber
 */
add_filter( 'posts_search', 'db_filter_authors_search' );
function db_filter_authors_search( $posts_search ) {

	// Don't modify the query at all if we're not on the search template
	// or if the LIKE is empty
	if ( !is_search() || empty( $posts_search ) )
		return $posts_search;

	global $wpdb;
	// Get all of the users of the blog and see if the search query matches either
	// the display name or the user login
	add_filter( 'pre_user_query', 'db_filter_user_query' );
	$search = sanitize_text_field( get_query_var( 's' ) );
	$args = array(
		'count_total' => false,
		'search' => sprintf( '*%s*', $search ),
		'search_fields' => array(
			'display_name',
			'user_login',
		),
		'fields' => 'ID',
	);
	$matching_users = get_users( $args );
	remove_filter( 'pre_user_query', 'db_filter_user_query' );
	// Don't modify the query if there aren't any matching users
	if ( empty( $matching_users ) )
		return $posts_search;
	// Take a slightly different approach than core where we want all of the posts from these authors
	$posts_search = str_replace( ')))', ")) OR ( {$wpdb->posts}.post_author IN (" . implode( ',', array_map( 'absint', $matching_users ) ) . ")))", $posts_search );
	return $posts_search;
}
/**
 * Modify get_users() to search display_name instead of user_nicename
 */
function db_filter_user_query( &$user_query ) {

	if ( is_object( $user_query ) )
		$user_query->query_where = str_replace( "user_nicename LIKE", "display_name LIKE", $user_query->query_where );
	return $user_query;
}

function create_post_type() {
    register_post_type( 'podcast',
        array(
            'labels' => array(
                'name' => __( 'Podcasts' ),
                'singular_name' => __( 'Podcast' )
            ),
            'public' => true,
            'has_archive' => true,
            'rewrite' => array('slug' => 'podcasts'),
            'show_in_rest' => true,
            'taxonomies'  => array( 'category' ),
            'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt', 'comments', 'page-attributes', 'custom-fields', 'post-formats' )
        )
    );
}
// Hooking up our function to theme setup
add_action( 'init', 'create_post_type' );

/**
 * Add custom taxonomies
 *
 * Additional custom taxonomies can be defined here
 * http://codex.wordpress.org/Function_Reference/register_taxonomy
 */
function add_custom_taxonomies() {
	// Add new "Programs" taxonomy
	register_taxonomy('programs',
		array('post', 'podcast'),
		array(
			// Hierarchical taxonomy (like categories)
			'hierarchical' => true,
			// This array of options controls the labels displayed in the WordPress Admin UI
			'labels' => array(
				'name' => _x( 'Programs', 'taxonomy general name' ),
				'singular_name' => _x( 'Program', 'taxonomy singular name' ),
				'search_items' =>  __( 'Search Programs' ),
				'all_items' => __( 'All Programs' ),
				'parent_item' => __( 'Parent Program' ),
				'parent_item_colon' => __( 'Parent Program:' ),
				'edit_item' => __( 'Edit Program' ),
				'update_item' => __( 'Update Program' ),
				'add_new_item' => __( 'Add New Program' ),
				'new_item_name' => __( 'New Program Name' ),
				'menu_name' => __( 'Programs' ),
		),
		'show_in_rest' => true,
		'rest_base'             => 'program',
    	'rest_controller_class' => 'WP_REST_Terms_Controller',
		// Control the slugs used for this taxonomy
		'rewrite' => array(
			'slug' => 'programs', // This controls the base slug that will display before each term
			'with_front' => true, // Display the category base before "/locations/"
			'hierarchical' => true // This will allow URL's like "/locations/boston/cambridge/"
		)
	));

	// Add new "Programs" taxonomy
	register_taxonomy('sections',
		array('post'),
		array(
			// Hierarchical taxonomy (like categories)
			'hierarchical' => true,
			// This array of options controls the labels displayed in the WordPress Admin UI
			'labels' => array(
				'name' => _x( 'Sections', 'taxonomy general name' ),
				'singular_name' => _x( 'Section', 'taxonomy singular name' ),
				'search_items' =>  __( 'Search Sections' ),
				'all_items' => __( 'All Sections' ),
				'parent_item' => __( 'Parent Section' ),
				'parent_item_colon' => __( 'Parent Section:' ),
				'edit_item' => __( 'Edit Section' ),
				'update_item' => __( 'Update Section' ),
				'add_new_item' => __( 'Add New Section' ),
				'new_item_name' => __( 'New Section Name' ),
				'menu_name' => __( 'Sections' ),
		),
		'show_in_rest' => true,
		'rest_base'             => 'section',
    	'rest_controller_class' => 'WP_REST_Terms_Controller',
		// Control the slugs used for this taxonomy
		'rewrite' => array(
			'slug' => 'sections', // This controls the base slug that will display before each term
			'with_front' => false, // Display the category base before "/locations/"
			'hierarchical' => true // This will allow URL's like "/locations/boston/cambridge/"
		)
	));

	// Add new "People" taxonomy
	register_taxonomy('people',
		array('post', 'podcast'),
		array(
			'hierarchical' => false,
			// This array of options controls the labels displayed in the WordPress Admin UI
			'labels' => array(
				'name' => _x( 'People', 'taxonomy general name' ),
				'singular_name' => _x( 'Person', 'taxonomy singular name' ),
				'search_items' =>  __( 'Search People' ),
				'all_items' => __( 'All People' ),
				'parent_item' => __( 'Parent Person' ),
				'parent_item_colon' => __( 'Parent Person:' ),
				'edit_item' => __( 'Edit Person' ),
				'update_item' => __( 'Update Person' ),
				'add_new_item' => __( 'Add New Person' ),
				'new_item_name' => __( 'New Person Name' ),
				'menu_name' => __( 'People' ),
			),
			'query_var' => true,
			'rewrite' => true,
			'show_in_rest' => true,
			'rest_base'             => 'person',
    		'rest_controller_class' => 'WP_REST_Terms_Controller',
		)
	);
}
add_action( 'init', 'add_custom_taxonomies', 0 );

/*************************** Post Types and Taxonomies **************************/

//wp_enqueue_style( 'elusive-icon-font', '/wp-content/plugins/rf-media/elusive-icons-2.0.0/css/elusive-icons.min.css');

add_image_size( 'blog-single', 1140, 500, true );

function rfmedia_person_browse() {

    $str = "";
    $terms = get_terms(array('people'));
    $formattedCats = array();

    // List of common suffixes that we want to handle
    $suffixes = array('Jr.', 'Sr.', 'II', 'III', 'IV', 'V', 'Esq.');

    // Re-sort and rename by last name
    foreach ($terms as $term) {
        // Split the name by spaces
        $names = explode(' ', $term->name);
        $last_name = '';

        // Check if the last element is a suffix
        if (in_array(end($names), $suffixes)) {
            // Pop the suffix and store it
            $suffix = array_pop($names);
        } else {
            $suffix = '';
        }

        // The last element should be the last name
        $last_name = array_pop($names);

        // Reconstruct the name as "Last Name, First Name(s) Suffix"
        $term->name = $last_name . ', ' . implode(' ', $names);
        if ($suffix) {
            $term->name .= ' ' . $suffix;
        }

        // Store the formatted names with links
        array_push($formattedCats, array(
            'name' => $term->name,
            'link' => get_term_link($term->slug, 'people')
        ));
    }

    // Sort the array by the formatted name
    usort($formattedCats, function ($a, $b) {
        return strcmp($a['name'], $b['name']);
    });

    // Print the sorted list with the appropriate initial headers
    $initial = '';
    foreach ($formattedCats as $term) {
        if ($initial != substr($term['name'], 0, 1)) {
            $initial = substr($term['name'], 0, 1);
            if ($initial != 'A') {
                $str .= '</ul></div>';
            }
            $str .= '<div class="taxonomy-block"><h2 class="taxonomy-title';
            if ($initial == 'A') {
                $str .= ' first';
            };
            $str .= '">' . $initial . '</h2><ul class="taxonomy-list">';
        }

        $str .= '<li><a href="' . $term['link'] . '">' . $term['name'] . '</a></li>';
    }

    echo $str;
}
add_shortcode('rfmedia_person_browse', 'rfmedia_person_browse');

function rfmedia_series_browse() {
	$catId = get_cat_ID('Series');

	echo "<ul>";
	wp_list_categories('hide_empty=1&child_of='.$catId.'&title_li=');
	echo "</ul>";
}
add_shortcode( 'rfmedia_series_browse', 'rfmedia_series_browse' );


/***************** Custom Elementor Author Filters *****************/
/**
 * Gets a different avatar URL.
 *
 * @param string $url         The URL of the avatar.
 * @param mixed  $id Gravatar to get.
 * @return string $url The filtered URL.
 */
add_filter( 'get_avatar_url', function( $url, $id ) {
	global $post;

	if ( false ) { // check for certain people for whom we have better photos
		// get_the_author_meta('user_login', $id);
		// return null;
	}
	return $url;
}, 10, 2 );

function rfmedia_podcast_author($author_display_name) {
	global $post;

	if ( get_post_type($post->ID) == 'podcast' ) {
		return rfmedia_get_the_show_name();
	} else {
		return $author_display_name;
	}
}
add_filter( 'the_author', 'rfmedia_podcast_author' );
add_filter( 'get_the_author_display_name', 'rfmedia_podcast_author' );

function rfmedia_podcast_author_link($author_link) {
	global $post;

	if ( get_post_type($post->ID) == 'podcast' ) {
		return rfmedia_get_the_show_link();
	} else {
		return $author_link;
	}
}
add_filter( 'the_author_posts_link', 'rfmedia_podcast_author_link' );
add_filter( 'get_the_author_posts_link', 'rfmedia_podcast_author_link' );


/*************************** Custom Login Page ***************************/
function rf_login_screen_redirect() {
    global $pagenow;

    if ($pagenow == 'wp-login.php' && !is_user_logged_in()) {
        wp_redirect('/login/');
        exit;
    } else if ($pagenow == 'profile.php' && is_user_logged_in() && !current_user_can('editor')) {
        wp_redirect('/my-account/edit-account/');
        exit;
    } else if (is_page(26708) && !is_user_logged_in()) { // checks to see if an unauthenticated user is attempting to view the student profile page
        wp_redirect('/login/');
        exit;
    }
}
add_action('init', 'rf_login_screen_redirect');


/*************************** Custom Elementor Queries ***************************/
// Showing multiple post types in Posts Widget
add_action( 'elementor/query/published', function( $query ) {
	// Here we set the query to fetch only published items
	$query->set( 'post_status', 'publish' );
	$query->set( 'post_type', array('post', 'podcast') );
} );

// Showing multiple post types in Posts Widget
add_action( 'elementor/query/author_filter', function( $query ) {
	// Here we set the query to fetch posts with
	// post type of 'post' and 'podcast'
	$query->set( 'post_type', array('post', 'podcast') );
	$query->set( 'post_status', 'publish' );
	$query->set( 'posts_per_page', 4 );
} );

// Showing multiple post types in Posts Widget
add_action( 'elementor/query/combined', function( $query ) {
    $paged = max( 1, get_query_var('paged'), get_query_var('page') );
    $query->set( 'paged', $paged );
    $query->set( 'post_type', [ 'post', 'podcast', 'course' ] );
    $query->set( 'post_status', 'publish' );
    $query->set( 'ignore_sticky_posts', true );
    $query->set( 'no_found_rows', false ); // required for correct max_num_pages
    // Let the widget control posts_per_page so it matches other breakpoints:
    // $query->set( 'posts_per_page', 5 );
} );

// Showing multiple post types in Posts Widget
add_action( 'elementor/query/archive', function( $query ) {
	// Here we set the query to fetch posts with
	// post type of 'post' and 'podcast'
	$query->set( 'post_status', 'publish' );
	$query->set( 'posts_per_page', 10 );
} );

// Showing multiple post types in Posts Widget
add_action( 'elementor/query/home', function( $query ) {
	// Here we set the query to fetch posts with
	// post type of 'post' and 'podcast'
	$query->set( 'post_type', array('post', 'podcast') );
	$query->set( 'post_status', 'publish' );
	$query->set( 'posts_per_page', 8 );
} );

// Showing multiple post types in Posts Widget
add_action( 'elementor/query/posts_and_podcasts', function( $query ) {
	// Here we set the query to fetch posts with
	// post type of 'post' and 'podcast'
	$query->set( 'post_type', array('post', 'podcast') );
	$query->set( 'post_status', 'publish' );
} );

add_action('pre_get_posts', 'rf_product_query', 99);
function rf_product_query($query){
	if ( is_product_category() && $query->is_main_query() ) {
		$query->set( 'post_type', 'product' );
	}
}


// (rfmedia_save_podcast removed in refactor: the filesize-refresh loop is moot with
// postmeta + Captivate dynamic ad insertion, and the Libsyn auto-insert was disabled.
// New episodes get their audio URL via the rf_podcast_audio_url meta box.)

// prevent the sending of notifications for user password changes
if  ( !function_exists('wp_password_change_notification') ) {
	function wp_password_change_notification() {}
}


add_action( 'init', 'rf_old_url_rewrite' );
function rf_old_url_rewrite() {
    add_rewrite_rule('^(ctc|rmr|tsp|pc)([0-9]*)(.*)$', 'index.php?name=$matches[1]$matches[2]&$matches[3]', 'top');
}
// At some point, my custom posttype URLs quit working. This rewrite rule works.
// Previously, I had it in .htaccess, but it would get blown away with Wordpress updates.
// RewriteRule ^(ctc|rmr|tsp|pc)([0-9]*)(.*)$ https://www.reformedforum.org/podcasts/$1$2$3 [R=301,L]


function rf_embed_defaults($embed_size){
    $embed_size['width'] = 848;
    $embed_size['height'] = 477;
    return $embed_size;
}
add_filter('embed_defaults', 'rf_embed_defaults');


/* ########## QUEST ########## */

function rf23_quest_shortcode() {
    ob_start();  // Start output buffering

    global $wpdb;

    // Define the query
    $query = "
		SELECT r.id,
            CONCAT(r.first_name, ' ', r.last_name) AS name,
            COUNT(DISTINCT v2.value) AS tokens
        FROM wp_e_submissions AS s,
        	wp_e_submissions_values AS v,
            wp_e_submissions_values AS v2,
        	quest_registrants AS r
        WHERE
        	s.id = v.submission_id
            AND s.id = v2.submission_id
            AND s.form_name = 'RF23 Quest'
            AND v.key = 'email'
            AND r.email = v.value
            AND v2.key = 'field_c6508f4'
            AND v2.value IN (SELECT token from quest_registrants)
        GROUP BY r.id
        ORDER BY tokens DESC;
    ";

    /*
    SELECT
            CONCAT(quest_registrants.first_name, ' ', quest_registrants.last_name) AS name,
            COUNT(DISTINCT wp_e_submissions_values_2.value) AS key_count
        FROM {$wpdb->prefix}e_submissions
        JOIN {$wpdb->prefix}e_submissions_values
            ON {$wpdb->prefix}e_submissions.id = {$wpdb->prefix}e_submissions_values.submission_id
        JOIN {$wpdb->prefix}e_submissions_values AS wp_e_submissions_values_2
            ON {$wpdb->prefix}e_submissions.id = wp_e_submissions_values_2.submission_id
        JOIN quest_registrants
        	ON {$wpdb->prefix}e_submissions_values.value = quest_registrants.email
        JOIN quest_registrants AS quest_registrants_2
        	ON {$wpdb->prefix}e_submissions_values_2.value = quest_registrants_2.token
        WHERE
            {$wpdb->prefix}e_submissions.form_name = 'RF23 Quest'
            AND {$wpdb->prefix}e_submissions_values.key = 'email'
            AND {$wpdb->prefix}e_submissions_values_2.key = 'field_c6508f4'
        GROUP BY quest_registrants.id
        ORDER BY key_count DESC
    */

    // Execute the query
    $results = $wpdb->get_results($query);

    // Check for errors
    if ($wpdb->last_error) {
        return "<div class='error'><p>Database Query Failed: {$wpdb->last_error}</p></div>";
    }

    // Begin HTML table
    $output = '
    <style>
        .rf23-quest-table {
            border-collapse: collapse;
            width: 100%;
            margin-bottom: 20px;
        }
        .rf23-quest-table th, .rf23-quest-table td {
            border: 1px solid #ddd;
            text-align: left;
            padding: 8px;
        }
        .rf23-quest-table th {
            background-color: #3383B5;
            color: white;
        }
        .rf23-quest-table tr:nth-child(even) {
            background-color: #f2f2f2;
        }
    </style>
    <table class="rf23-quest-table">
        <thead>
            <tr>
                <th>Rank</th>
                <th>Name</th>
                <th>Tokens</th>
            </tr>
        </thead>
        <tbody>';

    // Loop through results and add rows to the table
    $i = 1;
    foreach ($results as $row) {
        $output .= sprintf(
            '<tr>
                <td>%d</td>
                <td>%s</td>
                <td>%d</td>
            </tr>',
            $i,
            esc_html($row->name),
            intval($row->tokens)
        );
        $i++;
    }

    // Close HTML table
    $output .= '</tbody></table>';

    echo $output;  // Output the table

    return ob_get_clean();  // Return the buffered output
}

add_shortcode('rf23_quest_table', 'rf23_quest_shortcode');

// Usage: [rf23_quest_table]

// **************** begin WooCommerce Email Opt-In ****************
// Add opt-in checkbox at the checkout page
add_action('woocommerce_review_order_before_submit', 'custom_opt_in_checkbox', 9);
function custom_opt_in_checkbox() {
    woocommerce_form_field('opt_in_newsletter', array(
        'type' => 'checkbox',
        'class' => array('form-row privacy'),
        'label' => __('Subscribe to our newsletter'),
        'required' => false,
    ));
}

// Validate if the checkbox is checked
add_action('woocommerce_checkout_process', 'custom_opt_in_checkbox_validation');
function custom_opt_in_checkbox_validation() {
    if (!$_POST['opt_in_newsletter']) {
        // Optional: Add any validation logic if necessary
    }
}

// Save the checkbox value in order meta
add_action('woocommerce_checkout_update_order_meta', 'custom_save_opt_in_checkbox_value');
function custom_save_opt_in_checkbox_value($order_id) {
    if (isset($_POST['opt_in_newsletter'])) {
        update_post_meta($order_id, '_opt_in_newsletter', 'yes');
    } else {
        update_post_meta($order_id, '_opt_in_newsletter', 'no');
    }
}
// **************** end WooCommerce Email Opt-In ****************

// Custom shortcode to display The Events Calendar event start date
function display_event_start_date($atts) {
    // Get attributes passed to the shortcode, specifically the event ID
    $atts = shortcode_atts(
        array(
            'id' => '', // Event ID is required
            'format' => 'F j, Y', // Date format (default: Month Day, Year)
        ),
        $atts
    );

    // Check if an event ID was provided
    if (empty($atts['id'])) {
        return 'Event ID not provided.';
    }

    // Get the event start date
    $event_start_date = get_post_meta($atts['id'], '_EventStartDate', true);

    if ($event_start_date) {
        return date_i18n($atts['format'], strtotime($event_start_date));
    } else {
        return 'Event start date not found.';
    }
}
add_shortcode('event_start_date', 'display_event_start_date');

function subcategories_shortcode($atts) {
    $atts = shortcode_atts(
        array(
            'category' => 0, // Default category ID is 0
        ),
        $atts,
        'subcategories'
    );

    $parent_category_id = intval($atts['category']);

    if ($parent_category_id <= 0) {
        return '<p>Please provide a valid category ID.</p>';
    }

    // Get subcategories
    $args = array(
        'taxonomy'   => 'category',
        'child_of'   => $parent_category_id,
        'hide_empty' => false, // Set to true to hide empty categories
        'orderby'    => 'name',
        'order'      => 'ASC',
    );

    $subcategories = get_categories($args);

    if (empty($subcategories)) {
        return '<p>No subcategories found.</p>';
    }

    // Generate the HTML output
    ob_start();
    ?>
    <div class="subcategory-list">
        <ul>
            <?php foreach ($subcategories as $subcategory) : ?>
                <li>
                    <a href="<?php echo esc_url(get_category_link($subcategory->term_id)); ?>">
                        <?php echo esc_html($subcategory->name); ?>
                    </a>
                    <span class="category-count">(<?php echo esc_html($subcategory->count); ?>)</span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <style>
        .subcategory-list {

        }
        .subcategory-list ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .subcategory-list li {
            margin-bottom: 10px;
        }
        .subcategory-list a {
            text-decoration: none;
            font-weight: bold;
            transition: color 0.3s ease;
        }
        .subcategory-list a:hover {

        }
        .category-count {
            font-size: 0.9em;
            color: #666;
            margin-left: 5px;
        }
    </style>
    <?php
    return ob_get_clean();
}

// Register the shortcode
add_shortcode('subcategories', 'subcategories_shortcode');

// Fix the admin panel Gutenberg editor font styles
function custom_editor_fonts_gutenberg() {
    add_editor_style('https://fonts.googleapis.com/css2?family=Rubik:wght@400;500;700&family=Roboto:wght@400;500;700&display=swap');
    add_editor_style('editor-fonts.css');
}
add_action('after_setup_theme', 'custom_editor_fonts_gutenberg');

/**
* Track Kit newsletter form submissions as GA4 conversion events.
* Fires 'newsletter_signup' event via gtag on Kit embedded form submit.
*/
add_action( 'wp_footer', function() {
echo '<script>
(function() {
function trackKitSubmit(event) {
var form = event.target;
if ( !form || form.tagName !== "FORM" ) return;
// Kit plugin forms have action URLs pointing to api.convertkit.com
var action = form.getAttribute("action") || "";
if ( action.indexOf("convertkit.com") === -1 && action.indexOf("kit.com") === -1 ) return;
var formId = form.getAttribute("data-sv-form") || form.getAttribute("data-form-id") || "unknown";
if ( typeof gtag === "function" ) {
gtag("event", "newsletter_signup", {
"event_category": "engagement",
"event_label": "Kit Form " + formId,
"form_id": formId
});
}
}
document.addEventListener("submit", trackKitSubmit, true);
})();
</script>';
}, 20 );

/**
 * Plugin Name: RF Tenax Caps
 * Description: Grant minimal extra caps to the tenax user for REST media uploads and page drafts.
 */

add_action('init', function () {
    // Change this if the username differs
    $user = get_user_by('login', 'tenax');
    if (!$user) return;

    // Media uploads (POST /wp/v2/media)
    $user->add_cap('upload_files');

    // Allow creating/editing Pages (POST /wp/v2/pages)
    // Minimal set for drafts:
    $user->add_cap('edit_pages');
    $user->add_cap('publish_pages'); // required by some setups even for creating drafts via REST

    // Optional (only if you want Tenax to edit pages created by other users):
    // $user->add_cap('edit_others_pages');

    // Optional (only if you want Tenax to delete pages):
    // $user->add_cap('delete_pages');
    // $user->add_cap('delete_published_pages');
});

?>
