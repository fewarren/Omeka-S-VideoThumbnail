<?php
namespace VideoThumbnail\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\View\Model\JsonModel;
use Omeka\Stdlib\Message;
use VideoThumbnail\Form\ConfigBatchForm;
// Don't use Debug in controller initialization
// use VideoThumbnail\Stdlib\Debug;

class VideoThumbnailController extends AbstractActionController
{
    protected $entityManager;
    protected $fileManager;
    protected $settings;
    protected $serviceLocator;

    public function __construct($entityManager = null, $fileManager = null, $serviceLocator = null)
    {
        $this->entityManager = $entityManager;
        $this->fileManager = $fileManager;
        $this->serviceLocator = $serviceLocator;
        
        // Simple error_log instead of Debug
        error_log('VideoThumbnail: Controller initialized');
    }

    public function setSettings($settings)
    {
        $this->settings = $settings;
        // Remove Debug call
        return $this;
    }

    public function indexAction()
    {
        // Remove all Debug calls from initialization
        try {
            $form = new ConfigBatchForm();
            $form->init();

            $defaultSupportedFormats = [
                'video/mp4',         // MP4 files
                'video/webm',        // WebM files
                'video/quicktime',   // MOV files
                'video/x-msvideo',   // AVI files
                'video/x-ms-wmv',    // WMV files
                'video/x-matroska',  // MKV files
                'video/3gpp',        // 3GP files
                'video/3gpp2',        // 3G2 files
                'video/x-flv'         // FLV files
            ];
            
            // Get supported formats setting
            $storedFormats = null;
            try {
                $storedFormats = $this->settings->get('videothumbnail_supported_formats');
            } catch (\Exception $e) {
                error_log('VideoThumbnail: Failed to get videothumbnail_supported_formats: ' . $e->getMessage());
            }
            
            // Apply default if needed
            $supportedFormats = $storedFormats;
            if (!is_array($supportedFormats) || empty($supportedFormats)) {
                $supportedFormats = $defaultSupportedFormats;
            }
            
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error initializing form: ' . $e->getMessage());
            
            // Fallback to create basic form
            $form = new ConfigBatchForm();
            $form->init();
            $supportedFormats = ['video/mp4', 'video/quicktime'];
        }

        // Set settings with error handling
        try {
            // Get debug mode setting
            $debugMode = false;
            try {
                $debugMode = (bool)$this->settings->get('videothumbnail_debug_mode', false);
            } catch (\Exception $e) {
                error_log('VideoThumbnail: Failed to get videothumbnail_debug_mode: ' . $e->getMessage());
                $debugMode = false; // Default to false for stability
            }
            
            // Get default frame setting
            $defaultFrame = 10;
            try {
                $defaultFrame = (int)$this->settings->get('videothumbnail_default_frame', 10);
            } catch (\Exception $e) {
                error_log('VideoThumbnail: Failed to get videothumbnail_default_frame: ' . $e->getMessage());
            }
            
            // Get timestamp property setting
            $timestampProperty = null;
            try {
                $timestampProperty = $this->settings->get('videothumbnail_timestamp_property');
            } catch (\Exception $e) {
                error_log('VideoThumbnail: Failed to get videothumbnail_timestamp_property: ' . $e->getMessage());
            }
            
            // Prepare form data
            $formData = [
                'default_frame_position' => $defaultFrame,
                'supported_formats' => $supportedFormats,
                'debug_mode' => $debugMode,
            ];
            
            // Only add timestamp property if set
            if ($timestampProperty) {
                $formData['timestamp_property'] = $timestampProperty;
            }
            
            $form->setData($formData);
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error setting form data: ' . $e->getMessage());
            
            // Use defaults if settings access fails
            $form->setData([
                'default_frame_position' => 10,
                'supported_formats' => $supportedFormats,
                'debug_mode' => false, // Default to false for stability
            ]);
        }

        $request = $this->getRequest();
        if ($request->isPost()) {
            $postData = $request->getPost()->toArray();
            
            $form->setData($postData);
            if ($form->isValid()) {
                $formData = $form->getData();
                
                try {
                    // Save settings without detailed debugging
                    $this->settings->set('videothumbnail_default_frame', $formData['default_frame_position']);
                    $this->settings->set('videothumbnail_supported_formats', $formData['supported_formats']);
                    $this->settings->set('videothumbnail_debug_mode', !empty($formData['debug_mode']));
                    
                    // Only update timestamp property if it exists in form data
                    if (isset($formData['timestamp_property'])) {
                        $this->settings->set('videothumbnail_timestamp_property', $formData['timestamp_property']);
                    }
                    
                    $this->messenger()->addSuccess('Video thumbnail settings updated.'); // Success message
                } catch (\Exception $e) {
                    error_log('VideoThumbnail: Failed to save settings: ' . $e->getMessage());
                    $this->messenger()->addError('Failed to save video thumbnail settings: ' . $e->getMessage()); // Error message
                }
            } else {
                $messages = $form->getMessages();
                $this->messenger()->addError('There was an error during form validation. Please check the form and try again.'); // Form validation error
            }
        }

        try {
            $totalVideos = $this->getTotalVideos();
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error getting total videos: ' . $e->getMessage());
            $totalVideos = 0;
        }
        
        $view = new ViewModel();
        $view->setVariable('form', $form);
        $view->setVariable('totalVideos', $totalVideos);
        $view->setVariable('supportedFormats', implode(', ', $supportedFormats));
        
        $view->setTemplate('video-thumbnail/admin/video-thumbnail/index');
        
        return $view;
    }

