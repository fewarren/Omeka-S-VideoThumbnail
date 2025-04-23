/**
 * JavaScript for the Video Thumbnail Block configuration in the admin interface.
 */
$(document).ready(function() {
    console.log('video-thumbnail-block-admin.js loaded'); // Add this line

    $(document).on('click', '.select-media', function(e) {
        e.preventDefault();
        console.log('Select media button clicked');
        try {
            const button = $(this);
            const blockContainer = button.closest('.block-form, .block');
            const mediaIdInput = blockContainer.find('.media-id');
            const selectedMediaSpan = blockContainer.find('.selected-media');

            if (typeof Omeka === 'undefined' || typeof Omeka.openMediaBrowser !== 'function') {
                console.error('Omeka.openMediaBrowser is not available.');
                alert('Media browser is not available.');
                return;
            }

            Omeka.openMediaBrowser(function(selections) {
                console.log('Media selected:', selections);
                if (!selections) {
                    console.warn('No selections returned from media browser.');
                    return;
                }
                if (selections.length < 1) {
                    console.warn('Empty selection array from media browser.');
                    return;
                }
                const selection = selections[0];
                if (!selection.id) {
                    console.error('Selected media has no ID:', selection);
                    alert('Selected media is invalid.');
                    return;
                }
                mediaIdInput.val(selection.id);
                selectedMediaSpan.text(selection.display_title || selection.id);
                blockContainer.find('.video-thumbnail-block').addClass('selected');
            });
        } catch (err) {
            console.error('Error handling select-media click:', err);
            alert('An error occurred while selecting media.');
        }
    });
    // Optional: Add input validation for percent field
    $(document).on('input', '.frame-percent', function() {
        let val = parseInt($(this).val(), 10);
        if (isNaN(val) || val < 0) val = 0;
        if (val > 100) val = 100;
        $(this).val(val);
    });
});