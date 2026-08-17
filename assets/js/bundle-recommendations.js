/**
 * GrowthLab Bundle Builder for WooCommerce — "Frequently Bundled With" multi-add.
 *
 * Vanilla JS, no dependencies. The product list is never sent from the browser;
 * the server re-reads the curated partners from product meta.
 *
 * @package GrowthLab_Bundle_Builder_For_WooCommerce
 */

( function () {
	'use strict';

	var params = window.glbbwcRecommendParams || {};
	var i18n = params.i18n || {};

	/**
	 * Wire up one recommendation section.
	 *
	 * @param {HTMLElement} root Section root element.
	 */
	function initSection( root ) {
		if ( root.dataset.glbbwcReady === '1' ) {
			return;
		}

		root.dataset.glbbwcReady = '1';

		var submit = root.querySelector( '.glbbwc-recommend__submit' );
		var notices = root.querySelector( '.glbbwc-recommend__notices' );
		var productId = parseInt( root.dataset.productId, 10 ) || 0;

		if ( ! submit || ! productId ) {
			return;
		}

		/**
		 * Replace the notice area contents.
		 *
		 * @param {Array}  messages List of messages.
		 * @param {string} type     Either 'error' or 'success'.
		 */
		function setNotices( messages, type, action ) {
			if ( ! notices ) {
				return;
			}

			notices.innerHTML = '';
			notices.className = 'glbbwc-recommend__notices';

			if ( ! messages || ! messages.length ) {
				return;
			}

			notices.classList.add( 'glbbwc-recommend__notices--' + ( type || 'error' ) );

			var list = document.createElement( 'ul' );

			messages.forEach( function ( message ) {
				var item = document.createElement( 'li' );

				item.textContent = message;
				list.appendChild( item );
			} );

			// Matches the bundle picker: a "View cart" follow-up once the set has
			// been added and the shopper stays on the product page.
			if ( action && action.url && action.label ) {
				var actionItem = document.createElement( 'li' );
				var link = document.createElement( 'a' );

				actionItem.className = 'glbbwc-recommend__notice-action';
				link.className = 'glbbwc-recommend__view-cart button';
				link.href = action.url;
				link.textContent = action.label;

				actionItem.appendChild( link );
				list.appendChild( actionItem );
			}

			notices.appendChild( list );
		}

		submit.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			if ( submit.disabled ) {
				return;
			}

			var originalLabel = submit.textContent;

			submit.disabled = true;
			submit.textContent = i18n.adding || 'Adding…';
			root.classList.add( 'is-busy' );
			setNotices( [] );

			var body = new FormData();

			body.append( 'action', 'glbbwc_add_recommended_to_cart' );
			body.append( 'nonce', params.nonce || '' );
			body.append( 'product_id', productId );

			fetch( params.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} )
				.then( function ( response ) {
					return response.json().catch( function () {
						return { success: false };
					} );
				} )
				.then( function ( payload ) {
					if ( payload && payload.success ) {
						var data = payload.data || {};

						if ( data.fragments ) {
							document.body.dispatchEvent(
								new CustomEvent( 'glbbwc:added-to-cart', { detail: data } )
							);
						}

						if ( data.redirect ) {
							window.location.href = data.redirect;

							return;
						}

						setNotices( [ data.message ], 'success', {
							url: params.cartUrl,
							label: i18n.viewCart
						} );
					} else {
						var messages = ( payload && payload.data && payload.data.messages ) || [ i18n.genericErr ];

						setNotices( messages, 'error' );
					}
				} )
				.catch( function () {
					setNotices( [ i18n.genericErr ], 'error' );
				} )
				.then( function () {
					root.classList.remove( 'is-busy' );
					submit.disabled = false;
					submit.textContent = originalLabel;
				} );
		} );
	}

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '.glbbwc-recommend' ), initSection );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	window.glbbwcInitRecommendations = init;
} )();
