# Omeka S VideoThumbnail Module

## Features
- Extracts and manages thumbnails for video files in Omeka S using ffmpeg
- Admin configuration for default thumbnail percent and ffmpeg path
- Batch job to regenerate all video thumbnails
- Block for site pages: select video, set percent, display thumbnail
- User-friendly, Omeka S-consistent UI

## Installation
1. Copy this module folder to your Omeka S `modules` directory.
2. Install and activate the module from the Omeka S admin interface.
3. Ensure ffmpeg is installed and accessible on your server (Windows: add to PATH or specify full path).

## Configuration
- Go to **Admin > Video Thumbnail** to set the default percent and ffmpeg path.
- Optionally, run the batch job to regenerate all thumbnails.

## Usage
- Add the **Video Thumbnail** block to a site page.
- Select a video from your Omeka S media library.
- Set the percent into the video for the thumbnail.
- The block will display the generated thumbnail.

## Troubleshooting
- Check Omeka and server logs for errors if thumbnails are not generated.
- Ensure ffmpeg is installed and the path is correct.
- Make sure Omeka has write permissions to its files directory.

## License
MIT
