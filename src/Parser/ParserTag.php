<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\GoogleAdSense;

use Html;
use MediaWiki\Parser\Parser;
use PPFrame;

/**
 * <adsense /> parser tag.
 *
 * We emit a marker in parser output and replace it in ParserOutputPostCacheTransform
 * to keep eligibility logic per-request (parser cache is shared).
 */
final class ParserTag {

	/**
	 * @param string|null $input Not used
	 * @param array $args Tag attributes
	 */
	public static function render( $input, array $args, Parser $parser, PPFrame $frame ): string {
		$attrs = [];

		if ( isset( $args['slot'] ) ) {
			$attrs['slot'] = (string)$args['slot'];
		}
		if ( isset( $args['format'] ) ) {
			$attrs['format'] = (string)$args['format'];
		}
		if ( isset( $args['responsive'] ) ) {
			$attrs['responsive'] = (string)$args['responsive'];
		}
		if ( isset( $args['style'] ) ) {
			$attrs['style'] = (string)$args['style'];
		}

		$json = json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$json = htmlspecialchars( $json ?: '{}', ENT_QUOTES );

		// Empty span marker: replaced/removed later.
		return Html::rawElement(
			'span',
			[
				'class' => 'mw-googleadsense-marker',
				'data-adsense-attrs' => $json,
			],
			''
		);
	}
}
