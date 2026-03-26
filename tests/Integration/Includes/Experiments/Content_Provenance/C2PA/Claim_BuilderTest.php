<?php
/**
 * Tests for the Claim_Builder class.
 *
 * Validates C2PA 2.3 claim and assertion structure generation.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Content_Provenance\C2PA
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Experiments\Content_Provenance\C2PA;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Content_Provenance\C2PA\Claim_Builder;

/**
 * Claim_Builder test case.
 *
 * @since 0.7.0
 */
class Claim_BuilderTest extends WP_UnitTestCase {

	/**
	 * Test that build() returns claim_cbor and assertion_map.
	 */
	public function test_build_returns_expected_keys(): void {
		$builder = new Claim_Builder(
			'Hello, WordPress!',
			'c2pa.created',
			array( 'title' => 'Test Post' ),
			'urn:uuid:test-manifest'
		);

		$result = $builder->build();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'claim_cbor', $result );
		$this->assertArrayHasKey( 'assertion_map', $result );
		$this->assertNotEmpty( $result['claim_cbor'] );
		$this->assertNotEmpty( $result['assertion_map'] );
	}

	/**
	 * Test that assertion map contains required C2PA assertions.
	 */
	public function test_assertion_map_contains_required_assertions(): void {
		$builder = new Claim_Builder(
			'Content to sign.',
			'c2pa.created',
			array(),
			'urn:uuid:test'
		);

		$result = $builder->build();

		$this->assertArrayHasKey( Claim_Builder::ASSERTION_ACTIONS, $result['assertion_map'] );
		$this->assertArrayHasKey( Claim_Builder::ASSERTION_HASH_DATA, $result['assertion_map'] );
		$this->assertArrayHasKey( Claim_Builder::ASSERTION_SOFT_BINDING, $result['assertion_map'] );
	}

	/**
	 * Test that assertion CBOR values are non-empty byte strings.
	 */
	public function test_assertion_cbor_values_are_non_empty(): void {
		$builder = new Claim_Builder( 'Test.', 'c2pa.created', array(), 'urn:uuid:test' );
		$result  = $builder->build();

		foreach ( $result['assertion_map'] as $label => $cbor ) {
			$this->assertIsString( $cbor, "Assertion '{$label}' should be a string." );
			$this->assertNotEmpty( $cbor, "Assertion '{$label}' should not be empty." );
		}
	}

	/**
	 * Test that claim CBOR is non-empty.
	 */
	public function test_claim_cbor_is_non_empty(): void {
		$builder = new Claim_Builder( 'Test.', 'c2pa.created', array(), 'urn:uuid:test' );
		$result  = $builder->build();

		$this->assertNotEmpty( $result['claim_cbor'] );
	}

	/**
	 * Test that hash.data assertion contains correct SHA-256 hash of content.
	 */
	public function test_hash_data_contains_content_sha256(): void {
		$content = 'Specific test content for hashing.';
		$builder = new Claim_Builder( $content, 'c2pa.created', array(), 'urn:uuid:test' );
		$result  = $builder->build();

		$expected_hash = hash( 'sha256', $content, true );

		// The hash bytes should appear in the hash.data assertion CBOR.
		$this->assertStringContainsString(
			$expected_hash,
			$result['assertion_map'][ Claim_Builder::ASSERTION_HASH_DATA ],
			'Hash data assertion should contain SHA-256 of content.'
		);
	}

	/**
	 * Test that different content produces different hashes.
	 */
	public function test_different_content_produces_different_hashes(): void {
		$builder1 = new Claim_Builder( 'Content A', 'c2pa.created', array(), 'urn:uuid:test' );
		$builder2 = new Claim_Builder( 'Content B', 'c2pa.created', array(), 'urn:uuid:test' );

		$result1 = $builder1->build();
		$result2 = $builder2->build();

		$this->assertNotSame(
			$result1['assertion_map'][ Claim_Builder::ASSERTION_HASH_DATA ],
			$result2['assertion_map'][ Claim_Builder::ASSERTION_HASH_DATA ],
			'Different content should produce different hash data assertions.'
		);
	}

	/**
	 * Test that claim CBOR starts with CBOR map marker.
	 */
	public function test_claim_cbor_is_cbor_map(): void {
		$builder = new Claim_Builder( 'Test.', 'c2pa.created', array(), 'urn:uuid:test' );
		$result  = $builder->build();

		$first_byte = ord( $result['claim_cbor'][0] );
		// CBOR map: major type 5, initial byte 0xa0-0xbf.
		$this->assertGreaterThanOrEqual( 0xa0, $first_byte, 'Claim should start with CBOR map header.' );
		$this->assertLessThanOrEqual( 0xbf, $first_byte, 'Claim should start with CBOR map header.' );
	}

	/**
	 * Test that the claim contains the manifest label in signature reference.
	 */
	public function test_claim_contains_manifest_label(): void {
		$label   = 'urn:uuid:my-special-manifest';
		$builder = new Claim_Builder( 'Test.', 'c2pa.created', array(), $label );
		$result  = $builder->build();

		$this->assertStringContainsString(
			$label,
			$result['claim_cbor'],
			'Claim should contain manifest label in signature reference.'
		);
	}

	/**
	 * Test that claim contains claimGenerator string.
	 */
	public function test_claim_contains_generator(): void {
		$builder = new Claim_Builder( 'Test.', 'c2pa.created', array(), 'urn:uuid:test' );
		$result  = $builder->build();

		$this->assertStringContainsString(
			'WordPress/AI',
			$result['claim_cbor'],
			'Claim should contain WordPress/AI in claim generator.'
		);
	}

	/**
	 * Test that actions assertion contains the specified action type.
	 */
	public function test_actions_assertion_contains_action_type(): void {
		$builder = new Claim_Builder( 'Test.', 'c2pa.edited', array(), 'urn:uuid:test' );
		$result  = $builder->build();

		$this->assertStringContainsString(
			'c2pa.edited',
			$result['assertion_map'][ Claim_Builder::ASSERTION_ACTIONS ],
			'Actions assertion should contain the specified action.'
		);
	}

	/**
	 * Test that soft_binding assertion references text embedding method.
	 */
	public function test_soft_binding_references_text_embedding(): void {
		$builder = new Claim_Builder( 'Test.', 'c2pa.created', array(), 'urn:uuid:test' );
		$result  = $builder->build();

		$this->assertStringContainsString(
			'c2pa.text',
			$result['assertion_map'][ Claim_Builder::ASSERTION_SOFT_BINDING ],
			'Soft binding should reference text embedding algorithm.'
		);
	}

	/**
	 * Test that set_digital_source_type changes the action assertion.
	 */
	public function test_set_digital_source_type(): void {
		$builder = new Claim_Builder( 'Test.', 'c2pa.created', array(), 'urn:uuid:test' );
		$builder->set_digital_source_type( 'http://cv.iptc.org/newscodes/digitalsourcetype/humanEdited' );
		$result = $builder->build();

		$this->assertStringContainsString(
			'humanEdited',
			$result['assertion_map'][ Claim_Builder::ASSERTION_ACTIONS ],
			'Actions assertion should contain the custom digital source type.'
		);
	}
}
