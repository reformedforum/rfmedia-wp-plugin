<?php
// Podcast Media meta box — edits the postmeta the player/feed read.
// (Replaces the old disabled box that wrote raw SQL into media.assets.)

add_action( 'add_meta_boxes', function () {
	add_meta_box( 'rfmedia_box', 'Podcast Media (Captivate)', 'rfmedia_box_html', 'podcast', 'normal', 'high' );
} );

function rfmedia_box_html( $post ) {
	wp_nonce_field( 'rfmedia_box', 'rfmedia_nonce' );
	$fields = array(
		'rf_podcast_audio_url' => 'Audio URL (Captivate / host)',
		'rf_youtube_url'       => 'YouTube URL',
		'rf_vimeo_url'         => 'Vimeo URL',
	);
	foreach ( $fields as $key => $label ) {
		$val = esc_attr( get_post_meta( $post->ID, $key, true ) );
		echo "<p><label for='" . esc_attr( $key ) . "'><strong>" . esc_html( $label ) . "</strong></label><br>";
		echo "<input type='url' id='" . esc_attr( $key ) . "' name='" . esc_attr( $key ) . "' value='$val' style='width:100%' /></p>";
	}
}

add_action( 'save_post_podcast', function ( $post_id ) {
	if ( ! isset( $_POST['rfmedia_nonce'] ) || ! wp_verify_nonce( $_POST['rfmedia_nonce'], 'rfmedia_box' ) ) { return; }
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
	if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
	foreach ( array( 'rf_podcast_audio_url', 'rf_youtube_url', 'rf_vimeo_url' ) as $key ) {
		if ( isset( $_POST[ $key ] ) ) {
			update_post_meta( $post_id, $key, esc_url_raw( trim( $_POST[ $key ] ) ) );
		}
	}
} );
