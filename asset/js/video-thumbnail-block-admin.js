/**
 * JavaScript for the Video Thumbnail Block configuration in the admin interface.
 */
$(document).ready(function() {
    $(document).on('click', '.select-media', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const blockContainer = button.closest('.block-form');
        const mediaIdInput = blockContainer.find('.media-id');
        const selectedMediaSpan = blockContainer.find('.selected-media');
        
        Omeka.openMediaBrowser(function(selections) {
            if (!selections) return;
            if (selections.length < 1) return;
            
            const selection = selections[0];
            mediaIdInput.val(selection.id);
            selectedMediaSpan.text(selection.display_title || selection.id);
        });
    });
});