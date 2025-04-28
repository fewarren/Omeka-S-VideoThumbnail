/**
 * Video Thumbnail Block JavaScript functionality
 */
(function($) {
    /**
     * Initialize the video thumbnail block functionality
     */
    function initVideoThumbnailBlock() {
        $(document).on('o:block-added', function(e, data) {
            if (data.blockLayout === 'videoThumbnail') {
                handleVideoThumbnailBlock(data.$block);
            }
        });

        // For blocks that are already on the page when it loads
        $('.block[data-block-layout="videoThumbnail"]').each(function() {
            handleVideoThumbnailBlock($(this));
        });
    }

    /**
     * Set up event handlers for a video thumbnail block
     * 
     * @param {jQuery} $block The block element
     */
    function handleVideoThumbnailBlock($block) {
        // Handle showing/hiding the custom URL field based on link type selection
        var $linkTypeSelect = $block.find('.link-type-select');
        var $customUrl = $block.find('.custom-url');

        $linkTypeSelect.on('change', function() {
            if ($(this).val() === 'custom') {
                $customUrl.show();
            } else {
                $customUrl.hide();
            }
        });

        // When a new media attachment is added, update the UI
        $block.on('o:block-attachment-added', function(e, attachmentData) {
            handleAttachmentUpdate($block, attachmentData);
        });

        // When a media attachment is removed, update the UI
        $block.on('o:block-attachment-removed', function() {
            updateAttachmentWarning($block);
        });
    }

    /**
     * Handle when a new attachment is added to a block
     * 
     * @param {jQuery} $block The block element
     * @param {Object} attachmentData Data about the added attachment
     */
    function handleAttachmentUpdate($block, attachmentData) {
        // Check if the attachment has the appropriate media type
        if (attachmentData.mediaType && !attachmentData.mediaType.startsWith('video/')) {
            // Show a warning that this is not a video
            showAttachmentWarning($block, 'The selected file is not a video. Please select a video file.');
            return;
        }

        // Update any UI based on the new attachment
        updateAttachmentWarning($block);
    }

    /**
     * Update the attachment warning message if there are multiple attachments
     * 
     * @param {jQuery} $block The block element
     */
    function updateAttachmentWarning($block) {
        // Count the number of attachments
        var attachmentCount = $block.find('.attachment').length;

        // If there's more than one, show a warning
        if (attachmentCount > 1) {
            showAttachmentWarning($block, 'Multiple video files are attached. Only the first valid video will be used.');
        } else {
            // Otherwise, remove any existing warning
            $block.find('.attachment-warning').remove();
        }
    }

    /**
     * Show a warning message in the block
     * 
     * @param {jQuery} $block The block element
     * @param {string} message The warning message
     */
    function showAttachmentWarning($block, message) {
        // Remove any existing warning
        $block.find('.attachment-warning').remove();

        // Add the new warning
        var $warning = $('<div class="attachment-warning"><p>' + message + '</p></div>');
        $block.find('.attachments-form').append($warning);
    }

    // Initialize the module when the document is ready
    $(document).ready(function() {
        initVideoThumbnailBlock();
    });
})(jQuery);