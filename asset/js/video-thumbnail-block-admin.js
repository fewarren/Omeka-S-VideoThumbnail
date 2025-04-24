/**
 * VideoThumbnail Block Admin JavaScript
 * 
 * Handles media selection functionality for the video thumbnail block.
 * Simplified to avoid causing Omeka S bootstrap hanging issues.
 */
(function($) {
    // Wait for document ready
    $(document).ready(function() {
        initVideoThumbnailBlock();
    });
    
    // Initialize on Omeka events
    $(document).on('o:sidebar-content-loaded o:block-added', function() {
        initVideoThumbnailBlock();
    });
    
    /**
     * Initialize the block functionality
     */
    function initVideoThumbnailBlock() {
        // Remove any existing handlers to avoid duplicates
        $(document).off('click', '.select-media');
        $(document).off('click', '.remove-media');
        
        // Add new handlers
        $(document).on('click', '.select-media', handleMediaSelection);
        $(document).on('click', '.remove-media', handleMediaRemoval);
    }
    
    /**
     * Handle the select media button click
     */
    function handleMediaSelection(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var $button = $(this);
        var $container = $button.closest('.field');
        var $mediaIdInput = $container.find('.media-id');
        var $selectedMediaSpan = $container.find('.selected-media');
        
        // Use Omeka's media browser if available
        if (typeof Omeka !== 'undefined' && typeof Omeka.openMediaBrowser === 'function') {
            Omeka.openMediaBrowser(function(selections) {
                if (selections && selections.length > 0) {
                    var selection = selections[0];
                    if (selection.id) {
                        $mediaIdInput.val(selection.id);
                        $selectedMediaSpan.text(selection.display_title || selection.title || selection.id);
                        
                        // Add clear button if needed
                        if ($container.find('.remove-media').length === 0) {
                            $('<button type="button" class="remove-media button">Clear</button>')
                                .insertAfter($button);
                        }
                    }
                }
            });
        } else if (typeof Omeka !== 'undefined' && typeof Omeka.resourceSelectorOpen === 'function') {
            // Fallback to resource selector for older Omeka versions
            Omeka.resourceSelectorOpen($button, 'media', function(selections) {
                if (selections && selections.length > 0) {
                    var selection = selections[0];
                    $mediaIdInput.val(selection.value || selection.id);
                    $selectedMediaSpan.text(selection.text || selection.display_title || selection.id);
                    
                    // Add clear button if needed
                    if ($container.find('.remove-media').length === 0) {
                        $('<button type="button" class="remove-media button">Clear</button>')
                            .insertAfter($button);
                    }
                }
            });
        } else {
            alert('Media browser not available. Please refresh the page and try again.');
        }
    }
    
    /**
     * Handle the clear button click
     */
    function handleMediaRemoval(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var $button = $(this);
        var $container = $button.closest('.field');
        var $mediaIdInput = $container.find('.media-id');
        var $selectedMediaSpan = $container.find('.selected-media');
        
        // Clear the selection
        $mediaIdInput.val('');
        $selectedMediaSpan.text('No media selected');
        
        // Remove the clear button
        $button.remove();
    }
})(window.jQuery);