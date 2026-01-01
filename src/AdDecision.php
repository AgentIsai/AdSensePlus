<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\GoogleAdSense;

use Config;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\Authority;
use MediaWiki\ResourceLoader\Module;

/**
 * Centralized eligibility logic.
 */
final class AdDecision {

	public static function shouldShowAds( OutputPage $out, Config $config, Authority $authority ): bool {
		if ( !(bool)$config->get( 'GoogleAdSenseEnabled' ) ) {
			return false;
		}

		$client = (string)$config->get( 'GoogleAdSenseClient' );
		if ( $client === '' ) {
			return false;
		}

		// Do not show ads when OutputPage::disallowUserJs() has been used.
		// We infer this by checking the allowed module origin for scripts.
		if ( self::isUserJsDisallowed( $out ) ) {
			return false;
		}

		// Exemption right always wins.
		if ( $authority->isAllowed( 'googleadsense-exempt' ) ) {
			return false;
		}

		// Optionally exempt logged-in users (common for community wikis).
		if ( (bool)$config->get( 'GoogleAdSenseExemptLoggedIn' ) && $authority->isRegistered() ) {
			return false;
		}

		// Optionally require a view right to see ads.
		if ( (bool)$config->get( 'GoogleAdSenseRequireViewRight' ) && !$authority->isAllowed( 'googleadsense-viewads' ) ) {
			return false;
		}

		// Namespace filtering (applies after exemptions).
		$title = $out->getTitle();
		if ( !$title ) {
			return false;
		}
		if ( $title->isSpecialPage() && !(bool)$config->get( 'GoogleAdSenseAllowSpecialPages' ) ) {
			return false;
		}

		$namespaces = $config->get( 'GoogleAdSenseNamespaces' );
		if ( is_array( $namespaces ) && $namespaces !== [] ) {
			if ( !in_array( $title->getNamespace(), $namespaces, true ) ) {
				return false;
			}
		}

		// Avoid printable views.
		if ( $out->isPrintable() ) {
			return false;
		}

		$action = $out->getContext()->getActionName();
		if ( $action !== 'view' && $action !== 'render' ) {
			return false;
		}

		return true;
	}

	public static function isPlacementEnabled( Config $config, string $placement ): bool {
		$placements = $config->get( 'GoogleAdSensePlacements' );
		return is_array( $placements ) && !empty( $placements[$placement] );
	}

	private static function isUserJsDisallowed( OutputPage $out ): bool {
		// getAllowedModules() returns a Module::ORIGIN_* constant.
		// If user scripts are disallowed, the maximum allowed origin for scripts is
		// typically below ORIGIN_USER_SITEWIDE.
		$allowed = $out->getAllowedModules( Module::TYPE_SCRIPTS );

		$thresholdConst = Module::class . '::ORIGIN_USER_SITEWIDE';
		if ( \defined( $thresholdConst ) ) {
			/** @var int $threshold */
			$threshold = \constant( $thresholdConst );
			return $allowed < $threshold;
		}

		// Fallback heuristic for older/newer constants: treat ORIGIN_EXTENSION as the ceiling.
		$extConst = Module::class . '::ORIGIN_EXTENSION';
		if ( \defined( $extConst ) ) {
			/** @var int $ext */
			$ext = \constant( $extConst );
			return $allowed <= $ext;
		}

		// If we can't detect it, default to allowing (safer for functionality).
		return false;
	}
}
