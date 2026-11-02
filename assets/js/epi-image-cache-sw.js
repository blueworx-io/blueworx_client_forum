/**
 * ePim image worker. Keeps each ePim product image in the visitor's browser.
 *
 * Served by EPI_Image_Cache::serve_worker(), which puts self.EPI_CACHE
 * ({ base, cache, maxAgeMs, maxEntries }) above this file.
 *
 * Only GET requests under the ePim image base are answered. Everything else,
 * page loads included, never reaches respondWith(), so the browser handles it
 * exactly as it would with no worker at all.
 */
( function () {
	'use strict';

	var settings = self.EPI_CACHE;
	var PREFIX = 'epi-epim-images-';
	// Records when the saved set was started, so it can be cleared after maxAgeMs.
	var STAMP = settings.base + '__epi-stamp';

	// ePim does not allow CORS, so a response cannot be inspected: it is saved as
	// is, and the page tells us to forget any image that fails to show.

	// Saves still in progress, by URL. The page can report a broken image before
	// its save has finished, so forgetting waits for the save first.
	var saving = {};

	function isEpimImage( request ) {
		return 'GET' === request.method && 0 === request.url.indexOf( settings.base );
	}

	self.addEventListener( 'install', function ( event ) {
		self.skipWaiting();

		// Where supported (Chrome 123+), page loads go straight to the network
		// without starting this worker at all.
		if ( event.addRoutes ) {
			event.waitUntil(
				Promise.resolve( event.addRoutes( [
					{ condition: { requestMode: 'navigate' }, source: 'network' },
				] ) ).catch( function () {} )
			);
		}
	} );

	self.addEventListener( 'activate', function ( event ) {
		event.waitUntil(
			caches.keys().then( function ( names ) {
				return Promise.all( names.filter( function ( name ) {
					return 0 === name.indexOf( PREFIX ) && name !== settings.cache;
				} ).map( function ( name ) {
					return caches.delete( name );
				} ) );
			} ).then( function () {
				return self.clients.claim();
			} )
		);
	} );

	// The saved set is opened once per worker run and shared, so images
	// requested together cannot each clear the set and lose each other's saves.
	var opening = null;
	// When the open set was started; 0 while it is still being opened.
	var startedAt = 0;

	// The saved set, cleared first when it is older than maxAgeMs.
	function openFresh() {
		if ( opening && ( ! startedAt || Date.now() - startedAt < settings.maxAgeMs ) ) {
			return opening;
		}

		startedAt = 0;
		opening = caches.open( settings.cache ).then( function ( cache ) {
			return cache.match( STAMP ).then( function ( stamp ) {
				return stamp ? stamp.text() : '';
			} ).then( function ( started ) {
				var since = parseInt( started, 10 );

				if ( since && Date.now() - since < settings.maxAgeMs ) {
					startedAt = since;
					return cache;
				}

				return caches.delete( settings.cache ).then( function () {
					return caches.open( settings.cache );
				} ).then( function ( fresh ) {
					var now = Date.now();

					return fresh.put( STAMP, new Response( String( now ) ) ).then( function () {
						startedAt = now;
						return fresh;
					} );
				} );
			} );
		} ).catch( function ( error ) {
			opening = null;
			throw error;
		} );

		return opening;
	}

	// Drop the oldest images beyond maxEntries. keys() is in the order they were saved.
	function trim( cache ) {
		return cache.keys().then( function ( keys ) {
			var images = keys.filter( function ( key ) {
				return key.url !== STAMP;
			} );
			var extra = images.slice( 0, Math.max( 0, images.length - settings.maxEntries ) );

			return Promise.all( extra.map( function ( key ) {
				return cache.delete( key );
			} ) );
		} );
	}

	// Ask ePim for the image. Resolves to { response, keep }: whether to save it.
	function download( request ) {
		return fetch( request ).then( function ( response ) {
			// Opaque: the status cannot be read, so keep it unless it is visibly an error.
			return { response: response, keep: 'opaque' === response.type || response.ok };
		} );
	}

	self.addEventListener( 'fetch', function ( event ) {
		var request = event.request;

		if ( ! isEpimImage( request ) ) {
			return;
		}

		var cached = openFresh().then( function ( cache ) {
			return cache.match( request.url ).then( function ( hit ) {
				return { cache: cache, hit: hit };
			} );
		} ).catch( function () {
			return { cache: null, hit: null };
		} );

		event.respondWith(
			cached.then( function ( found ) {
				if ( found.hit ) {
					return found.hit;
				}

				return download( request ).then( function ( result ) {
					if ( result.keep && found.cache ) {
						var copy = result.response.clone();

						// A failed save (storage full, say) must never cost the visitor the image.
						var save = found.cache.put( request.url, copy ).then( function () {
							return trim( found.cache );
						} ).catch( function () {} ).then( function () {
							delete saving[ request.url ];
						} );

						saving[ request.url ] = save;
						event.waitUntil( save );
					}

					return result.response;
				} );
			} )
		);
	} );

	// The page reports an ePim image that failed to show: never reuse that copy.
	self.addEventListener( 'message', function ( event ) {
		var data = event.data || {};

		if ( 'epi-forget' !== data.type || 'string' !== typeof data.url ) {
			return;
		}

		event.waitUntil(
			Promise.resolve( saving[ data.url ] ).then( function () {
				return caches.open( settings.cache );
			} ).then( function ( cache ) {
				return cache.delete( data.url );
			} )
		);
	} );
}() );
