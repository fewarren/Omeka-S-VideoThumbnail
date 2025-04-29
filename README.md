# Video Thumbnail Module for Omeka S

## Overview
This module enhances Omeka S with advanced video thumbnail generation capabilities. It allows users to extract and select custom thumbnails from video files using FFmpeg, with support for multiple video formats, batch processing, and a user-friendly interface.

## Features

### Core Features
- Extract thumbnails from multiple points in videos
- Interactive frame selection interface
- Support for multiple video formats
- Batch thumbnail regeneration
- Configurable frame extraction settings
- Memory-efficient processing
- Automatic error recovery
- Dark mode support
- Accessibility compliant

### Supported Video Formats
- MP4 (.mp4)
- WebM (.webm)
- QuickTime/MOV (.mov)
- AVI (.avi)
- WMV (.wmv)
- MKV (.mkv)
- 3GP (.3gp)
- 3G2 (.3g2)
- FLV (.flv)

## Requirements

### System Requirements
- Omeka S 3.0 or later
- PHP 7.4 or later
- FFmpeg installed and executable
- Sufficient disk space for temporary files
- GD or ImageMagick extension
- Write permissions for temporary directories

### PHP Configuration
- memory_limit: 512M recommended
- max_execution_time: 300 recommended
- upload_max_filesize: Adequate for your video files
- post_max_size: Larger than upload_max_filesize

## Installation

1. Download and unzip in your modules directory
2. Install FFmpeg if not already present
3. Enable the module in Omeka S admin interface
4. Configure FFmpeg path and other settings

### FFmpeg Installation
#### Linux (Debian/Ubuntu)
```bash
sudo apt-get update
sudo apt-get install ffmpeg
```

#### Windows
1. Download FFmpeg from https://ffmpeg.org/download.html
2. Extract to a permanent location
3. Add to system PATH or configure full path in module settings

## Configuration

### Basic Settings
- FFmpeg Path: Full path to FFmpeg executable
- Default Frame Position: Position for automatic thumbnail extraction (0-100%)
- Number of Frames: How many frames to extract for selection
- Memory Limit: Maximum memory allocation for processing

### Advanced Settings
- Debug Mode: Enable detailed logging (disabled by default)
- Log Level: Set logging detail level
- Supported Formats: Enable/disable specific video formats
- Process Timeout: Maximum processing time per video
- GC Probability: Controls garbage collection frequency (default: 10)

### Production Environment Settings
For production environments, the module uses these conservative defaults:
- Debug Mode: Disabled
- Memory Limit: 512MB
- GC Probability: 10

These settings can be adjusted in config/module.ini or through the admin interface.

## Usage

### Single Video Processing
1. Upload a video file
2. Edit the media item
3. Select "Choose Thumbnail"
4. Pick from extracted frames or generate new ones
5. Save selection

### Batch Processing
1. Go to Video Thumbnail admin page
2. Select videos to process
3. Choose processing options
4. Start batch operation
5. Monitor progress in job status

### Custom Frame Selection
1. Open video media item
2. Click "Select Frame"
3. Use slider or frame previews
4. Click desired frame
5. Confirm selection

## Advanced Features

### Debug Mode
Enable detailed logging for troubleshooting:
1. Set debug_mode = true in config
2. Check logs in OMEKA_PATH/logs
3. Use log level setting to control detail

### Custom Thumbnail Sizes
Configure in module.config.php:
```php
'thumbnail_options' => [
    'sizes' => [
        'large' => ['width' => 800, 'height' => 450],
        'medium' => ['width' => 400, 'height' => 225],
        'square' => ['width' => 200, 'height' => 200]
    ]
]
```

### Performance Optimization
1. Adjust memory_limit based on video size
2. Configure process timeout for large files
3. Use hardware acceleration if available
4. Enable temporary file cleanup

## API Integration

### REST API Endpoints
```
GET /api/video-thumbnail/info/:id
POST /api/video-thumbnail/extract/:id
POST /api/video-thumbnail/select/:id
GET /api/video-thumbnail/status/:id
```

### Event Hooks
- video_thumbnail.pre_extract
- video_thumbnail.post_extract
- video_thumbnail.pre_save
- video_thumbnail.post_save

## Troubleshooting

See [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for detailed guidance on:
- Common issues and solutions
- Error messages
- Debug procedures
- Performance optimization
- System compatibility

## Contributing

### Development Setup
1. Clone repository
2. Install dependencies
3. Configure development environment
4. Run tests

### Testing
Run PHPUnit tests:
```bash
cd tests
../vendor/bin/phpunit
```

### Coding Standards
- Follow PSR-12
- Add PHPDoc blocks
- Include unit tests
- Update documentation

## License
Released under the GNU General Public License v3.0.

## Credits
- FFmpeg for video processing
- Omeka S team for the platform
- Contributors and testers

## Support
- GitHub Issues for bug reports
- User Manual for documentation
- Community forums for discussion
