<?php
/**
 * C2PA manifest builder.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Provenance;

use WordPress\AI\Experiments\Content_Provenance\Signing\Signing_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and verifies C2PA content manifests.
 *
 * Delegates to a Signing_Interface backend which produces spec-compliant
 * JUMBF manifest stores with COSE_Sign1 signatures and ES256 signing.
 * Verification extracts the JUMBF from Unicode variation selectors and
 * validates the content hash binding.
 *
 * @since 0.5.0
 */
class C2PA_Manifest_Builder {

	/**
	 * C2PA text magic byte sequence (ASCII "C2PATXT\0").
	 *
	 * Identifies the payload as a C2PA text manifest container per spec Section A.7.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const MAGIC = "\x43\x32\x50\x41\x54\x58\x54\x00";

	/**
	 * Manifest format version.
	 *
	 * @since 0.5.0
	 * @var int
	 */
	public const VERSION = 1;

	/**
	 * Build a C2PA manifest for the given content.
	 *
	 * Delegates to the signer backend, which produces JUMBF manifest store
	 * bytes containing CBOR-encoded claims and a COSE_Sign1 signature.
	 *
	 * @since 0.5.0
	 * @since 0.7.0 Returns JUMBF binary instead of JSON manifest.
	 *
	 * @param string                                                                     $content           Plain text content.
	 * @param string                                                                     $action            'c2pa.created' or 'c2pa.edited'.
	 * @param string|null                                                                $previous_manifest Previous manifest for ingredient chain (unused in binary format).
	 * @param array<string, mixed>                                                       $metadata          Post metadata: title, url, author, post_id.
	 * @param \WordPress\AI\Experiments\Content_Provenance\Signing\Signing_Interface      $signer            Signing backend to use.
	 * @return array{manifest: string, content_hash: string}|\WP_Error Signed manifest bytes and hash, or error.
	 */
	public static function build(
		string $content,
		string $action,
		?string $previous_manifest,
		array $metadata,
		Signing_Interface $signer
	) {
		$content_hash = hash( 'sha256', $content );

		$metadata['action'] = $action;

		$result = $signer->sign( $content, $metadata );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'manifest'     => $result,
			'content_hash' => $content_hash,
		);
	}

	/**
	 * Extract and verify C2PA provenance from text.
	 *
	 * Extracts embedded variation-selector data, parses the manifest,
	 * and validates the SHA-256 content hash against the stripped plain text.
	 *
	 * Supports both the new JUMBF binary format and legacy JSON format
	 * for backwards compatibility with previously signed content.
	 *
	 * @since 0.5.0
	 * @since 0.7.0 Added JUMBF binary format support with legacy JSON fallback.
	 *
	 * @param string $text Text that may contain embedded Unicode provenance.
	 * @return array{verified: bool, status: string, manifest: array<string, mixed>|null, error: string|null}
	 */
	public static function extract_and_verify( string $text ): array {
		$embedded = Unicode_Embedder::extract( $text );

		if ( null === $embedded ) {
			return array(
				'verified' => false,
				'status'   => 'unsigned',
				'manifest' => null,
				'error'    => null,
			);
		}

		// Detect format: legacy JSON starts with '{', JUMBF is binary.
		if ( strlen( $embedded ) > 0 && '{' === $embedded[0] ) {
			return self::verify_legacy_json( $text, $embedded );
		}

		return self::verify_jumbf( $text, $embedded );
	}

	/**
	 * Verifies legacy JSON manifest format (pre-0.7.0).
	 *
	 * @since 0.7.0
	 *
	 * @param string $text     Full text with embedded manifest.
	 * @param string $json_str Extracted JSON manifest string.
	 * @return array{verified: bool, status: string, manifest: array<string, mixed>|null, error: string|null}
	 */
	private static function verify_legacy_json( string $text, string $json_str ): array {
		$manifest = json_decode( $json_str, true );

		if ( ! is_array( $manifest ) ) {
			return array(
				'verified' => false,
				'status'   => 'invalid',
				'manifest' => null,
				'error'    => 'Could not parse legacy manifest',
			);
		}

		$plain_text   = Unicode_Embedder::strip( $text );
		$content_hash = hash( 'sha256', $plain_text );
		$stored_hash  = $manifest['claims']['assertions']['c2pa.hash.data.v1']['hash'] ?? null;

		if ( $stored_hash !== $content_hash ) {
			return array(
				'verified' => false,
				'status'   => 'tampered',
				'manifest' => $manifest,
				'error'    => 'Content hash mismatch',
			);
		}

		return array(
			'verified' => true,
			'status'   => 'legacy_verified',
			'manifest' => $manifest,
			'error'    => null,
		);
	}

	/**
	 * Verifies JUMBF binary manifest format (0.7.0+).
	 *
	 * Performs content hash verification by locating the hash.data assertion
	 * within the JUMBF structure. Full COSE_Sign1 signature verification
	 * is delegated to the verify ability class.
	 *
	 * @since 0.7.0
	 *
	 * @param string $text       Full text with embedded manifest.
	 * @param string $jumbf_bytes Extracted JUMBF manifest store bytes.
	 * @return array{verified: bool, status: string, manifest: array<string, mixed>|null, error: string|null}
	 */
	private static function verify_jumbf( string $text, string $jumbf_bytes ): array {
		// Validate minimum JUMBF structure: must start with a box header.
		if ( strlen( $jumbf_bytes ) < 16 ) {
			return array(
				'verified' => false,
				'status'   => 'invalid',
				'manifest' => null,
				'error'    => 'Manifest too short for JUMBF',
			);
		}

		// Verify the outer box type is 'jumb' (JUMBF superbox).
		$box_type = substr( $jumbf_bytes, 4, 4 );
		if ( 'jumb' !== $box_type ) {
			return array(
				'verified' => false,
				'status'   => 'invalid',
				'manifest' => null,
				'error'    => 'Invalid JUMBF structure',
			);
		}

		// Content hash verification: compute hash of stripped text.
		$plain_text   = Unicode_Embedder::strip( $text );
		$content_hash = hash( 'sha256', $plain_text, true );

		// Search for the content hash in the JUMBF bytes.
		// The hash appears in the c2pa.hash.data assertion as a CBOR byte string.
		if ( false === strpos( $jumbf_bytes, $content_hash ) ) {
			return array(
				'verified' => false,
				'status'   => 'tampered',
				'manifest' => array( 'format' => 'jumbf' ),
				'error'    => 'Content hash mismatch',
			);
		}

		return array(
			'verified' => true,
			'status'   => 'verified',
			'manifest' => array( 'format' => 'jumbf' ),
			'error'    => null,
		);
	}
}
