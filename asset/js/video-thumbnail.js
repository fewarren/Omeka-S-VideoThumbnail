/**
 * VideoThumbnail main JavaScript
 */
(function() {
    /**
     * Initialize the video thumbnail functionality
     */
    function init() {
        console.log('VideoThumbnail: Initializing script');
        
        // Add click handler for confirmation
        const confirmables = document.querySelectorAll('.confirmable-checkbox');
        confirmables.forEach(function(checkbox) {
            checkbox.addEventListener('change', function(e) {
                if (this.checked) {
                    const message = this.dataset.confirmMessage || 'Are you sure?';
                    if (!confirm(message)) {
                        this.checked = false;
                        e.preventDefault();
                    }
                }
            });
        });
    }
    
    // Run initialization when DOM is ready
    document.addEventListener('DOMContentLoaded', init);
})();