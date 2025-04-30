# Video Thumbnail Module Troubleshooting Guide

## Common Issues and Solutions

### FFmpeg Issues

#### FFmpeg Not Found
**Symptoms:**
- Error message: "FFmpeg not found or not executable"
- No thumbnails are generated

**Solutions:**
1. Verify FFmpeg is installed:
   ```bash
   ffmpeg -version
   ```
2. Check the FFmpeg path in module configuration
3. Ensure the FFmpeg executable has proper permissions
4. On Windows, make sure to use the full path with .exe extension

#### FFmpeg Permission Errors
**Symptoms:**
- Error message: "Permission denied"
- Thumbnail generation fails

**Solutions:**
1. Check FFmpeg executable permissions
2. Verify web server user has execute permissions
3. On Windows, run as administrator if needed

### Thumbnail Generation Issues

#### No Frames Generated
**Symptoms:**
- Error message: "Failed to extract any frames"
- Empty frame selection interface

**Solutions:**
1. Check video file permissions
2. Verify video format is supported
3. Increase memory limit in module settings
4. Check logs for specific FFmpeg errors
5. Try different frame positions

#### Poor Quality Thumbnails
**Symptoms:**
- Blurry or pixelated thumbnails
- Incorrect aspect ratio

**Solutions:**
1. Increase quality settings in configuration
2. Verify video source quality
3. Check for proper video codec support
4. Adjust frame selection position

### Performance Issues

#### Slow Processing
**Symptoms:**
- Long wait times for thumbnail generation
- Timeout errors

**Solutions:**
1. Reduce number of frame extractions
2. Increase PHP timeout settings
3. Optimize memory limit settings
4. Use hardware acceleration if available
5. Check server load

#### Memory Errors
**Symptoms:**
- "Out of memory" errors
- Process termination

**Solutions:**
1. Increase PHP memory_limit
2. Adjust module memory settings
3. Enable debug mode for detailed logs
4. Process fewer frames simultaneously
5. Check for memory leaks in logs

### Storage Issues

#### Missing Thumbnails
**Symptoms:**
- Thumbnails not appearing after generation
- "File not found" errors

**Solutions:**
1. Check storage directory permissions
2. Verify temp directory exists and is writable
3. Clear temporary files
4. Check file system quotas
5. Validate storage paths

#### Temp File Cleanup
**Symptoms:**
- Disk space warnings
- Accumulating temporary files

**Solutions:**
1. Enable automatic cleanup
2. Run manual cleanup:
   ```bash
   rm -rf files/temp/video-thumbnails/*
   ```
3. Schedule periodic cleanup
4. Monitor disk usage

### Debug Mode

#### Enabling Debug Mode
1. Set 'videothumbnail_debug_mode' to true in settings
2. Check logs in `OMEKA_PATH/logs/videothumbnail.log`
3. Set appropriate log level:
   - error: Only errors
   - warning: Errors and warnings
   - info: General operation info
   - debug: Detailed debugging info

#### Reading Debug Logs
Debug logs contain:
- Timestamp
- Log level
- Process ID
- Memory usage
- Method name
- Detailed message

Example:
```
[2025-04-20 10:15:30] [DEBUG] [PID:1234] [MEM:256MB] [extractFrame] Starting frame extraction for video.mp4
```

### Integration Issues

#### Media Type Detection
**Symptoms:**
- Videos not recognized
- "Unsupported format" errors

**Solutions:**
1. Verify MIME type detection
2. Add format to supported formats list
3. Check file extension mapping
4. Update media type configuration

#### Database Synchronization
**Symptoms:**
- Inconsistent thumbnail status
- Missing database entries

**Solutions:**
1. Run database synchronization
2. Check media entity status
3. Verify thumbnail flags
4. Clear cache if needed

## Advanced Troubleshooting

### Command Line Tools

#### FFmpeg Diagnostic Commands
```bash
# Check FFmpeg capabilities
ffmpeg -formats
ffmpeg -codecs

# Test frame extraction
ffmpeg -i video.mp4 -ss 00:00:10 -vframes 1 test.jpg
```

#### Debug Mode CLI Tools
```bash
# Check log file
tail -f logs/videothumbnail.log

# Monitor process status
ps aux | grep ffmpeg
```

### Configuration Validation

#### Testing Configuration
1. Verify config file syntax
2. Check for path consistency
3. Validate service configuration
4. Test file permissions

#### Performance Tuning
1. Monitor resource usage
2. Adjust batch processing size
3. Optimize frame extraction
4. Configure caching

## Getting Help

### Support Resources
1. Check GitHub issues
2. Review documentation
3. Enable debug logging
4. Contact maintainers

### Reporting Issues
1. Enable debug mode
2. Collect relevant logs
3. Document reproduction steps
4. Provide system information:
   - PHP version
   - FFmpeg version
   - OS details
   - File permissions