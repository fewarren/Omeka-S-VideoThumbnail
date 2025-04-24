/**
 * VideoThumbnail Block Admin JavaScript
 * 
 * Handles media selection functionality for the video thumbnail block.
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
        console.log('VideoThumbnail: Initializing block selection');
        
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
        
        // Get the URL from the data attribute
        var sidebarContentUrl = $button.data('sidebar-content-url');
        
        // Use Omeka's sidebar select functionality
        Omeka.openSidebar(sidebarContentUrl);
        
        // Handle the selection
        Omeka.mediaSidebar = {
            selectedMedia: function(id, title) {
                $mediaIdInput.val(id);
                $selectedMediaSpan.text(title || id);
                
                // Add clear button if needed
                if ($container.find('.remove-media').length === 0) {
                    $('<button type="button" class="remove-media button">Clear</button>')
                        .appendTo($button.parent());
                }
                
                Omeka.closeSidebar();
            }
        };
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