<?php
/*
Plugin Name: WP_Code2Image
Description: Creates images from code block
Version: 0.1
Author: Matej Gačnik
*/

add_action( 'transition_post_status', 'WPCode2Image_post_transition_hook', 10, 3 );

function WPCode2Image_post_transition_hook( $new_status, $old_status, $post ) {

	if ('publish' === $new_status) {
		/* Gutenberg's REST API saves featured images after transition_post_status is run, so we need to run it after. */
		if(defined('REST_REQUEST') && REST_REQUEST ){
			add_action( 'rest_after_insert_post', 'WPCode2Image_rest_workaround', 10, 3 );
		}
		else
		{
			WPCode2Image_parse_enlighter_code_block($post);
		}
	}
}

function WPCode2Image_rest_workaround($post,$request,$is_update)
{
	WPCode2Image_parse_enlighter_code_block($post);
}

function WPCode2Image_parse_enlighter_code_block( $post) {
	if(has_post_thumbnail($post))
		return;

	$doc = new DOMDocument();

	if ( $doc->loadHTML("<html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\"></head><body>".$post->post_content . "</body></html>" ) === false ) {
		return;
	}

	$xpath = new DOMXPath( $doc );
	$nlist = $xpath->query( "//pre[@class='EnlighterJSRAW']" );

	if ( $nlist !== false and ! empty( $nlist ) ) {

		foreach ( $nlist as $item ) {

			$img = WPCode2Image_get_code_image( $item->getAttribute( 'data-enlighter-language' ), $item->textContent );
			if ( $img !== false ) {
				WPCode2Image_insert_image( $img,$post );
			}
		}
	}

}

function WPCode2Image_get_code_image( $lang, $content ) {
	//pripravimo argumente za spletno zahtevo; zakodiramo jih v JSON obliko

	$body = json_encode( array(
		'code'     => $content,
		'language' => $lang,
		'theme'    => 'solarized'
	) );

	$headers = array(
		"Accept"       => "*/*",
		"Content-Type" => "application/json;charset=UTF-8",
	);

	$data = array(
		'headers' => $headers,
		'body'    => $body
	);
	//končno pošljemo vsebino kot zahtevo na spletno storitev instaco.de
	$result = wp_remote_post( 'http://instaco.de/api/highlight', $data );

	if ( ! is_wp_error( $result ) ) {
		return $result['body'];
	}
	else
	{
		error_log( "Error connecting to Instaco.de service!; by WPCode2Image", 0 );
	}

	return false;
}

function WPCode2Image_insert_image( $base64image,$post ) {
	//Instaco.de vrača sliko v base64 kodiranju, zato jo prej dekodiramo
	$img = str_replace( ' ', '+', $base64image );
	$img = base64_decode( $img );

	$img = wp_upload_bits( 'WPCode2Image.png', null, $img );

	/* nato pa le še shranimo dobljeno sliko. Zgledujemo se po Wordpressovemu primeru  na https://codex.wordpress.org/Function_Reference/wp_insert_attachment#Example
	*/

	if ( $img['error'] === false ) {

		$filename       = $img['file'];

		$attachment = array(
			'post_mime_type' => $img['type'],
			'post_title'     => "Created by WPCode2Image",
			'post_content'   => '',
			'post_status'    => 'inherit'
		);

		$attach_id = wp_insert_attachment( $attachment, $filename, $post->ID );

		if (is_wp_error( $attach_id ) || $attach_id === 0 ) {
			error_log( "Couldn't save image as attachment!; by WPCode2Image", 0 );

			return;
		}

		require_once( ABSPATH . 'wp-admin/includes/image.php' );

		$attach_data = wp_generate_attachment_metadata( $attach_id, $filename );
		if ( ! wp_update_attachment_metadata( $attach_id, $attach_data ) ) {
			error_log( "Wrong image attachment!; by WPCode2Image", 0 );
		}
	} else {
		error_log( "Couldn't save image! Error:" . $img['error'] . "; by WPCode2Image", 0 );
	}

}