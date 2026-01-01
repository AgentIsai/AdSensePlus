<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\GoogleAdSense;

use Html;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\Parser;
use MediaWiki\ResourceLoader\Module;
use MediaWiki\Skin\Skin;
use MediaWiki\Title\Title;
use RequestContext;

/**
 * Hook handlers for the GoogleAdSense extension.
 *
 * Notes:
 * - We intentionally accept variadic arguments for hooks whose signatures may vary across versions/skins.
 * - Inline ad markers are post-cache transformed to respect per-request eligibility.
 */
final class Hooks {

	/** OutputPage property key used as a per-request cache. */
	private const OUTPROP_ENABLED = 'googleadsense-eligible';

	public static function onBeforePageDisplay( OutputPage $out, Skin $skin ): void {
		$config = $out->getConfig();
		$authority = $out->getContext()->getAuthority();

		$eligible = AdDecision::shouldShowAds( $out, $config, $authority );
		$out->setProperty( self::OUTPROP_ENABLED, $eligible ? '1' : '0' );

		if ( !$eligible ) {
			return;
		}

		$out->addModuleStyles( 'ext.googleadsense' );
		$out->addModules( 'ext.googleadsense' );

		$head = AdRenderer::renderAdsenseScriptTag( $config->get( 'GoogleAdSenseClient' ) );
		if ( $head !== '' ) {
			$out->addHeadItem( 'googleadsense-adsbygoogle', $head );
		}

		$out->addJsConfigVars( [
			'wgGoogleAdSense' => [
				'enabled' => true,
				'client' => $config->get( 'GoogleAdSenseClient' ),
				'slots' => $config->get( 'GoogleAdSenseSlots' ),
				'placements' => $config->get( 'GoogleAdSensePlacements' ),
				'universalInjector' => (bool)$config->get( 'GoogleAdSenseUniversalInjector' ),
				'linkClickDelayMs' => (int)$config->get( 'GoogleAdSenseLinkClickDelayMs' ),
			],
		] );
	}

	public static function onSiteNoticeAfter( &$siteNotice, $skin ): void {
		$out = self::safeGetOutputPage( $skin );
		if ( !$out || !self::isEligibleCached( $out ) ) {
			return;
		}
		$config = $out->getConfig();
		if ( !AdDecision::isPlacementEnabled( $config, 'banner' ) ) {
			return;
		}

		$siteNotice .= AdRenderer::renderPlacement( $config, 'banner' );
	}

	public static function onSkinAfterContent( $skin, &$data, ...$rest ): void {
		$out = self::safeGetOutputPage( $skin );
		if ( !$out || !self::isEligibleCached( $out ) ) {
			return;
		}
		$config = $out->getConfig();
		if ( !AdDecision::isPlacementEnabled( $config, 'afterContent' ) ) {
			return;
		}

		$data .= AdRenderer::renderPlacement( $config, 'afterContent' );
	}

	public static function onSkinAfterBottomScripts( $skin, &$text ): void {
		$out = self::safeGetOutputPage( $skin );
		if ( !$out || !self::isEligibleCached( $out ) ) {
			return;
		}
		$config = $out->getConfig();

		$payload = [];

		// Footer ad (below footer; injected late to be skin-agnostic).
		if ( AdDecision::isPlacementEnabled( $config, 'footer' ) ) {
			$payload[] = AdRenderer::renderPlacement( $config, 'footer' );
		}

		// Floating bottom ad.
		if ( AdDecision::isPlacementEnabled( $config, 'floatingBottom' ) ) {
			$payload[] = AdRenderer::renderFloatingBottom( $config );
		}

		// Link-click interstitial container.
		if ( AdDecision::isPlacementEnabled( $config, 'linkClick' ) ) {
			$payload[] = AdRenderer::renderLinkClickInterstitial( $config );
		}

		// Universal injector templates (used by JS to place ads in sidebars/footers across skins).
		if ( (bool)$config->get( 'GoogleAdSenseUniversalInjector' ) ) {
			$payload[] = AdRenderer::renderUniversalTemplates( $config );
		}

		if ( $payload ) {
			$text .= Html::rawElement(
				'div',
				[
					'id' => 'mw-googleadsense-runtime',
					'class' => 'mw-googleadsense-runtime noprint',
				],
				implode( "\n", $payload )
			);
		}
	}

	public static function onSkinBuildSidebar( $skin, &$bar ): void {
		$out = self::safeGetOutputPage( $skin );
		if ( !$out || !self::isEligibleCached( $out ) ) {
			return;
		}
		$config = $out->getConfig();
		if ( !AdDecision::isPlacementEnabled( $config, 'sidebar' ) ) {
			return;
		}

		// Try to add a dedicated "googleadsense" portlet. Many skins will render this.
		$bar['googleadsense'] = [
			[
				'id' => 'n-googleadsense',
				// 'html' is supported by Skin::makeListItem() and allows raw HTML.
				'html' => AdRenderer::renderPlacement( $config, 'sidebar' ),
			],
		];
	}

	public static function onSkinAfterPortlet( $skin, $portletName, &$html ): void {
		$out = self::safeGetOutputPage( $skin );
		if ( !$out || !self::isEligibleCached( $out ) ) {
			return;
		}
		$config = $out->getConfig();
		if ( !AdDecision::isPlacementEnabled( $config, 'sidebar' ) ) {
			return;
		}

		// If a skin ignores SkinBuildSidebar's 'googleadsense' portlet, we can still append
		// to a "common" portlet during rendering. We try to do this only once.
		static $didInsert = false;
		if ( $didInsert ) {
			return;
		}

		$targets = $config->get( 'GoogleAdSenseSidebarPortletOrder' );
		if ( is_array( $targets ) && in_array( (string)$portletName, $targets, true ) ) {
			$html .= AdRenderer::renderPlacement( $config, 'sidebar' );
			$didInsert = true;
		}
	}

	public static function onParserFirstCallInit( Parser $parser ): void {
		$parser->setHook( 'adsense', [ ParserTag::class, 'render' ] );
	}

	public static function onParserOutputPostCacheTransform( &$html, $parserOutput, $parserOptions = null, ...$rest ): void {
		// Replace (or remove) <adsense /> markers in a per-request way.
		if ( strpos( $html, 'mw-googleadsense-marker' ) === false ) {
			return;
		}

		$context = RequestContext::getMain();
		$out = $context->getOutput();
		$config = $context->getConfig();
		$authority = $context->getAuthority();

		// If there's no OutputPage (e.g., some CLI contexts), remove markers.
		if ( !$out || !AdDecision::shouldShowAds( $out, $config, $authority ) ) {
			$html = preg_replace( AdRenderer::INLINE_MARKER_REGEX, '', $html ) ?? $html;
			return;
		}

		$html = preg_replace_callback(
			AdRenderer::INLINE_MARKER_REGEX,
			static function ( array $m ) use ( $config ): string {
				$attrsJson = html_entity_decode( $m['attrs'] ?? '', ENT_QUOTES );
				$attrs = json_decode( $attrsJson, true );
				if ( !is_array( $attrs ) ) {
					$attrs = [];
				}
				return AdRenderer::renderPlacement( $config, 'inline', $attrs );
			},
			$html
		) ?? $html;
	}

	private static function safeGetOutputPage( $skin ): ?OutputPage {
		if ( $skin instanceof Skin ) {
			return $skin->getOutput();
		}
		return null;
	}

	private static function isEligibleCached( OutputPage $out ): bool {
		return $out->getProperty( self::OUTPROP_ENABLED ) === '1';
	}
}
