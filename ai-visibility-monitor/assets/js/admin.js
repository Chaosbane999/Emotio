/* global AVM, jQuery */
( function ( $ ) {
	'use strict';

	$( function () {
		var $btn = $( '#avm-test-connection' );
		if ( ! $btn.length ) {
			return;
		}

		$btn.on( 'click', function () {
			var $result = $( '#avm-test-result' );
			$result.removeClass( 'is-error is-success' ).text( AVM.testing || 'Testing…' );

			$.post( AVM.ajaxUrl, {
				action: 'avm_test_connection',
				nonce: AVM.nonce,
				provider: $( '#avm-provider' ).val(),
				api_key: $( '#avm-api-key' ).val()
			} )
				.done( function ( response ) {
					if ( response && response.success ) {
						$result.addClass( 'is-success' ).text( response.data.message );
					} else {
						$result.addClass( 'is-error' ).text( ( response && response.data && response.data.message ) || 'Failed.' );
					}
				} )
				.fail( function () {
					$result.addClass( 'is-error' ).text( 'Request failed.' );
				} );
		} );
	} );
}( jQuery ) );
