<?php
/**
 * Test Image Detection
 * 
 * Instructions:
 * 1. Upload a new image to an ACF Gutenberg block
 * 2. Get the post ID
 * 3. Visit: your-site.com/wp-admin/admin-ajax.php?action=stls_test_images&post_id=YOUR_POST_ID
 * 4. You'll see what images are being detected
 */

add_action( 'wp_ajax_stls_test_images', 'stls_test_image_detection' );

function stls_test_image_detection() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}
	
	$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0;
	
	if ( ! $post_id ) {
		wp_die( 'Please provide post_id parameter' );
	}
	
	echo '<h1>Image Detection Test for Post ID: ' . $post_id . '</h1>';
	
	// Get all post meta
	$all_meta = get_post_meta( $post_id );
	
	echo '<h2>All Post Meta:</h2>';
	echo '<pre>';
	print_r( $all_meta );
	echo '</pre>';
	
	// Find images
	echo '<h2>Detected Images:</h2>';
	$image_count = 0;
	
	foreach ( $all_meta as $meta_key => $meta_values ) {
		foreach ( $meta_values as $meta_value ) {
			$unserialized = maybe_unserialize( $meta_value );
			
			// Check if numeric (attachment ID)
			if ( is_numeric( $unserialized ) ) {
				$attachment = get_post( $unserialized );
				if ( $attachment && $attachment->post_type === 'attachment' ) {
					$mime_type = get_post_mime_type( $attachment->ID );
					if ( strpos( $mime_type, 'image/' ) === 0 ) {
						$image_count++;
						$image_url = wp_get_attachment_url( $attachment->ID );
						echo '<div style="border: 1px solid #ccc; padding: 10px; margin: 10px 0;">';
						echo '<strong>Meta Key:</strong> ' . esc_html( $meta_key ) . '<br>';
						echo '<strong>Attachment ID:</strong> ' . $attachment->ID . '<br>';
						echo '<strong>Image URL:</strong> ' . esc_html( $image_url ) . '<br>';
						echo '<strong>Title:</strong> ' . esc_html( $attachment->post_title ) . '<br>';
						echo '<img src="' . esc_url( $image_url ) . '" style="max-width: 200px; height: auto;" />';
						echo '</div>';
					}
				}
			}
			
			// Check if ACF array format
			if ( is_array( $unserialized ) && isset( $unserialized['ID'] ) ) {
				$attachment_id = $unserialized['ID'];
				$attachment = get_post( $attachment_id );
				if ( $attachment && $attachment->post_type === 'attachment' ) {
					$mime_type = get_post_mime_type( $attachment->ID );
					if ( strpos( $mime_type, 'image/' ) === 0 ) {
						$image_count++;
						$image_url = isset( $unserialized['url'] ) ? $unserialized['url'] : wp_get_attachment_url( $attachment_id );
						echo '<div style="border: 1px solid #ccc; padding: 10px; margin: 10px 0; background: #f0f0f0;">';
						echo '<strong>Meta Key:</strong> ' . esc_html( $meta_key ) . '<br>';
						echo '<strong>Attachment ID:</strong> ' . $attachment_id . '<br>';
						echo '<strong>Image URL:</strong> ' . esc_html( $image_url ) . '<br>';
						echo '<strong>ACF Array Data:</strong><br>';
						echo '<pre>' . print_r( $unserialized, true ) . '</pre>';
						echo '<img src="' . esc_url( $image_url ) . '" style="max-width: 200px; height: auto;" />';
						echo '</div>';
					}
				}
			}
		}
	}
	
	if ( $image_count === 0 ) {
		echo '<p style="color: red; font-weight: bold;">❌ NO IMAGES DETECTED!</p>';
		echo '<p>This means images aren\'t being stored in post meta as expected.</p>';
		echo '<p>Check:</p>';
		echo '<ul>';
		echo '<li>Are you using ACF image fields?</li>';
		echo '<li>Is the image saved properly in the media library?</li>';
		echo '<li>Did you save the post after uploading the image?</li>';
		echo '</ul>';
	} else {
		echo '<p style="color: green; font-weight: bold;">✅ Found ' . $image_count . ' image(s)!</p>';
	}
	
	wp_die();
}


