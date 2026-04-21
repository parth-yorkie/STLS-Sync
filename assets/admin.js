jQuery(document).ready(function($) {
	'use strict';
	
	// Handle sync button click
	$(document).on('click', '.stls-sync-post', function(e) {
		e.preventDefault();
		
		var $link = $(this);
		var postId = $link.data('post-id');
		var nonce = $link.data('nonce');
		var originalText = $link.text();
		
		// Disable link
		$link.addClass('stls-syncing').prop('disabled', true);
		$link.text(stlsData.strings.syncing);
		
		// Make AJAX request
		$.ajax({
			url: stlsData.ajaxUrl,
			type: 'POST',
			data: {
				action: 'stls_sync_post',
				post_id: postId,
				nonce: nonce
			},
			success: function(response) {
				if (response.success) {
					$link.text(stlsData.strings.success);
					$link.addClass('stls-sync-success');
					
					// Reset after 3 seconds
					setTimeout(function() {
						$link.text(originalText);
						$link.removeClass('stls-syncing stls-sync-success').prop('disabled', false);
					}, 3000);
				} else {
					$link.text(response.data && response.data.message ? response.data.message : stlsData.strings.error);
					$link.addClass('stls-sync-error');
					
					// Reset after 5 seconds
					setTimeout(function() {
						$link.text(originalText);
						$link.removeClass('stls-syncing stls-sync-error').prop('disabled', false);
					}, 5000);
				}
			},
			error: function(xhr, status, error) {
				$link.text(stlsData.strings.error);
				$link.addClass('stls-sync-error');
				
				// Reset after 5 seconds
				setTimeout(function() {
					$link.text(originalText);
					$link.removeClass('stls-syncing stls-sync-error').prop('disabled', false);
				}, 5000);
			}
		});
	});
	
	// Handle Generate API Key button click
	$(document).on('click', '.stls-generate-key-btn', function(e) {
		e.preventDefault();
		
		var $button = $(this);
		var $input = $('#' + $button.data('target'));
		var nonce = $button.data('nonce');
		var originalText = $button.text();
		
		// Disable button
		$button.prop('disabled', true);
		$button.text(stlsData.strings.generating);
		
		// Make AJAX request
		$.ajax({
			url: stlsData.ajaxUrl,
			type: 'POST',
			data: {
				action: 'stls_generate_api_key',
				nonce: nonce
			},
			success: function(response) {
				if (response.success && response.data && response.data.api_key) {
					// Set the generated key in the input field
					$input.val(response.data.api_key);
					
					// Show success message
					$button.text(stlsData.strings.keyGenerated);
					$button.addClass('button-primary');
					
					// Reset button after 2 seconds
					setTimeout(function() {
						$button.text(originalText);
						$button.removeClass('button-primary').prop('disabled', false);
					}, 2000);
				} else {
					alert(response.data && response.data.message ? response.data.message : stlsData.strings.error);
					$button.text(originalText);
					$button.prop('disabled', false);
				}
			},
			error: function(xhr, status, error) {
				alert(stlsData.strings.error);
				$button.text(originalText);
				$button.prop('disabled', false);
			}
		});
	});
	
	// Handle Copy API Key button click
	$(document).on('click', '.stls-copy-key-btn', function(e) {
		e.preventDefault();
		
		var $button = $(this);
		var $input = $('#' + $button.data('target'));
		var apiKey = $input.val();
		var originalText = $button.text();
		
		if (!apiKey) {
			alert('No API key to copy. Please generate one first.');
			return;
		}
		
		// Try to use modern Clipboard API
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(apiKey).then(function() {
				$button.text(stlsData.strings.keyCopied);
				$button.addClass('button-primary');
				
				setTimeout(function() {
					$button.text(originalText);
					$button.removeClass('button-primary');
				}, 2000);
			}).catch(function(err) {
				// Fallback to older method
				stlsCopyToClipboardFallback(apiKey, $button, originalText);
			});
		} else {
			// Fallback to older method
			stlsCopyToClipboardFallback(apiKey, $button, originalText);
		}
	});
	
	// Fallback copy to clipboard method
	function stlsCopyToClipboardFallback(text, $button, originalText) {
		var $temp = $('<textarea>');
		$('body').append($temp);
		$temp.val(text).select();
		
		try {
			var successful = document.execCommand('copy');
			if (successful) {
				$button.text(stlsData.strings.keyCopied);
				$button.addClass('button-primary');
				
				setTimeout(function() {
					$button.text(originalText);
					$button.removeClass('button-primary');
				}, 2000);
			} else {
				alert(stlsData.strings.copyFailed);
			}
		} catch (err) {
			alert(stlsData.strings.copyFailed);
		}
		
		$temp.remove();
	}
});

