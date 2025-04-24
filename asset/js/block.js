jQuery(function($) {
    // Use event delegation for dynamically added blocks
    $(document).on('click', '.select-media', function(e) {
        e.preventDefault();
        var $input = $(this).siblings('.media-id');
        var $label = $(this).siblings('.selected-media');
        if (typeof Omeka !== 'undefined' && typeof Omeka.openMediaBrowser === 'function') {
            Omeka.openMediaBrowser(function(selections) {
                if (selections && selections.length > 0) {
                    $input.val(selections[0].id);
                    $label.text(selections[0].display_title || selections[0].id);
                }
            });
        } else {
            alert('Media browser not available.');
        }
    });
});
