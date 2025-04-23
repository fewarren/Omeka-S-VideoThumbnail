/**
 * JavaScript for the Video Thumbnail Block configuration in the admin interface.
 */
$(document).ready(function() {
    console.log('video-thumbnail-block-admin.js loaded'); // Add this line

    $(document).on('click', '.select-media', function(e) {
        e.preventDefault();
        console.log('Select media button clicked'); // Add this line
        
        const button = $(this);
        const blockContainer = button.closest('.block-form, .block');
        const mediaIdInput = blockContainer.find('.media-id');
        const selectedMediaSpan = blockContainer.find('.selected-media');
        
        Omeka.openMediaBrowser(function(selections) {
            console.log('Media selected:', selections); // Add this line
            if (!selections) return;
            if (selections.length < 1) return;
            
            const selection = selections[0];
            mediaIdInput.val(selection.id);
            selectedMediaSpan.text(selection.display_title || selection.id);
            // Add a visual highlight
            blockContainer.find('.video-thumbnail-block').addClass('selected');
        });
    });
    // Optional: Add input validation for percent field
    $(document).on('input', '.frame-percent', function() {
        let val = parseInt($(this).val(), 10);
        if (isNaN(val) || val < 0) val = 0;
        if (val > 100) val = 100;
        $(this).val(val);
    });
});