/**
 * Form validation helper for VideoThumbnail module
 */
(function() {
    document.addEventListener('DOMContentLoaded', function() {
        // Get the form
        const form = document.querySelector('form[name="videothumbnail-config-form"]');
        if (!form) return;
        
        // Add form validation
        form.addEventListener('submit', function(e) {
            // Collect selected formats from checkboxes
            const formatCheckboxes = document.querySelectorAll('.videothumbnail-format-checkbox:checked');
            const selectedFormats = Array.from(formatCheckboxes).map(checkbox => checkbox.value);
            
            // Display debug info
            console.log('Selected formats:', selectedFormats);
            
            // Ensure at least one format is selected
            if (selectedFormats.length === 0) {
                e.preventDefault();
                alert('Please select at least one video format.');
                return false;
            }
            
            // Update the hidden field with the selected formats
            const hiddenField = document.getElementById('videothumbnail_supported_formats');
            if (hiddenField) {
                hiddenField.value = JSON.stringify(selectedFormats);
            }
            
            // Add a debug field to help troubleshoot
            const debugField = document.createElement('input');
            debugField.type = 'hidden';
            debugField.name = 'debug_info';
            debugField.value = JSON.stringify({
                timestamp: new Date().toISOString(),
                formats: selectedFormats,
                formAction: form.action,
                formMethod: form.method
            });
            form.appendChild(debugField);
            
            // Continue with form submission
            return true;
        });
        
        // Add helper for showing form data
        console.log('Form data will be submitted to:', form.action);
    });
})();