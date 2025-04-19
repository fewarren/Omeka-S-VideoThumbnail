# VideoThumbnail Plugin Troubleshooting

This document provides solutions for common issues with the VideoThumbnail plugin for Omeka-S.

## Debug Mode

The plugin includes a debug mode that can be enabled from the admin settings page. When enabled, detailed logs will be written to the Omeka-S error log, which is typically stored in `/var/log/apache2/omeka-s_error.log`.

## Common Issues

### Job Scheduling Failures

If the scheduled batch job for video thumbnail generation fails:

1. **Check for job failures**: Review the job log in the Omeka-S admin panel (Jobs menu)
2. **Debug logging**: Enable debug mode in the plugin settings for more detailed logs
3. **PHP CLI availability**: Ensure that PHP CLI is available and properly configured
4. **Memory limits**: Consider increasing PHP memory_limit in your php.ini
5. **Timeouts**: The plugin now has fallback mechanisms if jobs time out
6. **FFmpeg path**: Verify FFmpeg is accessible by the PHP CLI user (not just the web server)

### Plugin Hanging or Taking Too Long

If the plugin seems to hang or takes too long when generating thumbnails:

1. Make sure FFmpeg is properly installed and accessible at the path specified in settings
2. Enable debug mode to log detailed information about FFmpeg processes
3. Lower the timeout values in the VideoFrameExtractor class if needed
4. Check for FFmpeg errors in the error log

### Debug Messages Not Appearing

If debug messages aren't appearing in the error log:

1. Make sure debug mode is explicitly enabled in the plugin settings
2. Verify that Apache has permission to write to the log directory
3. Check that your PHP logging configuration is set correctly
4. Restart Apache after making configuration changes:
   ```
   sudo systemctl restart apache2
   ```

### 500 Server Errors

If you encounter 500 server errors when accessing the VideoThumbnail admin page:

1. Enable debug mode to get detailed error information
2. Check the Apache error logs at `/var/log/apache2/error.log` and `/var/log/apache2/omeka-s_error.log`
3. Ensure all PHP classes are properly defined and files are not truncated
4. Verify FFmpeg path settings

## FFmpeg Compatibility

This plugin requires FFmpeg to be installed on your server. To verify FFmpeg is working:

1. Run `ffmpeg -version` from the command line
2. Ensure the path in the plugin settings matches the FFmpeg executable
3. Check that the Apache/PHP process has permission to execute FFmpeg

If issues persist, check your system's FFmpeg logs or try updating FFmpeg to the latest version.

## Job System Troubleshooting

The plugin now uses a more robust job execution system that:

1. Uses Omeka S's PHP CLI strategy as a fallback
2. Provides better error logging and handling
3. Manages memory more effectively during batch processing
4. Can be interrupted safely without corrupting the system
5. Handles FFmpeg timeouts better to prevent server hangs

+### Common Error Messages
+
+* `FFmpeg process timed out` - This indicates that the video processing took longer than expected. Try increasing timeouts or processing a smaller video.
+* `PHP CLI not available` - Ensure PHP is installed and available in the command line on your server.
+* `Cannot allocate memory` - Increase PHP memory_limit setting in your php.ini file.

If job scheduling continues to fail after these improvements, please report the exact error message from your logs.
