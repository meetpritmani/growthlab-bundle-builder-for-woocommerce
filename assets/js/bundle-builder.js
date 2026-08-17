/**
 * GrowthLab Bundle Builder for WooCommerce — frontend picker.
 *
 * Vanilla JS, no dependencies. Selection state lives on the DOM node so that
 * several pickers can coexist on one page.
 *
 * Since 1.1.0 selection is tracked per category slot. A bundle with a single
 * slot behaves exactly as it did in 1.0 — the slot wrapper carries no header
 * and the running counter in the action bar still shows the combined total.
 *
 * @package GrowthLab_Bundle_Builder_For_WooCommerce
 */

( function () {
	'use strict';

	var params = window.glbbwcParams || {};
	var i18n = params.i18n || {};

	/**
	 * Minimal printf for the `%1$d`/`%2$d` placeholders used by our strings.
	 *
	 * @param {string} template Template string.
	 * @param {Array}  args     Replacement values, 1-indexed in the template.
	 * @return {string} Formatted string.
	 */
	function format( template, args ) {
		return String( template ).replace( /%(\d+)\$[ds]/g, function ( match, index ) {
			var value = args[ parseInt( index, 10 ) - 1 ];

			return typeof value === 'undefined' ? match : value;
		} );
	}

	/**
	 * Wire up a single bundle picker.
	 *
	 * @param {HTMLElement} root Bundle root element.
	 */
	function initBundle( root ) {
		if ( root.dataset.glbbwcReady === '1' ) {
			return;
		}

		root.dataset.glbbwcReady = '1';

		var required = parseInt( root.dataset.required, 10 ) || 0;
		var bundleId = parseInt( root.dataset.bundleId, 10 ) || 0;
		var counter = root.querySelector( '.glbbwc-bundle__counter' );
		var submit = root.querySelector( '.glbbwc-bundle__submit' );
		var notices = root.querySelector( '.glbbwc-bundle__notices' );
		var preview = root.querySelector( '.glbbwc-bundle__preview' );
		var previewSlots = preview
			? Array.prototype.slice.call( preview.querySelectorAll( '.glbbwc-bundle__preview-slot' ) )
			: [];

		// One entry per slot: its element, its required count, and what is picked.
		var slots = Array.prototype.map.call( root.querySelectorAll( '.glbbwc-bundle__slot' ), function ( element ) {
			return {
				id: element.dataset.slotId,
				required: parseInt( element.dataset.slotRequired, 10 ) || 0,
				element: element,
				counter: element.querySelector( '.glbbwc-bundle__slot-counter' ),
				tiles: Array.prototype.slice.call( element.querySelectorAll( '.glbbwc-product' ) ),
				selected: []
			};
		} );

		/**
		 * Replace the notice area contents.
		 *
		 * @param {Array}  messages List of messages.
		 * @param {string} type     Either 'error', 'info' or 'success'.
		 */
		function setNotices( messages, type, action ) {
			if ( ! notices ) {
				return;
			}

			notices.innerHTML = '';
			notices.className = 'glbbwc-bundle__notices';

			if ( ! messages || ! messages.length ) {
				return;
			}

			notices.classList.add( 'glbbwc-bundle__notices--' + ( type || 'error' ) );

			var list = document.createElement( 'ul' );

			messages.forEach( function ( message ) {
				var item = document.createElement( 'li' );

				item.textContent = message;
				list.appendChild( item );
			} );

			// An optional follow-up link, e.g. "View cart" once a bundle has been
			// added and the shopper has been left on the picker.
			if ( action && action.url && action.label ) {
				var actionItem = document.createElement( 'li' );
				var link = document.createElement( 'a' );

				actionItem.className = 'glbbwc-bundle__notice-action';
				link.className = 'glbbwc-bundle__view-cart button';
				link.href = action.url;
				link.textContent = action.label;

				actionItem.appendChild( link );
				list.appendChild( actionItem );
			}

			notices.appendChild( list );
		}

		/**
		 * Every picked product ID across all slots, in slot order.
		 *
		 * @return {Array} Product IDs.
		 */
		function allSelected() {
			return slots.reduce( function ( carry, slot ) {
				return carry.concat( slot.selected );
			}, [] );
		}

		/**
		 * Find the tile for a product ID within a slot.
		 *
		 * @param {Object} slot      Slot record.
		 * @param {number} productId Product ID.
		 * @return {HTMLElement|null} Tile element.
		 */
		function findTile( slot, productId ) {
			for ( var i = 0; i < slot.tiles.length; i++ ) {
				if ( parseInt( slot.tiles[ i ].dataset.productId, 10 ) === productId ) {
					return slot.tiles[ i ];
				}
			}

			return null;
		}

		/**
		 * Fill one preview position from a picked tile.
		 *
		 * The image is cloned from the tile already on the page rather than
		 * fetched, so the preview costs no extra requests and inherits whatever
		 * responsive sizes the theme rendered.
		 *
		 * @param {HTMLElement} position Preview slot element.
		 * @param {HTMLElement} tile     Source product tile.
		 */
		function fillPreviewSlot( position, tile ) {
			var image = tile.querySelector( '.glbbwc-product__media img' );
			var title = tile.querySelector( '.glbbwc-product__title' );

			position.innerHTML = '';
			position.classList.remove( 'is-empty' );

			if ( image ) {
				var clone = image.cloneNode( false );

				clone.removeAttribute( 'id' );
				clone.setAttribute( 'alt', '' );
				clone.setAttribute( 'loading', 'lazy' );
				position.appendChild( clone );
			}

			if ( title ) {
				var caption = document.createElement( 'span' );

				caption.className = 'glbbwc-bundle__preview-caption';
				caption.textContent = title.textContent;
				position.appendChild( caption );
			}
		}

		/**
		 * Reset one preview position to its numbered placeholder.
		 *
		 * @param {HTMLElement} position Preview slot element.
		 * @param {number}      index    Zero-based position.
		 */
		function emptyPreviewSlot( position, index ) {
			var placeholder = document.createElement( 'span' );

			placeholder.className = 'glbbwc-bundle__preview-placeholder';
			placeholder.setAttribute( 'aria-hidden', 'true' );
			placeholder.textContent = String( index + 1 );

			position.innerHTML = '';
			position.classList.add( 'is-empty' );
			position.appendChild( placeholder );
		}

		/**
		 * Rebuild the bundle preview from the current selection.
		 *
		 * Positions are filled in slot order, so a multi-slot bundle reads left to
		 * right in the same order the slots appear on the page.
		 */
		function renderPreview() {
			if ( ! previewSlots.length ) {
				return;
			}

			var picked = [];

			slots.forEach( function ( slot ) {
				slot.selected.forEach( function ( productId ) {
					var tile = findTile( slot, productId );

					if ( tile ) {
						picked.push( tile );
					}
				} );
			} );

			previewSlots.forEach( function ( position, index ) {
				if ( picked[ index ] ) {
					fillPreviewSlot( position, picked[ index ] );
				} else {
					emptyPreviewSlot( position, index );
				}
			} );

			preview.classList.toggle( 'is-filled', picked.length > 0 );
		}

		/**
		 * Reflect the current selection in the UI.
		 */
		function refresh() {
			var complete = true;

			slots.forEach( function ( slot ) {
				var atLimit = slot.selected.length >= slot.required;

				if ( slot.selected.length !== slot.required ) {
					complete = false;
				}

				slot.tiles.forEach( function ( tile ) {
					var id = parseInt( tile.dataset.productId, 10 );
					var isSelected = slot.selected.indexOf( id ) !== -1;
					var button = tile.querySelector( '.glbbwc-product__toggle' );

					tile.classList.toggle( 'is-selected', isSelected );
					tile.classList.toggle( 'is-disabled', atLimit && ! isSelected );

					if ( button ) {
						button.setAttribute( 'aria-pressed', isSelected ? 'true' : 'false' );
						button.disabled = atLimit && ! isSelected;
					}
				} );

				slot.element.classList.toggle( 'is-complete', atLimit );

				if ( slot.counter ) {
					slot.counter.textContent = format( i18n.counter || '%1$d of %2$d selected', [
						slot.selected.length,
						slot.required
					] );
				}
			} );

			renderPreview();

			var total = allSelected().length;

			if ( counter ) {
				counter.textContent = format( i18n.counter || '%1$d of %2$d selected', [ total, required ] );
			}

			if ( submit ) {
				submit.disabled = ! complete;
			}

			root.classList.toggle( 'is-complete', complete );

			/**
			 * Let extensions react to selection changes (e.g. live surcharges).
			 */
			root.dispatchEvent(
				new CustomEvent( 'glbbwc:selection-change', {
					bubbles: true,
					detail: {
						bundleId: bundleId,
						selected: allSelected(),
						required: required,
						slots: slots.map( function ( slot ) {
							return {
								id: slot.id,
								required: slot.required,
								selected: slot.selected.slice()
							};
						} )
					}
				} )
			);
		}

		/**
		 * Select or deselect a product within one slot.
		 *
		 * @param {Object} slot      Slot record.
		 * @param {number} productId Product ID.
		 */
		function toggle( slot, productId ) {
			var index = slot.selected.indexOf( productId );

			if ( index !== -1 ) {
				slot.selected.splice( index, 1 );
				setNotices( [] );
			} else {
				if ( slot.selected.length >= slot.required ) {
					setNotices( [ i18n.limit ], 'info' );

					return;
				}

				slot.selected.push( productId );
				setNotices( [] );
			}

			refresh();
		}

		slots.forEach( function ( slot ) {
			slot.tiles.forEach( function ( tile ) {
				var button = tile.querySelector( '.glbbwc-product__toggle' );

				if ( ! button ) {
					return;
				}

				button.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					toggle( slot, parseInt( tile.dataset.productId, 10 ) );
				} );
			} );
		} );

		/*
		 * Revealed only once the script is running: without JS the preview would
		 * sit there as a row of empty numbered boxes that never fill.
		 */
		if ( preview && previewSlots.length ) {
			preview.hidden = false;
		}

		if ( submit ) {
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

				body.append( 'action', 'glbbwc_add_bundle_to_cart' );
				body.append( 'nonce', params.nonce || '' );
				body.append( 'bundle_id', bundleId );
				body.append( 'quantity', 1 );

				slots.forEach( function ( slot ) {
					slot.selected.forEach( function ( id ) {
						// `slots[…]` drives per-slot validation; `products[]` is kept
						// so anything written against the 1.0 endpoint still works.
						body.append( 'slots[' + slot.id + '][]', id );
						body.append( 'products[]', id );
					} );
				} );

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

							slots.forEach( function ( slot ) {
								slot.selected = [];
							} );

							refresh();
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
						submit.textContent = originalLabel;
						refresh();
					} );
			} );
		}

		refresh();
	}

	/**
	 * Initialise every picker on the page.
	 */
	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '.glbbwc-bundle' ), initBundle );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	// Expose for pickers injected after load (e.g. by a page builder preview).
	window.glbbwcInitBundles = init;
} )();