    protected function getTotalVideos()
    {
        try {
            // Check if entity manager is available
            if (!$this->entityManager) {
                error_log('VideoThumbnail: Entity manager is not available');
                return 0;
            }
            
            // Get the repository
            $repository = $this->entityManager->getRepository('Omeka\Entity\Media');
            
            // Query for the total number of videos based on supported formats
            $defaultSupportedFormats = [
                'video/mp4',          // MP4 files
                'video/quicktime',    // MOV files
                'video/x-msvideo',    // AVI files
                'video/x-ms-wmv',     // WMV files
                'video/x-matroska',   // MKV files
                'video/webm',         // WebM files
                'video/3gpp',         // 3GP files
                'video/3gpp2',        // 3G2 files
                'video/x-flv'         // FLV files
            ];
            $supportedFormats = $this->settings ? 
                $this->settings->get('videothumbnail_supported_formats', $defaultSupportedFormats) : 
                $defaultSupportedFormats;
                
            if (!is_array($supportedFormats) || empty($supportedFormats)) {
                $supportedFormats = $defaultSupportedFormats;
            }
            
            $queryBuilder = $repository->createQueryBuilder('media');
            $queryBuilder->select('COUNT(media.id)')
                        ->where($queryBuilder->expr()->in('media.mediaType', ':formats'))
                        ->setParameter('formats', $supportedFormats);

            $result = (int) $queryBuilder->getQuery()->getSingleScalarResult();
            
            return $result;
            
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error in getTotalVideos: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Action to save a specific frame as the thumbnail
     * 
     * @return JsonModel Response with success/error status
     */
    public function saveFrameAction()
    {
        // Conditional debug logging that's safe
        $debugMode = false;
        try {
            if ($this->settings) {
                $debugMode = (bool)$this->settings->get('videothumbnail_debug_mode', false);
            }
        } catch (\Exception $e) {
            // Ignore settings errors
        }
        
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin');
        }

        $mediaId = $this->params()->fromPost('media_id');
        $position = $this->params()->fromPost('position');
        
        // Validate mediaId
        if (empty($mediaId) || !is_numeric($mediaId) || (int)$mediaId <= 0) {
            return new JsonModel([
                'success' => false,
                'message' => 'Invalid media ID parameter',
            ]);
        }
        
        // Validate position
        if (!is_numeric($position)) {
            return new JsonModel([
                'success' => false,
                'message' => 'Position must be a numeric value',
            ]);
        }
        
        // Ensure position is within valid range (0-100)
        $position = max(0, min(100, (float)$position));
        
        try {
            $media = $this->api()->read('media', $mediaId)->getContent();
            
            if (!$media) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'Media not found',
                ]);
            }
            
