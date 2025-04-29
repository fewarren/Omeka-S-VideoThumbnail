(function($) {
    'use strict';

    const VideoThumbnail = {
        settings: {
            containerSelector: '.video-thumbnail-container',
            previewSelector: '.video-thumbnail-preview',
            loadingSelector: '.video-thumbnail-loading',
            errorSelector: '.video-thumbnail-error',
            framePreviewClass: 'frame-preview',
            selectButtonClass: 'frame-select-button',
            timestampClass: 'frame-timestamp'
        },

        init: function() {
            this.bindEvents();
            this.initializeFramePreviews();
        },

        bindEvents: function() {
            $(document).on('click', `.${this.settings.selectButtonClass}`, (e) => {
                e.preventDefault();
                this.handleFrameSelection($(e.currentTarget));
            });

            $(document).on('regenerateThumbnails', () => {
                this.regenerateAllThumbnails();
            });
        },

        initializeFramePreviews: function() {
            const $container = $(this.settings.containerSelector);
            if (!$container.length) return;

            this.showLoading();
            
            const mediaId = $container.data('media-id');
            if (!mediaId) {
                this.showError('Media ID not found');
                return;
            }

            this.generateFramePreviews(mediaId);
        },

        generateFramePreviews: function(mediaId) {
            $.ajax({
                url: Omeka.basePath + '/admin/video-thumbnail/generate-frames',
                method: 'POST',
                data: { media_id: mediaId },
                success: (response) => {
                    if (response.success) {
                        this.renderFramePreviews(response.frames);
                    } else {
                        this.showError(response.message || 'Failed to generate frame previews', response.details, response.help);
                    }
                },
                error: (xhr, status, error) => {
                    this.showError('Error generating frame previews: ' + error);
                },
                complete: () => {
                    this.hideLoading();
                }
            });
        },

        renderFramePreviews: function(frames) {
            const $preview = $(this.settings.previewSelector);
            $preview.empty();

            frames.forEach(frame => {
                const $framePreview = this.createFramePreview(frame);
                $preview.append($framePreview);
            });
        },

        createFramePreview: function(frame) {
            const timestamp = this.formatTimestamp(frame.timestamp);
            
            return $(`<div class="${this.settings.framePreviewClass}" tabindex="0" role="listitem">
                <img src="${frame.url}" alt="Video frame at ${timestamp}">
                <span class="${this.settings.timestampClass}">${timestamp}</span>
                <button type="button" 
                        class="${this.settings.selectButtonClass}"
                        data-position="${frame.position}"
                        data-timestamp="${frame.timestamp}"
                        aria-label="Select this frame at ${timestamp} as thumbnail">
                    Select Frame
                </button>
            </div>`);
        },

        handleFrameSelection: function($button) {
            const mediaId = $(this.settings.containerSelector).data('media-id');
            const position = $button.data('position');

            this.showLoading();

            $.ajax({
                url: Omeka.basePath + '/admin/video-thumbnail/save-frame',
                method: 'POST',
                data: {
                    media_id: mediaId,
                    position: position
                },
                success: (response) => {
                    if (response.success) {
                        this.showSuccess('Thumbnail updated successfully');
                        // Refresh thumbnails in the UI
                        $('.media-thumbnail img').attr('src', response.thumbnail_url + '?' + new Date().getTime());
                    } else {
                        this.showError(response.message || 'Failed to update thumbnail');
                    }
                },
                error: (xhr, status, error) => {
                    this.showError('Error updating thumbnail: ' + error);
                },
                complete: () => {
                    this.hideLoading();
                }
            });
        },

        regenerateAllThumbnails: function() {
            this.showLoading();

            $.ajax({
                url: Omeka.basePath + '/admin/video-thumbnail/regenerate',
                method: 'POST',
                success: (response) => {
                    if (response.success) {
                        this.showSuccess('Started regenerating all video thumbnails');
                    } else {
                        this.showError(response.message || 'Failed to start regeneration');
                    }
                },
                error: (xhr, status, error) => {
                    this.showError('Error starting regeneration: ' + error);
                },
                complete: () => {
                    this.hideLoading();
                }
            });
        },

        formatTimestamp: function(seconds) {
            const mins = Math.floor(seconds / 60);
            const secs = Math.floor(seconds % 60);
            return `${mins}:${secs.toString().padStart(2, '0')}`;
        },

        showLoading: function() {
            if (!this.$loadingElement) {
                this.$loadingElement = $(`
                    <div id="video-thumbnail-loading" class="${this.settings.loadingSelector.substring(1)}">
                        <div class="loading-spinner"></div>
                        <p>Processing video frames...</p>
                    </div>
                `);
                $('body').append(this.$loadingElement);
            }
            this.$loadingElement.show();
        },

        hideLoading: function() {
            if (this.$loadingElement) {
                this.$loadingElement.hide();
            }
        },

        showError: function(message, details, help) {
            const $container = $(this.settings.containerSelector);
            let html = `<div class="${this.settings.errorSelector.substring(1)}" role="alert" aria-live="assertive">`;
            html += `<span>${message}</span>`;
            if (details) {
                html += `<div class="video-thumbnail-error-details">${details}</div>`;
            }
            if (help) {
                html += `<div class="video-thumbnail-error-help">${help}</div>`;
            }
            html += `</div>`;
            const $error = $(html);
            $container.prepend($error);
            setTimeout(() => {
                $error.fadeOut(() => $error.remove());
            }, 7000);
        },

        showSuccess: function(message) {
            const $container = $(this.settings.containerSelector);
            const $success = $(`<div class="video-thumbnail-success">${message}</div>`);
            $container.prepend($success);
            
            setTimeout(() => {
                $success.fadeOut(() => $success.remove());
            }, 3000);
        }
    };

    // Initialize on document ready
    $(document).ready(() => {
        VideoThumbnail.init();
    });

})(window.jQuery);
