<?php
/**
 * Force re-sign a post with the latest C2PA code.
 * Usage: wp eval-file wp-content/mu-plugins/force-resign.php <post_id>
 */

$post_id = isset( $args[0] ) ? (int) $args[0] : 31;
$post    = get_post( $post_id );

if ( ! $post ) {
	WP_CLI::error( "Post {$post_id} not found." );
}

// Clear existing signature so hooks don't skip.
delete_post_meta( $post_id, '_c2pa_status' );
delete_post_meta( $post_id, '_c2pa_content_hash' );
delete_post_meta( $post_id, '_c2pa_embedded_content' );
delete_post_meta( $post_id, '_c2pa_manifest' );

// Get the Content_Provenance experiment instance.
$features = apply_filters( 'wpai_default_feature_classes', array() );
$loader   = new \WordPress\AI\Features\Loader();

// Find and instantiate the Content_Provenance feature.
$provenance = null;
foreach ( $features as $class ) {
	if ( str_contains( $class, 'Content_Provenance' ) ) {
		$provenance = new $class();
		break;
	}
}

if ( ! $provenance ) {
	WP_CLI::error( 'Content_Provenance class not found.' );
}

$result = $provenance->sign_post( $post_id, $post, 'c2pa.edited' );

if ( $result ) {
	$status = get_post_meta( $post_id, '_c2pa_status', true );
	$signed = get_post_meta( $post_id, '_c2pa_signed_at', true );
	WP_CLI::success( "Post {$post_id} re-signed. Status: {$status}, Time: {$signed}" );
} else {
	WP_CLI::error( "Signing failed for post {$post_id}." );
}