            // Validate media type
            if (strpos($media->mediaType(), 'video/') !== 0) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'Media is not a video file',
                ]);
            }
            
            // Extract and save the frame
            $result = $this->extractAndSaveFrame($media, $position);
            
            return new JsonModel($result);
            
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Frame save error: ' . $e->getMessage());
            return new JsonModel([
                'success' => false,
                'message' => 'Error saving frame',
                'details' => $e->getMessage()
            ]);
        }
    }

    protected function extractAndSaveFrame($media, $position)
    {
        try {
            $fileStore = $this->serviceLocator->get('Omeka\File\Store');
            $extractor = $this->serviceLocator->get('VideoThumbnail\VideoFrameExtractor');
            
            $storagePath = sprintf('original/%s', $media->filename());
            $filePath = $fileStore->getLocalPath($storagePath);
            
            // Get video duration
            $duration = $extractor->getVideoDuration($filePath);
            
            if ($duration <= 0) {
                $duration = 5.0;
            }
            
            // Calculate frame time
            $timeInSeconds = ($duration * $position) / 100;
            $timeInSeconds = max(0.1, min($timeInSeconds, $duration - 0.1));
            
            // Extract the frame
            $framePath = $extractor->extractFrame($filePath, $timeInSeconds);
            if (!$framePath) {
                return [
                    'success' => false,
                    'message' => 'Failed to extract frame'
                ];
            }
            
            // Store the frame
            $result = $this->storeThumbnail($media, $framePath, $position, $timeInSeconds);
            
            return $result;
            
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Frame extraction error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error processing frame: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Store an extracted video frame as a thumbnail
     * 
     * @param object $media The media representation
     * @param string $framePath Path to the extracted frame
     * @param float $position The position percentage in the video
     * @param float $timeInSeconds The time in seconds
     * @return array Result with success/error status
     */
    /**
     * Store an extracted video frame as a thumbnail
     * 
     * @param object $media The media representation
     * @param string $framePath Path to the extracted frame
     * @param float $position The position percentage in the video
     * @param float $timeInSeconds The time in seconds
     * @return array Result with success/error status
     */
    protected function storeThumbnail($media, $framePath, $position, $timeInSeconds)
    {
        try {
            // Verify we have the file storage
            if (!$this->fileManager) {
                throw new \RuntimeException('File storage not available');
            }
            
            // Get the media entity
            $mediaEntity = $this->entityManager->find('Omeka\Entity\Media', $media->id());
            if (!$mediaEntity) {
                return [
                    'success' => false,
                    'message' => 'Media entity not found'
                ];
            }
            
            // Create a temp file using Omeka's proper API
            $tempFileFactory = $this->serviceLocator->get('Omeka\File\TempFileFactory');
            $tempFile = $tempFileFactory->build();
            
            // Copy the frame to temp file
            if (!copy($framePath, $tempFile->getTempPath())) {
                return [
                    'success' => false,
                    'message' => 'Failed to process frame'
                ];
            }
            
            // Try to get proper file manager if available
            $fileManager = null;
            $hasThumbnails = false;
            
            try {
                if ($this->serviceLocator->has('Omeka\File\Manager')) {
                    $fileManager = $this->serviceLocator->get('Omeka\File\Manager');
                    // Generate thumbnails using the file manager directly
                    $storageId = $mediaEntity->getStorageId();
                    $hasThumbnails = $fileManager->storeThumbnails($tempFile->getTempPath(), $storageId);
                } else {
                    // Fallback: manually generate thumbnail sizes
                    $hasThumbnails = $this->manuallyCreateThumbnails($tempFile->getTempPath(), $mediaEntity->getStorageId());
                }
            } catch (\Exception $e) {
                // Fallback if there was an error
                error_log('VideoThumbnail: Error with FileManager: ' . $e->getMessage() . ' - using fallback');
                $hasThumbnails = $this->manuallyCreateThumbnails($tempFile->getTempPath(), $mediaEntity->getStorageId());
            }
            
            // Update media data
            $mediaEntity->setHasThumbnails($hasThumbnails);
            $mediaData = $mediaEntity->getData() ?: [];
            $mediaData['videothumbnail_frame_percentage'] = $position;
            $mediaData['videothumbnail_frame_time'] = $timeInSeconds;
            $mediaEntity->setData($mediaData);
            
            // Save changes
            $this->entityManager->persist($mediaEntity);
            $this->entityManager->flush();
            
            // Cleanup
            $tempFile->delete();
            @unlink($framePath);
            
            return [
                'success' => true,
                'message' => 'Thumbnail frame updated successfully'
            ];
            
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error storing thumbnail: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error storing thumbnail: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Manually create thumbnails when FileManager is not available
     * 
     * @param string $sourcePath Path to source image
     * @param string $storageId Storage ID for the media
     * @return bool True if successful
     */
    protected function manuallyCreateThumbnails($sourcePath, $storageId)
    {
        $thumbnailTypes = ['large', 'medium', 'square'];
        $thumbnailSizes = [
            'large' => [800, 800],
            'medium' => [400, 400],
            'square' => [200, 200, true] // Square thumbnail
        ];
        
        try {
            // Make sure image is readable
            if (!is_readable($sourcePath)) {
                error_log('VideoThumbnail: Source image not readable: ' . $sourcePath);
                return false;
            }
            
            // Use GD to resize the image
            $sourceImg = imagecreatefromjpeg($sourcePath);
            if (!$sourceImg) {
                error_log('VideoThumbnail: Failed to create image from source');
                return false;
            }
            
            $sourceDimensions = [imagesx($sourceImg), imagesy($sourceImg)];
            $success = true;
            
            foreach ($thumbnailTypes as $type) {
                $width = $thumbnailSizes[$type][0];
                $height = $thumbnailSizes[$type][1];
                $square = isset($thumbnailSizes[$type][2]) ? $thumbnailSizes[$type][2] : false;
                
                // Calculate dimensions for resize
                $sourceRatio = $sourceDimensions[0] / $sourceDimensions[1];
                
                if ($square) {
                    // For square thumbnails, crop to square
                    $targetWidth = $targetHeight = $width;
                    
                    // Create new image
                    $targetImg = imagecreatetruecolor($targetWidth, $targetHeight);
                    
                    // Calculate crop dimensions
                    if ($sourceRatio > 1) {
                        $cropHeight = $sourceDimensions[1];
                        $cropWidth = $cropHeight;
                        $cropX = floor(($sourceDimensions[0] - $cropWidth) / 2);
                        $cropY = 0;
                    } else {
                        $cropWidth = $sourceDimensions[0];
                        $cropHeight = $cropWidth;
                        $cropX = 0;
                        $cropY = floor(($sourceDimensions[1] - $cropHeight) / 2);
                    }
                    
                    // Resize and crop
                    imagecopyresampled(
                        $targetImg, $sourceImg,
                        0, 0, $cropX, $cropY,
                        $targetWidth, $targetHeight, $cropWidth, $cropHeight
                    );
                } else {
                    // For non-square thumbnails, maintain aspect ratio
                    if ($sourceRatio > ($width / $height)) {
                        $targetWidth = $width;
                        $targetHeight = round($width / $sourceRatio);
                    } else {
                        $targetHeight = $height;
                        $targetWidth = round($height * $sourceRatio);
                    }
                    
                    // Create target image
                    $targetImg = imagecreatetruecolor($targetWidth, $targetHeight);
                    
                    // Resize
                    imagecopyresampled(
                        $targetImg, $sourceImg,
                        0, 0, 0, 0,
                        $targetWidth, $targetHeight, $sourceDimensions[0], $sourceDimensions[1]
                    );
                }
                
                // Create temp file for the resized image
                $tempResized = tempnam(sys_get_temp_dir(), 'thumb');
                imagejpeg($targetImg, $tempResized, 85);
                imagedestroy($targetImg);
                
                // Define storage path
                $storagePath = sprintf('%s/%s.jpg', $type, $storageId);
                
                // Store using file storage
                try {
                    $this->fileManager->put($tempResized, $storagePath);
                } catch (\Exception $e) {
                    error_log('VideoThumbnail: Failed to store ' . $type . ' thumbnail: ' . $e->getMessage());
                    $success = false;
                }
                
                // Cleanup
                @unlink($tempResized);
            }
            
            imagedestroy($sourceImg);
            return $success;
            
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Manual thumbnail creation error: ' . $e->getMessage());
            return false;
        }
    }

    protected function validateAndExtractFrame($media, $position = null)
    {
        $fileStore = $this->serviceLocator->get('Omeka\File\Store');
        $extractor = $this->serviceLocator->get('VideoThumbnail\VideoFrameExtractor');
        $settings = $this->settings;

        try {
            $storagePath = sprintf('original/%s', $media->filename());
            $filePath = $fileStore->getLocalPath($storagePath);

            if (!file_exists($filePath) || !is_readable($filePath)) {
                throw new \RuntimeException('Video file not found or not readable');
            }

            // Validate video format
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($filePath);
            if (strpos($mimeType, 'video/') !== 0) {
                throw new \RuntimeException('Invalid file type: ' . $mimeType);
            }

            // Get video duration with enhanced error checking
            $duration = $extractor->getVideoDuration($filePath);
            if ($duration <= 0) {
                // For very small files, use minimum duration
                if (filesize($filePath) < 1048576) { // Less than 1MB
                    $duration = 1.0;
                } else {
                    throw new \RuntimeException('Could not determine video duration');
                }
            }

            // Calculate frame position
            if ($position === null) {
                $defaultPosition = (float)$settings->get('videothumbnail_default_frame', 10);
                $position = max(0, min(100, $defaultPosition));
            }

            $timeInSeconds = ($duration * $position) / 100;
            $timeInSeconds = max(0.1, min($timeInSeconds, $duration - 0.1));

            // Extract frame with multiple fallback attempts
            $framePath = $this->extractFrameWithFallback($extractor, $filePath, $timeInSeconds, $duration);
            
            if (!$framePath) {
                throw new \RuntimeException('Failed to extract frame from video');
            }

            return [
                'success' => true,
                'framePath' => $framePath,
                'position' => $position,
                'duration' => $duration
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    protected function extractFrameWithFallback($extractor, $filePath, $timeInSeconds, $duration)
    {
        // First attempt at specified position
        $framePath = $extractor->extractFrame($filePath, $timeInSeconds);
        if ($this->isValidFrame($framePath)) {
            return $framePath;
        }

        // Try at 25% of duration
        $earlierTime = $duration * 0.25;
        $framePath = $extractor->extractFrame($filePath, $earlierTime);
        if ($this->isValidFrame($framePath)) {
            return $framePath;
        }

        // Try at start of video
        $framePath = $extractor->extractFrame($filePath, 1.0);
        if ($this->isValidFrame($framePath)) {
            return $framePath;
        }

        return null;
    }

    protected function isValidFrame($framePath)
    {
        return $framePath && file_exists($framePath) && filesize($framePath) > 0;
    }

    /**
     * Action to display frame selection interface
     * 
     * @return ViewModel|Response View model or redirect response
     */
    public function selectFrameAction()
    {
        $id = $this->params('id');
        
        // Validate ID parameter
        if (empty($id) || !is_numeric($id) || (int)$id <= 0) {
            $this->messenger()->addError('Invalid media ID');
            return $this->redirect()->toRoute('admin');
        }
        
        try {
            $media = $this->api()->read('media', (int)$id)->getContent();
            
            // Validate media exists and is a video
            if (!$media) {
                $this->messenger()->addError('Media not found');
                return $this->redirect()->toRoute('admin');
            }
            
            if (strpos($media->mediaType(), 'video/') !== 0) {
                $this->messenger()->addError('Media is not a video file');
                return $this->redirect()->toRoute('admin');
            }
            
            // Validate services
            if (!$this->serviceLocator) {
                throw new \RuntimeException('Service locator is not available');
            }
            
            $fileStore = $this->serviceLocator->get('Omeka\File\Store');
            $extractor = $this->serviceLocator->get('VideoThumbnail\VideoFrameExtractor');
                
            // Construct and validate storage path
            $filename = $media->filename();
            if (empty($filename)) {
                throw new \RuntimeException('Media has no filename');
            }
            
            $storagePath = sprintf('original/%s', $filename);
            $filePath = $fileStore->getLocalPath($storagePath);
            
            if (!file_exists($filePath) || !is_readable($filePath)) {
                throw new \RuntimeException('Video file not accessible');
            }
            
            // Get video duration
            $duration = $extractor->getVideoDuration($filePath);
            if ($duration <= 0) {
                $duration = 1.0; // Use a minimal duration for very short videos
            }
            
            // Get number of frames to extract (with validation)
            $frameCount = (int)$this->settings->get('videothumbnail_frames_count', 5);
            $frameCount = max(1, min(20, $frameCount)); // Limit between 1-20 frames
            
            // Extract frames
            $extractedFrames = $extractor->extractFrames($filePath, $frameCount);
            
            // Validate extracted frames
            if (empty($extractedFrames)) {
                throw new \RuntimeException('No frames could be extracted');
            }
            
            // Prepare frame data for the view
            $frames = [];
            foreach ($extractedFrames as $index => $framePath) {
                // Validate frame path
                if (!file_exists($framePath) || !is_readable($framePath)) {
                    continue; // Skip invalid frames
                }
                
                // Create a URL that can be accessed by the browser
                $tempFilename = 'temp-' . basename($framePath);
                $tempFilePath = OMEKA_PATH . '/files/temp/' . $tempFilename;
                
                // Copy the frame to the Omeka temp directory for web access
                if (!copy($framePath, $tempFilePath)) {
                    continue; // Skip if copy fails
                }
                
                $frameUrl = $this->url()->fromRoute('asset', [
                    'asset' => $tempFilename,
                ]);
                
                // Calculate the position as a percentage of video duration
                $percentPosition = ($index + 1) * (100 / ($frameCount + 1));
                $percentPosition = max(0, min(100, $percentPosition)); // Ensure 0-100 range
                $timeInSeconds = ($duration * $percentPosition) / 100;
                
                // Add frame data
                $frames[] = [
                    'image' => $frameUrl,
                    'time' => $timeInSeconds,
                    'percent' => $percentPosition,
                ];
                
                // Clean up the original frame
                @unlink($framePath);
            }
            
            // Ensure we have frames to display
            if (empty($frames)) {
                throw new \RuntimeException('Failed to prepare frames for display');
            }
            
            $view = new ViewModel();
            $view->setVariable('media', $media);
            $view->setVariable('frameCount', $frameCount);
            $view->setVariable('duration', $duration);
            $view->setVariable('frames', $frames);
            return $view;
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error loading media: ' . $e->getMessage());
            $this->messenger()->addError('Error loading media: ' . $e->getMessage());
            return $this->redirect()->toRoute('admin');
        }
    }

    public function generateFramesAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin');
        }
        
        $mediaId = $this->params()->fromPost('media_id');
        
        if (!$mediaId) {
            return new JsonModel([
                'success' => false,
                'message' => 'No media ID provided',
            ]);
        }
        
        try {
            $media = $this->api()->read('media', $mediaId)->getContent();
            
            if (!$media || strpos($media->mediaType(), 'video/') !== 0) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'This media is not a supported video format.',
                ]);
            }

            // Get services and settings
            $ffmpegPath = $this->settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
            $frameCount = $this->settings->get('videothumbnail_frames_count', 5);
            
            $fileStore = $this->serviceLocator->get('Omeka\File\Store');
            $storagePath = sprintf('original/%s', $media->filename());
            $filePath = $fileStore->getLocalPath($storagePath);
            
            // Get video duration with enhanced logging
            $extractor = $this->serviceLocator->get('VideoThumbnail\VideoFrameExtractor');
            $duration = $extractor->getVideoDuration($filePath);
            
            if ($duration <= 0) {
                $duration = 5.0;
            }
            
            // Extract frames with progress logging
            $frames = $extractor->extractFrames($filePath, $frameCount);
            
            $framePaths = [];
            foreach ($frames as $index => $framePath) {
                $tempFilename = 'temp-' . basename($framePath);
                $tempFilePath = OMEKA_PATH . '/files/temp/' . $tempFilename;
                
                if (copy($framePath, $tempFilePath)) {
                    $frameUrl = $this->url()->fromRoute('asset', ['asset' => $tempFilename]);
                    $percentPosition = ($index + 1) * (100 / ($frameCount + 1));
                    $timeInSeconds = ($duration * $percentPosition) / 100;
                    
                    $framePaths[] = [
                        'index' => $index,
                        'path' => $frameUrl,
                        'position' => $percentPosition,
                        'time' => $timeInSeconds,
                        'original_path' => $framePath,
                    ];
                }
            }
            
            return new JsonModel([
                'success' => true,
                'frames' => $framePaths,
            ]);
            
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Frame generation error: ' . $e->getMessage());
            return new JsonModel([
                'success' => false,
                'message' => 'Error generating frames. Check logs for details.',
                'details' => $e->getMessage()
            ]);
        }
    }
}