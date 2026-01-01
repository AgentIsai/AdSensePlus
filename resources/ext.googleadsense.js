( function () {
	'use strict';

	var cfg = mw.config.get( 'wgGoogleAdSense' );
	if ( !cfg || !cfg.enabled ) {
		return;
	}

	function pushAllAds() {
		var nodes = document.querySelectorAll( 'ins.adsbygoogle' );
		for ( var i = 0; i < nodes.length; i++ ) {
			var ins = nodes[ i ];
			if ( ins.dataset.mwGoogleadsensePushed ) {
				continue;
			}
			ins.dataset.mwGoogleadsensePushed = '1';
			try {
				( window.adsbygoogle = window.adsbygoogle || [] ).push( {} );
			} catch ( e ) {
				// Ignore; AdSense may be blocked by CSP/adblock.
			}
		}
	}

	function ensureInjected( placement, selectorCandidates, insertFn ) {
		var templatesRoot = document.getElementById( 'mw-googleadsense-templates' );
		if ( !templatesRoot ) {
			return;
		}
		var template = templatesRoot.querySelector( '.mw-googleadsense-template[data-placement="' + placement + '"]' );
		if ( !template ) {
			return;
		}

		// Avoid duplicates
		if ( document.querySelector( '.mw-googleadsense--' + placement ) ) {
			return;
		}

		for ( var i = 0; i < selectorCandidates.length; i++ ) {
			var target = document.querySelector( selectorCandidates[ i ] );
			if ( target ) {
				var node = template.firstElementChild ? template.firstElementChild.cloneNode( true ) : null;
				if ( node ) {
					insertFn( target, node );
					break;
				}
			}
		}
	}

	function universalInject() {
		if ( !cfg.universalInjector ) {
			return;
		}

		// Sidebar (Vector/Vector-2022: #mw-panel; MonoBook: #column-one; Timeless: #mw-panel; Cosmos: .cosmos-sidebar)
		ensureInjected(
			'sidebar',
			[
				'#mw-panel',
				'#column-one',
				'.mw-sidebar',
				'.vector-sidebar',
				'.vector-sidebar-container',
				'.cosmos-sidebar',
				'#cosmos-sidebar'
			],
			function ( target, node ) {
				target.appendChild( node );
			}
		);

		// Banner near sitenotice
		ensureInjected(
			'banner',
			[
				'#siteNotice',
				'.mw-sitenotice',
				'.cosmos-sitenotice',
				'.cosmos-notice'
			],
			function ( target, node ) {
				// Insert after sitenotice
				if ( target.parentNode ) {
					target.parentNode.insertBefore( node, target.nextSibling );
				} else {
					target.appendChild( node );
				}
			}
		);

		// After content
		ensureInjected(
			'afterContent',
			[
				'#mw-content-text',
				'#bodyContent',
				'.mw-body-content',
				'#content'
			],
			function ( target, node ) {
				target.appendChild( node );
			}
		);

		// Footer
		ensureInjected(
			'footer',
			[
				'#footer',
				'.mw-footer',
				'.footer',
				'.cosmos-footer'
			],
			function ( target, node ) {
				target.appendChild( node );
			}
		);
	}

	function initFloatingBottom() {
		var el = document.getElementById( 'mw-googleadsense-floating-bottom' );
		if ( !el ) {
			return;
		}
		el.setAttribute( 'aria-hidden', 'false' );
		var close = el.querySelector( '.mw-googleadsense-close' );
		if ( close ) {
			close.addEventListener( 'click', function () {
				el.parentNode && el.parentNode.removeChild( el );
			} );
		}
	}

	function initLinkClickInterstitial() {
		var el = document.getElementById( 'mw-googleadsense-interstitial' );
		if ( !el ) {
			return;
		}

		var continueBtn = el.querySelector( '.mw-googleadsense-continue' );
		var closeBtn = el.querySelector( '.mw-googleadsense-close' );

		var pendingHref = null;
		var pendingTarget = null;

		function hide() {
			el.setAttribute( 'aria-hidden', 'true' );
			el.classList.remove( 'is-open' );
			pendingHref = null;
			pendingTarget = null;
		}

		function show() {
			el.setAttribute( 'aria-hidden', 'false' );
			el.classList.add( 'is-open' );
			// Trigger ad render when visible
			pushAllAds();
		}

		if ( closeBtn ) {
			closeBtn.addEventListener( 'click', hide );
		}
		el.addEventListener( 'click', function ( e ) {
			// Click on backdrop closes
			if ( e.target === el ) {
				hide();
			}
		} );

		if ( continueBtn ) {
			continueBtn.addEventListener( 'click', function () {
				if ( !pendingHref ) {
					hide();
					return;
				}
				var href = pendingHref;
				var target = pendingTarget;
				hide();

				if ( target && target !== '_self' ) {
					window.open( href, target );
				} else {
					window.location.href = href;
				}
			} );
		}

		document.addEventListener( 'click', function ( e ) {
			if ( el.classList.contains( 'is-open' ) ) {
				// Don't intercept when overlay already open.
				return;
			}
			var a = e.target && e.target.closest ? e.target.closest( 'a' ) : null;
			if ( !a ) {
				return;
			}

			// Respect modifiers/new-tab behavior
			if ( e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) {
				return;
			}

			// Ignore anchors without href, JS pseudo-links, and file downloads
			var href = a.getAttribute( 'href' );
			if ( !href || href.indexOf( 'javascript:' ) === 0 || href.indexOf( '#' ) === 0 ) {
				return;
			}

			// Ignore links that explicitly opt out
			if ( a.classList.contains( 'mw-googleadsense-nointerstitial' ) ) {
				return;
			}

			// If the interstitial is disabled in config, do nothing
			if ( !cfg.placements || !cfg.placements.linkClick ) {
				return;
			}

			e.preventDefault();

			pendingHref = a.href;
			pendingTarget = a.getAttribute( 'target' ) || '_self';

			var delay = parseInt( cfg.linkClickDelayMs, 10 ) || 0;
			if ( delay > 0 ) {
				window.setTimeout( show, delay );
			} else {
				show();
			}
		}, true );
	}

	// Run once DOM is ready
	mw.hook( 'wikipage.content' ).add( function () {
		universalInject();
		initFloatingBottom();
		initLinkClickInterstitial();
		pushAllAds();
	} );

	// Also try after full load (some skins build parts late)
	window.addEventListener( 'load', function () {
		universalInject();
		pushAllAds();
	} );

}() );
