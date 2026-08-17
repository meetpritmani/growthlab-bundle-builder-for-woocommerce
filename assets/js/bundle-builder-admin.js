/**
 * GrowthLab Bundle Builder for WooCommerce — admin category-slot repeater.
 *
 * Vanilla JS, no dependencies, matching the frontend picker's style.
 *
 * @package GrowthLab_Bundle_Builder_For_WooCommerce
 */

( function () {
	'use strict';

	var i18n = ( window.glbbwcAdminParams || {} ).i18n || {};

	/**
	 * Wire up the slot repeater inside the Bundle Settings meta box.
	 *
	 * @param {HTMLElement} wrap Container holding the slot rows.
	 */
	function initSlots( wrap ) {
		if ( wrap.dataset.glbbwcReady === '1' ) {
			return;
		}

		wrap.dataset.glbbwcReady = '1';

		var template = document.getElementById( 'tmpl-glbbwc-slot-row' );
		var addButton = document.querySelector( '.glbbwc-slots__add' );

		/**
		 * Grey out categories already claimed by another slot, so each product
		 * can only ever belong to one slot. Mirrors the server-side dedupe in
		 * GLBBWC_Admin::parse_posted_slots().
		 */
		function refreshAvailability() {
			var selects = Array.prototype.slice.call( wrap.querySelectorAll( '.glbbwc-slot__categories' ) );
			var claimed = {};

			selects.forEach( function ( select ) {
				Array.prototype.forEach.call( select.selectedOptions, function ( option ) {
					claimed[ option.value ] = select;
				} );
			} );

			selects.forEach( function ( select ) {
				Array.prototype.forEach.call( select.options, function ( option ) {
					var owner = claimed[ option.value ];

					option.disabled = !! owner && owner !== select;
				} );
			} );

			// Keep at least one slot on screen.
			var rows = wrap.querySelectorAll( '.glbbwc-slot' );

			Array.prototype.forEach.call( rows, function ( row ) {
				var remove = row.querySelector( '.glbbwc-slot__remove' );

				if ( remove ) {
					remove.hidden = rows.length < 2;
				}
			} );
		}

		wrap.addEventListener( 'change', function ( event ) {
			if ( event.target.classList.contains( 'glbbwc-slot__categories' ) ) {
				refreshAvailability();
			}
		} );

		wrap.addEventListener( 'click', function ( event ) {
			var remove = event.target.closest( '.glbbwc-slot__remove' );

			if ( ! remove ) {
				return;
			}

			event.preventDefault();

			if ( wrap.querySelectorAll( '.glbbwc-slot' ).length < 2 ) {
				return;
			}

			remove.closest( '.glbbwc-slot' ).remove();
			refreshAvailability();
		} );

		if ( addButton && template ) {
			addButton.addEventListener( 'click', function ( event ) {
				event.preventDefault();

				var index = parseInt( wrap.dataset.nextIndex, 10 ) || wrap.querySelectorAll( '.glbbwc-slot' ).length;
				var holder = document.createElement( 'div' );

				holder.innerHTML = template.innerHTML.split( '__INDEX__' ).join( String( index ) );

				var row = holder.querySelector( '.glbbwc-slot' );

				if ( ! row ) {
					return;
				}

				wrap.appendChild( row );
				wrap.dataset.nextIndex = String( index + 1 );

				refreshAvailability();

				var select = row.querySelector( '.glbbwc-slot__categories' );

				if ( select ) {
					select.focus();
				}
			} );
		}

		// Warn rather than silently dropping a slot that has no category chosen.
		var form = document.getElementById( 'post' );

		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				var empty = Array.prototype.filter.call(
					wrap.querySelectorAll( '.glbbwc-slot__categories' ),
					function ( select ) {
						return ! select.selectedOptions.length;
					}
				);

				if ( ! empty.length ) {
					return;
				}

				var message = i18n.emptySlot || 'One or more bundle slots have no category selected. They will be discarded — continue?';

				if ( ! window.confirm( message ) ) {
					event.preventDefault();
					empty[ 0 ].focus();
				}
			} );
		}

		refreshAvailability();
	}

	/**
	 * Wire up the gallery picker inside the Shop Listing meta box.
	 *
	 * Attachment IDs live in one hidden comma-separated input, which is what the
	 * server parses; the thumbnails are only ever a view of that value.
	 *
	 * @param {HTMLElement} wrap Gallery container.
	 */
	function initGallery( wrap ) {
		if ( wrap.dataset.glbbwcReady === '1' ) {
			return;
		}

		wrap.dataset.glbbwcReady = '1';

		var input = wrap.querySelector( '.glbbwc-gallery__input' );
		var list = wrap.querySelector( '.glbbwc-gallery__list' );
		var addButton = wrap.querySelector( '.glbbwc-gallery__add' );
		var frame = null;

		if ( ! input || ! list || ! addButton ) {
			return;
		}

		// The media modal ships with WordPress; without it, leave the stored value
		// alone rather than offering a control that cannot work.
		if ( ! window.wp || ! window.wp.media ) {
			addButton.hidden = true;

			return;
		}

		/**
		 * Current attachment IDs, in display order.
		 *
		 * @return {Array} Attachment IDs.
		 */
		function currentIds() {
			return input.value
				.split( ',' )
				.map( function ( id ) {
					return parseInt( id, 10 );
				} )
				.filter( function ( id ) {
					return ! isNaN( id ) && id > 0;
				} );
		}

		/**
		 * Write the ID list back to the hidden input.
		 *
		 * @param {Array} ids Attachment IDs.
		 */
		function setIds( ids ) {
			input.value = ids.join( ',' );
		}

		/**
		 * Append a thumbnail for a newly chosen attachment.
		 *
		 * @param {Object} attachment Attachment model attributes.
		 */
		function addThumbnail( attachment ) {
			var size = attachment.sizes && attachment.sizes.thumbnail
				? attachment.sizes.thumbnail
				: attachment;

			var item = document.createElement( 'li' );

			item.className = 'glbbwc-gallery__item';
			item.dataset.attachmentId = String( attachment.id );

			var image = document.createElement( 'img' );

			image.src = size.url;
			image.alt = '';
			image.width = size.width || 150;
			image.height = size.height || 150;

			var remove = document.createElement( 'button' );

			remove.type = 'button';
			remove.className = 'glbbwc-gallery__remove';
			remove.setAttribute( 'aria-label', i18n.removeImage || 'Remove image' );
			remove.innerHTML = '&times;';

			item.appendChild( image );
			item.appendChild( remove );
			list.appendChild( item );
		}

		addButton.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			if ( ! frame ) {
				frame = window.wp.media( {
					title: i18n.galleryTitle || 'Bundle gallery images',
					button: { text: i18n.galleryButton || 'Add to gallery' },
					library: { type: 'image' },
					multiple: 'add'
				} );

				frame.on( 'select', function () {
					var ids = currentIds();

					frame.state().get( 'selection' ).each( function ( model ) {
						var attachment = model.toJSON();

						if ( ids.indexOf( attachment.id ) !== -1 ) {
							return;
						}

						ids.push( attachment.id );
						addThumbnail( attachment );
					} );

					setIds( ids );
				} );
			}

			frame.open();
		} );

		list.addEventListener( 'click', function ( event ) {
			var remove = event.target.closest( '.glbbwc-gallery__remove' );

			if ( ! remove ) {
				return;
			}

			event.preventDefault();

			var item = remove.closest( '.glbbwc-gallery__item' );

			if ( ! item ) {
				return;
			}

			var removedId = parseInt( item.dataset.attachmentId, 10 );

			item.remove();
			setIds(
				currentIds().filter( function ( id ) {
					return id !== removedId;
				} )
			);
		} );
	}

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '.glbbwc-slots' ), initSlots );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-glbbwc-gallery]' ), initGallery );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
