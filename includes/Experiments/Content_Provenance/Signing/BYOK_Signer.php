<?php
/**
 * Bring-Your-Own-Key (BYOK) signing backend.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Provenance\Signing;

use WordPress\AI\Experiments\Content_Provenance\C2PA\COSE_Sign1_Builder;
use WordPress\AI\Experiments\Content_Provenance\C2PA\Claim_Builder;
use WordPress\AI\Experiments\Content_Provenance\C2PA\JUMBF_Writer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signs C2PA manifests using a publisher-supplied EC private key and certificate.
 *
 * BYOK allows publishers to use their own CA-issued certificate for C2PA signing.
 * The private key and certificate are loaded from filesystem paths configured in
 * the experiment settings. This tier offers the highest trust level since the
 * certificate can be issued by a C2PA trust list CA (SSL.com, DigiCert, etc.).
 *
 * @since 0.5.0
 */
class BYOK_Signer implements Signing_Interface {

	/**
	 * Filesystem path to the PEM-encoded private key.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private string $key_path;

	/**
	 * Filesystem path to the PEM-encoded X.509 certificate (chain).
	 *
	 * @since 0.7.0
	 * @var string
	 */
	private string $cert_path;

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 * @since 0.7.0 Now accepts separate key and certificate paths.
	 *
	 * @param string $key_path  Filesystem path to a PEM-encoded private key file.
	 * @param string $cert_path Filesystem path to a PEM-encoded certificate file.
	 */
	public function __construct( string $key_path, string $cert_path = '' ) {
		$this->key_path  = $key_path;
		$this->cert_path = $cert_path;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Loads the publisher's private key and certificate, builds a spec-compliant
	 * C2PA JUMBF manifest store with COSE_Sign1 signature.
	 *
	 * @since 0.5.0
	 * @since 0.7.0 Returns JUMBF binary instead of JSON.
	 *
	 * @param string               $content  Plain text content to sign.
	 * @param array<string, mixed> $metadata Post metadata (title, post_id, etc.).
	 * @return string|\WP_Error JUMBF manifest store bytes or WP_Error on failure.
	 */
	public function sign( string $content, array $metadata ) {
		if ( empty( $this->key_path ) ) {
			return new \WP_Error(
				'c2pa_byok_no_cert',
				esc_html__( 'BYOK private key path is not configured.', 'ai' )
			);
		}

		if ( ! is_readable( $this->key_path ) ) {
			return new \WP_Error(
				'c2pa_byok_cert_unreadable',
				esc_html__( 'BYOK private key file is not readable. Check the path and permissions.', 'ai' )
			);
		}

		$private_key_pem = file_get_contents( $this->key_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Reading local PEM key file.

		if ( false === $private_key_pem ) {
			return new \WP_Error(
				'c2pa_byok_key_read_failed',
				esc_html__( 'Failed to read BYOK private key file.', 'ai' )
			);
		}

		// Load and validate the private key.
		$key = openssl_pkey_get_private( $private_key_pem );

		if ( false === $key ) {
			return new \WP_Error(
				'c2pa_byok_key_load_failed',
				esc_html__( 'Failed to load BYOK private key. Ensure the file is a valid PEM-encoded EC private key.', 'ai' )
			);
		}

		// Load certificate.
		$certificate_der = $this->load_certificate_der();

		if ( is_wp_error( $certificate_der ) ) {
			return $certificate_der;
		}

		$manifest_label = 'urn:uuid:' . wp_generate_uuid4();

		// Step 1: Build assertions and claim.
		$action        = isset( $metadata['action'] ) ? (string) $metadata['action'] : 'c2pa.created';
		$claim_builder = new Claim_Builder( $content, $action, $metadata, $manifest_label );
		$claim_result  = $claim_builder->build();

		// Step 2: Build COSE_Sign1 signature.
		try {
			$cose_builder = new COSE_Sign1_Builder(
				$private_key_pem,
				$certificate_der,
				$claim_result['claim_cbor']
			);
			$cose_sign1   = $cose_builder->build();
		} catch ( \RuntimeException $e ) {
			return new \WP_Error(
				'c2pa_byok_sign_failed',
				sprintf(
					/* translators: %s: Error message from the signing operation. */
					esc_html__( 'BYOK C2PA signing failed: %s', 'ai' ),
					esc_html( $e->getMessage() )
				)
			);
		}

		// Step 3: Assemble JUMBF manifest store.
		return JUMBF_Writer::build_manifest_store(
			$claim_result['claim_cbor'],
			$claim_result['assertion_map'],
			$cose_sign1,
			$manifest_label
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 *
	 * @return string Always 'byok'.
	 */
	public function get_tier(): string {
		return 'byok';
	}

	/**
	 * Loads the certificate from the configured path and returns DER bytes.
	 *
	 * @since 0.7.0
	 *
	 * @return string|\WP_Error DER certificate bytes or WP_Error.
	 */
	private function load_certificate_der() {
		if ( empty( $this->cert_path ) ) {
			return new \WP_Error(
				'c2pa_byok_no_cert_file',
				esc_html__( 'BYOK certificate path is not configured.', 'ai' )
			);
		}

		if ( ! is_readable( $this->cert_path ) ) {
			return new \WP_Error(
				'c2pa_byok_cert_file_unreadable',
				esc_html__( 'BYOK certificate file is not readable.', 'ai' )
			);
		}

		$cert_pem = file_get_contents( $this->cert_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Reading local PEM certificate file.

		if ( false === $cert_pem ) {
			return new \WP_Error(
				'c2pa_byok_cert_read_failed',
				esc_html__( 'Failed to read BYOK certificate file.', 'ai' )
			);
		}

		$der = Local_Signer::pem_to_der( $cert_pem );

		if ( '' === $der ) {
			return new \WP_Error(
				'c2pa_byok_cert_invalid',
				esc_html__( 'BYOK certificate is not a valid PEM-encoded X.509 certificate.', 'ai' )
			);
		}

		return $der;
	}
}
