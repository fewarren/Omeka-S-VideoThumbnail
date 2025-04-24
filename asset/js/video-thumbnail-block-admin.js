/**
 * VideoThumbnail Block Admin JavaScript
 * 
 * Handles media selection functionality for the video thumbnail block.
 */
(function($) {
    // Wait for document ready
    $(document).ready(function() {
        console.log('VideoThumbnail: Document ready, initializing block');
        initVideoThumbnailBlock();
        
        // Write to a log file for diagnostics
        var debugEntry = 'VideoThumbnailBlock JS initialized - ' + new Date().toISOString();
        logActivity(debugEntry);
    });
    
    // Initialize on Omeka events
    $(document).on('o:sidebar-content-loaded o:block-added', function(e) {
        console.log('VideoThumbnail: Event triggered: ' + e.type);
        initVideoThumbnailBlock();
        
        // Write to a log file for diagnostics
        var debugEntry = 'Event triggered: ' + e.type + ' - ' + new Date().toISOString();
        logActivity(debugEntry);
    });
    
    /**
     * Helper to log activity to console and try to write to a file
     */
    function logActivity(message) {
        console.log('VideoThumbnail: ' + message);
        try {
            // We can't directly write to a file from JS, but we could make an AJAX call
            // to a logging endpoint if needed
        } catch (e) {
            console.error('VideoThumbnail: Error logging: ', e);
        }
    }
    
    /**
     * Initialize the block functionality
     */
    function initVideoThumbnailBlock() {
        logActivity('Initializing block selection');
        
        // For debugging, let's log what we find
        var selectButtons = $('.select-resource.select-media').length;
        var removeButtons = $('.remove-resource.remove-media').length;
        var resourceSelects = $('.resource-select').length;
        
        logActivity('Found elements - select buttons: ' + selectButtons + 
                   ', remove buttons: ' + removeButtons + 
                   ', resource selects: ' + resourceSelects);
        
        // Don't use delegated events to avoid potential conflicts
        $('.select-resource.select-media').each(function() {
            var $button = $(this);
            var currentHandler = $button.data('vt-handler-set');
            
            // Only bind once
            if (!currentHandler) {
                logActivity('Binding click to select button');
                $button.data('vt-handler-set', true);
                
                $button.on('click', function(e) {
                    handleMediaSelection(e, $button);
                });
            }
        });
        
        $('.remove-resource.remove-media').each(function() {
            var $button = $(this);
            var currentHandler = $button.data('vt-handler-set');
            
            // Only bind once
            if (!currentHandler) {
                logActivity('Binding click to remove button');
                $button.data('vt-handler-set', true);
                
                $button.on('click', function(e) {
                    handleMediaRemoval(e, $button);
                });
            }
        });
    }
    
    /**
     * Handle the select media button click
     */
    function handleMediaSelection(e, $button) {
        e.preventDefault();
        e.stopPropagation();
        
        logActivity('Select button clicked');
        
        var $container = $button.closest('.field');
        var $actionsContainer = $button.closest('.resource-select');
        var $mediaIdInput = $container.find('.media-id');
        var $selectedMediaSpan = $container.find('.selected-resource');
        
        // Get the URL from the actions container's data attribute
        var sidebarContentUrl = $actionsContainer.data('sidebar-content-url');
        
        if (!sidebarContentUrl) {
            logActivity('ERROR: No sidebar URL found!');
            console.error('No sidebar content URL found in data attribute');
            return;
        }
        
        logActivity('Opening sidebar with URL: ' + sidebarContentUrl);
        
        // Use Omeka's sidebar select functionality
        Omeka.openSidebar(sidebarContentUrl);
        
        // Handle the selection
        Omeka.mediaSidebar = {
            selectedMedia: function(id, title) {
                logActivity('Media selected - ID: ' + id + ', Title: ' + title);
                
                $mediaIdInput.val(id);
                $selectedMediaSpan.text(title || id);
                
                // Add clear button if needed
                if ($actionsContainer.find('.remove-resource').length === 0) {
                    $('<button type="button" class="o-icon-delete remove-resource remove-media" title="Clear selection"></button>')
                        .insertAfter($button)
                        .on('click', function(e) {
                            handleMediaRemoval(e, $(this));
                        });
                }
                
                Omeka.closeSidebar();
            }
        };
    }
    
    /**
     * Handle the clear button click
     */
    function handleMediaRemoval(e, $button) {
        e.preventDefault();
        e.stopPropagation();
        
        logActivity('Remove button clicked');
        
        var $container = $button.closest('.field');
        var $mediaIdInput = $container.find('.media-id');
        var $selectedMediaSpan = $container.find('.selected-resource');
        
        // Clear the selection
        $mediaIdInput.val('');
        $selectedMediaSpan.text('No media selected');
        
        // Remove the clear button
        $button.remove();
        
        logActivity('Media selection cleared');
    }
})(window.jQuery);