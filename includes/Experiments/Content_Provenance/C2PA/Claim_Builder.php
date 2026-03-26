<?php
/**
 * C2PA claim and assertion builder.
 *
 * Translates WordPress content metadata into C2PA 2.3 claim and assertion
 * structures, serialized as CBOR. Produces the semantic data layer that
 * gets wrapped in COSE_Sign1 and JUMBF containers.
 *
 * @package WordPress\AI\Experiments\Content_Provenance\C2PA
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Provenance\C2PA;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * C2PA 2.3 claim and assertion builder.
 *
 * @since 0.7.0
 */
final class Claim_Builder {

	/**
	 * C2PA assertion label for actions.
	 *
	 * @var string
	 */
	public const ASSERTION_ACTIONS = 'c2pa.actions.v2';

	/**
	 * C2PA assertion label for content hash binding.
	 *
	 * @var string
	 */
	public const ASSERTION_HASH_DATA = 'c2pa.hash.data';

	/**
	 * C2PA assertion label for soft binding (text embedding).
	 *
	 * @var string
	 */
	public const ASSERTION_SOFT_BINDING = 'c2pa.soft_binding';

	/**
	 * C2PA assertion label for ingredient reference.
	 *
	 * @var string
	 */
	public const ASSERTION_INGREDIENT = 'c2pa.ingredient.v2';

	/**
	 * Content text to sign.
	 *
	 * @var string
	 */
	private string $content;

	/**
	 * C2PA action type.
	 *
	 * @var string
	 */
	private string $action;

	/**
	 * Post metadata.
	 *
	 * @var array<string, mixed>
	 */
	private array $metadata;

	/**
	 * Manifest label for JUMBF self-references.
	 *
	 * @var string
	 */
	private string $manifest_label;

	/**
	 * Previous manifest bytes for ingredient chain, or null.
	 *
	 * @var string|null
	 */
	private ?string $previous_manifest;

	/**
	 * IPTC digital source type URI.
	 *
	 * @var string
	 */
	private string $digital_source_type = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';

	/**
	 * Constructor.
	 *
	 * @since 0.7.0
	 *
	 * @param string               $content            Content text to sign.
	 * @param string               $action             C2PA action type (e.g. "c2pa.created", "c2pa.edited").
	 * @param array<string, mixed> $metadata           Post metadata (title, post_id, etc.).
	 * @param string               $manifest_label     Manifest label for JUMBF self-references.
	 * @param string|null          $previous_manifest  Previous manifest bytes for ingredient chain.
	 */
	public function __construct( string $content, string $action, array $metadata, string $manifest_label, ?string $previous_manifest = null ) {
		$this->content           = $content;
		$this->action            = $action;
		$this->metadata          = $metadata;
		$this->manifest_label    = $manifest_label;
		$this->previous_manifest = $previous_manifest;
	}

	/**
	 * Sets the IPTC digital source type for the actions assertion.
	 *
	 * @since 0.7.0
	 *
	 * @param string $type IPTC digital source type URI.
	 */
	public function set_digital_source_type( string $type ): void {
		$this->digital_source_type = $type;
	}

	/**
	 * Builds the complete claim and assertion set.
	 *
	 * Returns an associative array with:
	 * - "claim_cbor": CBOR-encoded claim map
	 * - "assertion_map": array of assertion label => CBOR-encoded assertion bytes
	 *
	 * @since 0.7.0
	 *
	 * @return array{claim_cbor: string, assertion_map: array<string, string>}
	 */
	public function build(): array {
		$assertion_map = $this->build_assertions();
		$claim_cbor    = $this->build_claim( $assertion_map );

		return array(
			'claim_cbor'    => $claim_cbor,
			'assertion_map' => $assertion_map,
		);
	}

	/**
	 * Builds all C2PA assertions as CBOR.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, string> Assertion label => CBOR-encoded bytes.
	 */
	private function build_assertions(): array {
		$assertions = array(
			self::ASSERTION_ACTIONS      => $this->build_actions_assertion(),
			self::ASSERTION_HASH_DATA    => $this->build_hash_data_assertion(),
			self::ASSERTION_SOFT_BINDING => $this->build_soft_binding_assertion(),
		);

		if ( null !== $this->previous_manifest && '' !== $this->previous_manifest ) {
			$assertions[ self::ASSERTION_INGREDIENT ] = $this->build_ingredient_assertion();
		}

		return $assertions;
	}

	/**
	 * Builds the c2pa.actions.v2 assertion.
	 *
	 * @since 0.7.0
	 *
	 * @return string CBOR-encoded assertion.
	 */
	private function build_actions_assertion(): string {
		$action_map = array(
			'action'            => $this->action,
			'digitalSourceType' => $this->digital_source_type,
		);

		$assertion = array(
			'actions' => array( $action_map ),
		);

		return CBOR_Encoder::encode( $assertion );
	}

