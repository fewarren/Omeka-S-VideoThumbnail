jQuery(function($) {
    console.log('VideoThumbnail block.js loaded');
    // Add a visible marker to the DOM to confirm block.js is loaded
    if ($('#videothumbnail-js-marker').length === 0) {
        $('body').append('<div id="videothumbnail-js-marker" style="position:fixed;bottom:0;right:0;background:#ffc;padding:4px 8px;z-index:9999;font-size:14px;">VideoThumbnail JS loaded</div>');
    }
    // Use event delegation for dynamically added blocks
    $(document).on('click', '.select-media', function(e) {
        if (typeof Omeka !== 'undefined' && typeof Omeka.openMediaBrowser === 'function') {
            console.log('Select Video button clicked');
            e.preventDefault();
            alert('VideoThumbnail: Select Video button clicked!');
            var $input = $(this).siblings('.media-id');
            var $label = $(this).siblings('.selected-media');
            Omeka.openMediaBrowser(function(selections) {
                if (selections && selections.length > 0) {
                    $input.val(selections[0].id);
                    $label.text(selections[0].display_title || selections[0].id);
                }
            });
        } else {
            e.preventDefault();
            if (!window._videoThumbnailMediaBrowserWarned) {
                alert('Media browser not available.');
                console.warn('VideoThumbnail: Omeka.openMediaBrowser is not available.');
                window._videoThumbnailMediaBrowserWarned = true;
            }
        }
    });
});
