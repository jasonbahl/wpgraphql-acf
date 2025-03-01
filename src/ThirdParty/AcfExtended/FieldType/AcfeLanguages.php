<?php

namespace WPGraphQL\Acf\ThirdParty\AcfExtended\FieldType;

class AcfeLanguages {

	/**
	 * @param array|string $languages The langauge(s) to resolve as objects
	 *
	 * @return array|null
	 */
	public static function resolve_languages( $languages ): ?array {
		if ( empty( $languages ) ) {
			return null;
		}

		if ( ! is_array( $languages ) ) {
			$languages = [ $languages ];
		}

		if ( ! function_exists( 'acfe_get_language' ) ) {
			return null;
		}

		return array_filter( array_map( static function ( $language ) {
			return acfe_get_language( $language );
		}, $languages ) );
	}

	/**
	 * @return void
	 */
	public static function register_field_type(): void {
		register_graphql_acf_field_type( 'acfe_languages', [
			'graphql_type' => [ 'list_of' => 'ACFE_Language' ],
			'resolve'      => function ( $root, $args, $context, $info, $field_type, $field_config ) {
				$value = $field_config->resolve_field( $root, $args, $context, $info );
				return self::resolve_languages( $value );
			},
		]);
	}

}