	/**
	 * Builds the c2pa.hash.data assertion with SHA-256 content hash.
	 *
	 * @since 0.7.0
	 *
	 * @return string CBOR-encoded assertion.
	 */
	private function build_hash_data_assertion(): string {
		$hash_bytes = hash( 'sha256', $this->content, true );

		$assertion = array(
			'name'      => 'sha256',
			'hash'      => CBOR_Encoder::encode_byte_string( $hash_bytes ),
			'pad_start' => 0,
		);

		return CBOR_Encoder::encode( $assertion );
	}

	/**
	 * Builds the c2pa.soft_binding assertion for text embedding (Section A.7).
	 *
	 * @since 0.7.0
	 *
	 * @return string CBOR-encoded assertion.
	 */
	private function build_soft_binding_assertion(): string {
		$assertion = array(
			'alg'             => 'c2pa.text.vs16',
			'document_length' => mb_strlen( $this->content, 'UTF-8' ),
		);

		return CBOR_Encoder::encode( $assertion );
	}

	/**
	 * Builds the c2pa.ingredient.v2 assertion referencing a previous manifest.
	 *
	 * Per C2PA 2.3 §8.3, the ingredient assertion includes the hash of the
	 * previous manifest to form a provenance chain. Verifiers can trace edits
	 * back through the chain by following ingredient references.
	 *
	 * @since 0.7.0
	 *
	 * @return string CBOR-encoded assertion.
	 */
	private function build_ingredient_assertion(): string {
		/** @var string $previous_manifest — guaranteed non-null by caller. */
		$previous_manifest = (string) $this->previous_manifest;

		$assertion = array(
			'relationship' => 'parentOf',
			'title'        => 'Previous version',
			'format'       => 'application/c2pa',
			'hash'         => array(
				'name'  => 'sha256',
				'value' => CBOR_Encoder::encode_byte_string( hash( 'sha256', $previous_manifest, true ) ),
			),
		);

		return CBOR_Encoder::encode( $assertion );
	}

	/**
	 * Builds the C2PA claim structure referencing assertion hashes.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string, string> $assertion_map Assertion label => CBOR bytes.
	 * @return string CBOR-encoded claim map.
	 */
	private function build_claim( array $assertion_map ): string {
		$assertion_refs = array();
		foreach ( $assertion_map as $label => $cbor_bytes ) {
			$assertion_refs[] = array(
				'url'  => 'self#jumbf=' . $this->manifest_label . '/c2pa.assertions/' . $label,
				'hash' => array(
					'name'  => 'sha256',
					'value' => CBOR_Encoder::encode_byte_string( hash( 'sha256', $cbor_bytes, true ) ),
				),
			);
		}

		$title = isset( $this->metadata['title'] ) ? (string) $this->metadata['title'] : '';

		$claim = array(
			'dc:title'           => $title,
			'dc:format'          => 'text/plain',
			'instanceID'         => $this->generate_instance_id(),
			'claimGenerator'     => 'WordPress/AI c2pa-php/0.1.0',
			'claimGeneratorInfo' => array(
				array(
					'name'    => 'WordPress AI Plugin',
					'version' => '0.7.0',
				),
			),
			'signature'          => 'self#jumbf=' . $this->manifest_label . '/c2pa.signature',
			'assertions'         => $assertion_refs,
		);

		// Add ingredients array when there is a previous manifest in the chain.
		if ( null !== $this->previous_manifest && '' !== $this->previous_manifest ) {
			$ingredient_url       = 'self#jumbf=' . $this->manifest_label . '/c2pa.assertions/' . self::ASSERTION_INGREDIENT;
			$ingredient_cbor      = $assertion_map[ self::ASSERTION_INGREDIENT ] ?? '';
			$claim['ingredients'] = array(
				array(
					'url'  => $ingredient_url,
					'hash' => array(
						'name'  => 'sha256',
						'value' => CBOR_Encoder::encode_byte_string( hash( 'sha256', $ingredient_cbor, true ) ),
					),
				),
			);
		}

		return CBOR_Encoder::encode( $claim );
	}

	/**
	 * Generates a unique XMP-format instance ID.
	 *
	 * @since 0.7.0
	 *
	 * @return string Instance ID in "xmp:iid:<UUID>" format.
	 */
	private function generate_instance_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return 'xmp:iid:' . wp_generate_uuid4();
		}

		// Fallback for non-WP contexts.
		$bytes = random_bytes( 16 );
		$hex   = bin2hex( $bytes );

		return 'xmp:iid:' . sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 4 ),
			substr( $hex, 16, 4 ),
			substr( $hex, 20, 12 )
		);
	}
}
