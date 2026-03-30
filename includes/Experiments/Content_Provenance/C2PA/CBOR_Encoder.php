<?php
/**
 * Minimal deterministic CBOR encoder for COSE structures.
 *
 * Implements the subset of RFC 8949 required for C2PA COSE_Sign1 signing:
 * major types 0-6 and simple values (true, false, null). Uses CTAP2
 * canonical CBOR (RFC 8949 Section 4.2.1) for deterministic map ordering.
 *
 * @package WordPress\AI\Experiments\Content_Provenance\C2PA
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Provenance\C2PA;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Minimal deterministic CBOR encoder.
 *
 * @since x.x.x
 */
final class CBOR_Encoder {

	/**
	 * Encodes a PHP value as CBOR.
	 *
	 * Dispatches on PHP type: int, string (as text), array, bool, null.
	 * For byte strings (major type 2), use encode_byte_string() explicitly.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value The value to encode.
	 * @return string CBOR-encoded bytes.
	 */
	public static function encode( $value ): string {
		if ( is_null( $value ) ) {
			return "\xf6";
		}

		if ( is_bool( $value ) ) {
			return $value ? "\xf5" : "\xf4";
		}

		if ( is_int( $value ) ) {
			return $value >= 0
				? self::encode_unsigned_int( $value )
				: self::encode_negative_int( $value );
		}

		if ( is_string( $value ) ) {
			return self::encode_text_string( $value );
		}

		if ( is_array( $value ) ) {
			return self::is_sequential_array( $value )
				? self::encode_array( $value )
				: self::encode_map( $value );
		}

		return '';
	}

	/**
	 * Encodes a byte string (CBOR major type 2).
	 *
	 * PHP has no native byte string type, so callers must use this method
	 * explicitly when a CBOR byte string (not text string) is needed.
	 *
	 * @since x.x.x
	 *
	 * @param string $bytes Raw bytes.
	 * @return string CBOR-encoded byte string.
	 */
	public static function encode_byte_string( string $bytes ): string {
		return self::encode_head( 2, strlen( $bytes ) ) . $bytes;
	}

	/**
	 * Encodes a tagged value (CBOR major type 6).
	 *
	 * @since x.x.x
	 *
	 * @param int   $tag   Tag number (e.g. 18 for COSE_Sign1).
	 * @param mixed $value The value to tag.
	 * @return string CBOR-encoded tagged value.
	 */
	public static function encode_tagged( int $tag, $value ): string {
		return self::encode_head( 6, $tag ) . self::encode( $value );
	}

	/**
	 * Encodes a map with CTAP2 canonical key ordering.
	 *
	 * Keys are sorted by their CBOR-encoded form: shorter encodings first,
	 * then lexicographic comparison for equal-length encodings. Accepts
	 * integer and string keys.
	 *
	 * @since x.x.x
	 *
	 * @param array<int|string, mixed> $map Key-value pairs.
	 * @return string CBOR-encoded map.
	 */
	public static function encode_map( array $map ): string {
		// Encode all keys and values, preserving key association.
		$encoded_pairs = array();
		foreach ( $map as $key => $value ) {
			$encoded_key = is_int( $key )
				? self::encode( $key )
				: self::encode_text_string( (string) $key );

			$encoded_value = is_string( $value ) && self::is_cbor_bytes( $value )
				? $value
				: self::encode( $value );

			$encoded_pairs[] = array(
				'key_bytes'   => $encoded_key,
				'value_bytes' => $encoded_value,
			);
		}

		// CTAP2 canonical sort: shorter key encodings first, then lexicographic.
		usort(
			$encoded_pairs,
			static function ( array $a, array $b ): int {
				$len_a = strlen( $a['key_bytes'] );
				$len_b = strlen( $b['key_bytes'] );

				if ( $len_a !== $len_b ) {
					return $len_a - $len_b;
				}

				return strcmp( $a['key_bytes'], $b['key_bytes'] );
			}
		);

		$body = '';
		foreach ( $encoded_pairs as $pair ) {
			$body .= $pair['key_bytes'] . $pair['value_bytes'];
		}

		return self::encode_head( 5, count( $encoded_pairs ) ) . $body;
	}

	/**
	 * Encodes an unsigned integer (CBOR major type 0).
	 *
	 * @since x.x.x
	 *
	 * @param int $value Non-negative integer.
	 * @return string CBOR-encoded unsigned integer.
	 */
	private static function encode_unsigned_int( int $value ): string {
		return self::encode_head( 0, $value );
	}

	/**
	 * Encodes a negative integer (CBOR major type 1).
	 *
	 * CBOR encodes negative integers as -1 - n, where n is the encoded value.
	 *
	 * @since x.x.x
	 *
	 * @param int $value Negative integer.
	 * @return string CBOR-encoded negative integer.
	 */
	private static function encode_negative_int( int $value ): string {
		return self::encode_head( 1, -1 - $value );
	}

	/**
	 * Encodes a text string (CBOR major type 3).
	 *
	 * @since x.x.x
	 *
	 * @param string $text UTF-8 text string.
	 * @return string CBOR-encoded text string.
	 */
	private static function encode_text_string( string $text ): string {
		return self::encode_head( 3, strlen( $text ) ) . $text;
	}

	/**
	 * Encodes a CBOR array (major type 4).
	 *
	 * @since x.x.x
	 *
	 * @param array<int, mixed> $items Array items.
	 * @return string CBOR-encoded array.
	 */
	private static function encode_array( array $items ): string {
		$body = '';
		foreach ( $items as $item ) {
			$body .= self::encode( $item );
		}

		return self::encode_head( 4, count( $items ) ) . $body;
	}

	/**
	 * Encodes the CBOR initial byte(s) for a major type and argument.
	 *
	 * @since x.x.x
	 *
	 * @param int $major_type CBOR major type (0-7).
	 * @param int $argument   Non-negative integer argument.
	 * @return string Encoded head bytes.
	 */
	private static function encode_head( int $major_type, int $argument ): string {
		$major = $major_type << 5;

		if ( $argument <= 23 ) {
			return pack( 'C', $major | $argument );
		}

		if ( $argument <= 0xFF ) {
			return pack( 'CC', $major | 24, $argument );
		}

		if ( $argument <= 0xFFFF ) {
			return pack( 'Cn', $major | 25, $argument );
		}

		if ( $argument <= 0xFFFFFFFF ) {
			return pack( 'CN', $major | 26, $argument );
		}

		return pack( 'CJ', $major | 27, $argument );
	}

	/**
	 * Checks whether a PHP array has sequential integer keys starting from 0.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $arr The array to check.
	 * @return bool True if sequential, false otherwise.
	 */
	private static function is_sequential_array( array $arr ): bool {
		if ( empty( $arr ) ) {
			return true;
		}

		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}

	/**
	 * Heuristic check for pre-encoded CBOR byte strings in map values.
	 *
	 * Values passed to encode_map() that are already CBOR-encoded (from
	 * encode_byte_string, encode_tagged, etc.) should not be double-encoded.
	 * This checks if the string looks like a CBOR byte string header.
	 *
	 * @since x.x.x
	 *
	 * @param string $value The string to check.
	 * @return bool True if this appears to be pre-encoded CBOR.
	 */
	private static function is_cbor_bytes( string $value ): bool {
		if ( strlen( $value ) === 0 ) {
			return false;
		}

		$first = ord( $value[0] );

		// Check if the first byte indicates a CBOR byte string (major type 2: 0x40-0x5f).
		return ( $first >= 0x40 && $first <= 0x5b );
	}
}
