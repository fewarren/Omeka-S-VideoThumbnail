<?php
namespace VideoThumbnail\Media\Ingester;

use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\File\TempFileFactory;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Media\Ingester\MutableIngesterInterface;
use Omeka\Stdlib\ErrorStore;
use Laminas\Form\Element\File;
use Laminas\Http\PhpEnvironment\UploadProgressData;
use Laminas\View\Renderer\PhpRenderer;
use VideoThumbnail\Stdlib\VideoFrameExtractor;
use Doctrine\ORM\EntityManager;
use Laminas\Log\LoggerInterface; // Import LoggerInterface

class VideoThumbnail implements MutableIngesterInterface, IngesterInterface
{
    /**
     * @var TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var array
     */
    protected $settings;

    /**
     * @var VideoFrameExtractor
     */
    protected $videoFrameExtractor;

    /**
     * @var object
     */
    protected $uploader;

    /**
     * @var object
     */
    protected $fileStore;

    /**
     * @var EntityManager
     */
    protected $entityManager;

    /** @var LoggerInterface */
    protected $logger; // Add logger property

    /**
     * @param TempFileFactory $tempFileFactory
     * @param $settings
     * @param VideoFrameExtractor $videoFrameExtractor
     * @param $uploader
     * @param $fileStore
     * @param EntityManager $entityManager
     * @param LoggerInterface $logger // Add logger to constructor
     */
    public function __construct(
        TempFileFactory $tempFileFactory, 
        $settings, 
        VideoFrameExtractor $videoFrameExtractor,
        $uploader,
        $fileStore,
        EntityManager $entityManager,
        LoggerInterface $logger // Add logger to constructor
    )
    {
        $this->tempFileFactory = $tempFileFactory;
        $this->settings = $settings;
        $this->videoFrameExtractor = $videoFrameExtractor;
        $this->uploader = $uploader;
        $this->fileStore = $fileStore;
        $this->entityManager = $entityManager;
        $this->logger = $logger; // Assign logger
    }

    /**
     * Get the label for the ingester.
     *
     * @return string
     */
    public function getLabel()
    {
        return 'Video Thumbnail'; // @translate
    }

    /**
     * Create a new media entity from an uploaded file.
     *
     * @param Media $media
     * @param Request $request
     * @param ErrorStore $errorStore
     * @return bool
     */
    public function ingest(Media $media, Request $request, ErrorStore $errorStore)
    {
        $this->logger->info(sprintf('VideoThumbnail Ingester: Starting ingestion for media ID %d', $media->getId()), ['method' => __METHOD__]);

        $data = $request->getContent();
        $fileData = $request->getFileData();
        
        if (!isset($fileData['file'])) {
            $errorStore->addError('error', 'No file was uploaded');
            return false;
        }

        $file = $fileData['file'];
        $tempPath = $file['tmp_name'];
        $originalFilename = $file['name'];

        try {
            // Validate the video file
            $this->validateVideo($tempPath, $originalFilename);

            // Get video duration for validation
            $duration = $this->videoFrameExtractor->getVideoDuration($tempPath);
            if ($duration <= 0) {
                throw new \RuntimeException('Unable to process video file. The file may be corrupted or in an unsupported format.');
            }

            // Extract initial thumbnail with fallback positions
            $frameTime = $duration * 0.1; // Start at 10% of duration
            $tempFile = $this->extractThumbnailWithFallback($tempPath, $frameTime, $duration);

            if (!$tempFile) {
                throw new \RuntimeException('Failed to extract thumbnail from video');
            }

            // Store the original video file
            $tempFile = $this->tempFileFactory->build();
            $tempFile->setSourceName($originalFilename);
            $tempFile->setTempPath($file['tmp_name']);
            
            $store = $this->store;
            $storagePath = $store->put($tempFile);
            if (!$storagePath) {
                throw new \RuntimeException('Failed to store video file');
            }

            // Set the storage ID and data
            $media->setStorageId($storagePath);
            $media->setExtension(pathinfo($originalFilename, PATHINFO_EXTENSION));
            $media->setMediaType($file['type']);
            $media->setData([
                'video_metadata' => [
                    'duration' => $duration,
                    'original_name' => $originalFilename,
                    'size' => $file['size'],
                    'type' => $file['type']
                ]
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->err(sprintf('VideoThumbnail Ingester Error: %s', $e->getMessage()), [
                'method' => __METHOD__,
                'media_id' => $media->getId(),
                'exception' => $e
            ]);
            $errorStore->addError('error', $e->getMessage());
            return false;
        }
    }

    /**
     * Check if media type is a supported video format
     *
     * @param string $mediaType
     * @return bool
     */
    protected function isVideoMedia($mediaType)
    {
        return in_array($mediaType, [
            'video/mp4',          // MP4 files
            'video/quicktime',    // MOV files
            'video/x-msvideo',    // AVI files
            'video/x-ms-wmv',     // WMV files
            'video/x-matroska',   // MKV files
            'video/webm',         // WebM files
            'video/3gpp',         // 3GP files
            'video/3gpp2',        // 3G2 files
            'video/x-flv'         // FLV files
        ]);
    }

    /**
     * Extract a frame and set it as the thumbnail
     *
     * @param string $filePath
     * @param Media $media
     */
    protected function extractAndSetDefaultThumbnail($filePath, Media $media)
    {
        // Initialize VideoThumbnail debugging
        \VideoThumbnail\Stdlib\Debug::logEntry(__METHOD__, ['filePath' => $filePath]);
        
        try {
            // Log video file info for debugging
            $fileInfo = [
                'size' => filesize($filePath),
                'readable' => is_readable($filePath),
                'extension' => strtolower(pathinfo($filePath, PATHINFO_EXTENSION)),
                'mime' => function_exists('mime_content_type') ? mime_content_type($filePath) : 'unknown'
            ];
            \VideoThumbnail\Stdlib\Debug::log("Video file info: " . json_encode($fileInfo), __METHOD__);
            
            // Get video duration with sanity check
            $duration = $this->videoFrameExtractor->getVideoDuration($filePath);
            if ($duration <= 0) {
                \VideoThumbnail\Stdlib\Debug::logError('Could not determine video duration, using fallback value', __METHOD__);
                $duration = 20; // Use a more reasonable fallback (20s instead of 60s)
            }
            
            // Use default frame setting (percentage of the video)
            $defaultFrame = intval($this->settings->get('videothumbnail_default_frame', 10));
            $defaultFrame = max(1, min(90, $defaultFrame)); // Ensure sane value (1-90%)
            $frameTime = ($duration * $defaultFrame) / 100;
            
            // For very short clips or when we're using the fallback duration, make sure we're not too close to the end
            if ($duration <= 5 || $duration === 20) {
                $frameTime = min($frameTime, $duration * 0.75); // No more than 75% into the video
                \VideoThumbnail\Stdlib\Debug::log("Adjusted frame time to {$frameTime}s for short video", __METHOD__);
            }
            
            // For MOV files, use a slightly longer timeout
            $timeout = 15;
            if ($fileInfo['extension'] === 'mov') {
                $timeout = max(20, min(30, intval($fileInfo['size'] / 1048576))); // Scale with file size
                \VideoThumbnail\Stdlib\Debug::log("Using extended timeout of {$timeout}s for MOV file", __METHOD__);
            }
            
            \VideoThumbnail\Stdlib\Debug::log("Extracting frame at {$frameTime}s ({$defaultFrame}% of {$duration}s)", __METHOD__);
            
            // Extract the frame
            $tempFile = $this->videoFrameExtractor->extractFrame($filePath, $frameTime, $timeout);
            
            // If first attempt failed, try another position
            if (!$tempFile || !file_exists($tempFile) || filesize($tempFile) <= 0) {
                \VideoThumbnail\Stdlib\Debug::log("First frame extraction failed, trying earlier position", __METHOD__);
                
                // Try earlier in the video (25% of duration)
                $earlierFrameTime = $duration * 0.25;
                $tempFile = $this->videoFrameExtractor->extractFrame($filePath, $earlierFrameTime, $timeout);
                
                // If that fails too, try the very beginning
                if (!$tempFile || !file_exists($tempFile) || filesize($tempFile) <= 0) {
                    \VideoThumbnail\Stdlib\Debug::log("Second frame extraction failed, trying start of video", __METHOD__);
                    $tempFile = $this->videoFrameExtractor->extractFrame($filePath, 1.0, $timeout);
                }
            }
            
            if ($tempFile && file_exists($tempFile) && filesize($tempFile) > 0) {
                \VideoThumbnail\Stdlib\Debug::log("Frame extracted successfully to {$tempFile}", __METHOD__);
                
                try {
                    // Generate thumbnails for the media
                    $tempFileObj = $this->tempFileFactory->build();
                    $tempFileObj->setSourceName('thumbnail.jpg');
                    $tempFileObj->setTempPath($tempFile);
                    
                    // Need to get the thumbnails stored via Omeka's core system
                    // Since we can't use getServiceLocator() anymore, we'll use a direct approach
                    try {
                        // Let Omeka's FileManager handle the thumbnail creation
                        // This is a simple approach that avoids the need for the service locator
                        
                        // Copy the extracted frame to the media's storage location as a thumbnail
                        $thumbnailTypes = ['large', 'medium', 'square'];
                        $hasThumbnails = true;
                        
                        // Store thumbnail at each standard size location
                        foreach ($thumbnailTypes as $type) {
                            $storagePath = sprintf('%s/%s.jpg', $type, $media->getStorageId());
                            try {
                                $this->fileStore->put($tempFile, $storagePath);
                                \VideoThumbnail\Stdlib\Debug::log("Stored $type thumbnail at $storagePath", __METHOD__);
                            } catch (\Exception $e) {
                                \VideoThumbnail\Stdlib\Debug::logError("Failed to store $type thumbnail: " . $e->getMessage(), __METHOD__);
                                $hasThumbnails = false;
                            }
                        }
                        
                        // Set thumbnails flag
                        $media->setHasThumbnails($hasThumbnails);
                    } catch (\Exception $e) {
                        \VideoThumbnail\Stdlib\Debug::logError('Failed to store thumbnails: ' . $e->getMessage(), __METHOD__);
                        $hasThumbnails = false;
                    }
                    
                    // Get existing data to preserve other fields
                    $mediaData = $media->getData() ?: [];
                    
                    // Update with the new thumbnail info
                    $mediaData['video_duration'] = $duration;
                    $mediaData['thumbnail_frame_time'] = $frameTime;
                    $mediaData['thumbnail_frame_percentage'] = $defaultFrame;
                    $mediaData['videothumbnail_frame'] = $frameTime;
                    
                    // Set the updated data back to the media
                    $media->setData($mediaData);
                    
                    // Make sure the thumbnails are properly linked in the database
                    $this->updateThumbnailStoragePaths($media);
                    
                    \VideoThumbnail\Stdlib\Debug::log("Thumbnail set successfully", __METHOD__);
                } catch (\Exception $e) {
                    \VideoThumbnail\Stdlib\Debug::logError('Failed to set thumbnail: ' . $e->getMessage(), __METHOD__);
                } finally {
                    // Always clean up the temp file
                    if (file_exists($tempFile)) {
                        @unlink($tempFile);
                        \VideoThumbnail\Stdlib\Debug::log("Temp file removed", __METHOD__);
                    }
                }
            } else {
                \VideoThumbnail\Stdlib\Debug::logError('All frame extraction attempts failed or produced invalid files', __METHOD__);
            }
        } catch (\Exception $e) {
            \VideoThumbnail\Stdlib\Debug::logError('Exception in thumbnail extraction: ' . $e->getMessage(), __METHOD__);
        }
        
        \VideoThumbnail\Stdlib\Debug::logExit(__METHOD__);
    }

    /**
     * Get the form elements required to configure ingest of a file.
     *
     * @param PhpRenderer $view
     * @param array $options
     * @return string
     */
    public function form(PhpRenderer $view, array $options = [])
    {
        \VideoThumbnail\Stdlib\Debug::logEntry(__METHOD__);
        
        // Create a proper File element for the file upload
        // The name needs to be 'file[file]' to match Omeka's expected structure
        $fileElement = new \Laminas\Form\Element\File('file[file]');
        $fileElement->setAttributes([
            'class' => 'videothumbnail-file-input',
            'id' => 'videothumbnail-file-input',
            'required' => true,
        ]);
        
        // Create a hidden field to ensure file_index is always submitted
        $hiddenIndexElement = new \Laminas\Form\Element\Hidden('file_index');
        $hiddenIndexElement->setValue('0');
        $hiddenIndexElement->setAttributes([
            'id' => 'videothumbnail-file-index',
        ]);
        
        // Combine the element output
        $formMarkup = $view->formFile($fileElement) . $view->formHidden($hiddenIndexElement);
        
        // Add instruction text for clarity
        $formMarkup .= '<p class="video-thumbnail-help">' . $view->translate('Upload a video file (MP4, MOV, etc.)') . '</p>';
        
        \VideoThumbnail\Stdlib\Debug::logExit(__METHOD__);
        return $formMarkup;
    }

    /**
     * Get the form elements used to edit a media after ingest.
     *
     * @param PhpRenderer $view
     * @param \Omeka\Api\Representation\MediaRepresentation $media
     * @param array $options
     * @return string
     */
    public function updateForm(PhpRenderer $view, \Omeka\Api\Representation\MediaRepresentation $media, array $options = [])
    {
        \VideoThumbnail\Stdlib\Debug::logEntry(__METHOD__);
        
        // Create a proper File element for the file upload
        // The name needs to be 'file[file]' to match Omeka's expected structure
        $fileElement = new \Laminas\Form\Element\File('file[file]');
        $fileElement->setAttributes([
            'class' => 'videothumbnail-file-input',
            'id' => 'videothumbnail-file-input-update',
        ]);
        
        // Create a hidden field to ensure file_index is always submitted
        $hiddenIndexElement = new \Laminas\Form\Element\Hidden('file_index');
        $hiddenIndexElement->setValue('0');
        $hiddenIndexElement->setAttributes([
            'id' => 'videothumbnail-file-index-update',
        ]);
        
        // Combine the element output
        $formMarkup = $view->formFile($fileElement) . $view->formHidden($hiddenIndexElement);
        
        // Add instruction text for clarity
        $formMarkup .= '<p class="video-thumbnail-help">' . $view->translate('Replace with a different video file (optional)') . '</p>';
        
        \VideoThumbnail\Stdlib\Debug::logExit(__METHOD__);
        return $formMarkup;
    }

    /**
     * @param Media $media
     * @param Request $request
     * @param ErrorStore $errorStore
     * @return bool
     */
    public function update(Media $media, Request $request, ErrorStore $errorStore)
    {
        $fileData = $request->getValue('file');
        
        // Log the actual file data to troubleshoot
        \VideoThumbnail\Stdlib\Debug::log("Update raw file data: " . json_encode($fileData), __METHOD__);
        
        // Check if a file was provided for update - if not, just return success
        if (empty($fileData)) {
            return true;
        }
        
        // If direct file structure is found
        if (isset($fileData['error'])) {
            if ($fileData['error'] === UPLOAD_ERR_NO_FILE) {
                // No new file uploaded, this is okay for updates
                return true;
            }
            else if ($fileData['error'] !== UPLOAD_ERR_OK) {
                // Error with file upload
                $errorStore->addError('file', 'File upload error during update: ' . $fileData['error']);
                \VideoThumbnail\Stdlib\Debug::logError("File upload error during update: " . $fileData['error'], __METHOD__);
                return false;
            }
            else if (empty($fileData['name'])) {
                // No filename
                return true;
            }
        }
        // If nested file structure is found 
        else if (isset($fileData['file'])) {
            if (isset($fileData['file']['error'])) {
                if ($fileData['file']['error'] === UPLOAD_ERR_NO_FILE) {
                    // No new file uploaded, this is okay for updates
                    return true;
                }
                else if ($fileData['file']['error'] !== UPLOAD_ERR_OK) {
                    // Error with file upload
                    $errorStore->addError('file', 'File upload error during update: ' . $fileData['file']['error']);
                    \VideoThumbnail\Stdlib\Debug::logError("File upload error during update: " . $fileData['file']['error'], __METHOD__);
                    return false;
                }
            }
            
            // If no filename, consider it as no update needed
            if (empty($fileData['file']['name'])) {
                return true;
            }
        }
        else {
            // Unknown file structure but empty, just continue
            return true;
        }

        // Ensure file_index is provided in the request
        $request = clone $request;
        $requestData = $request->getContent();
        
        // If no file index is specified, default to index 0
        if (!isset($requestData['file_index'])) {
            $requestData['file_index'] = 0;
            $request->setContent($requestData);
        }

        \VideoThumbnail\Stdlib\Debug::log("Updating video with file data: " . json_encode(array_keys($fileData)), __METHOD__);

        // Update leverages the standard file ingester
        // Use the directly injected uploader service
        $fileIngester = new \Omeka\Media\Ingester\Upload($this->uploader);
        if (!$fileIngester->update($media, $request, $errorStore)) {
            \VideoThumbnail\Stdlib\Debug::logError("Standard file update failed", __METHOD__);
            return false;
        }

        // Now extract frame for thumbnail if it's a video
        $mediaType = $media->getMediaType();
        if ($this->isVideoMedia($mediaType)) {
            $extension = pathinfo($media->getFilename(), PATHINFO_EXTENSION);
            $storagePath = $this->getStoragePath('original', $media->getStorageId(), $extension);
            $filePath = $this->fileStore->getLocalPath($storagePath);
            $this->extractAndSetDefaultThumbnail($filePath, $media);
        }

        return true;
    }
    
    /**
     * Update the storage paths for thumbnails in the database
     *
     * @param \Omeka\Entity\Media $media The media entity to update
     * @return void
     */
    protected function updateThumbnailStoragePaths($media)
    {
        try {
            \VideoThumbnail\Stdlib\Debug::log(sprintf('Updating thumbnail storage paths for media %d', $media->getId()), __METHOD__);
            
            $storageId = $media->getStorageId();
            
            // Standard Omeka S thumbnail sizes
            $thumbnailTypes = ['large', 'medium', 'square'];
            
            foreach ($thumbnailTypes as $type) {
                // Construct expected path for this thumbnail type 
                $storagePath = $this->getStoragePath($type, $storageId, 'jpg');
                
                // Update thumbnail info in database if needed
                \VideoThumbnail\Stdlib\Debug::log(sprintf('Ensuring thumbnail path exists for %s: %s', $type, $storagePath), __METHOD__);
                
                // Force re-association of thumbnail with media
                $localPath = $this->fileStore->getLocalPath($storagePath);
                if (file_exists($localPath)) {
                    \VideoThumbnail\Stdlib\Debug::log(sprintf('Thumbnail file exists for %s: %s', $type, $storagePath), __METHOD__);
                    
                    // Force database to recognize the thumbnail paths
                    $mediaId = $media->getId();
                    $connection = $this->entityManager->getConnection();
                    
                    try {
                        // Update the media entity's has_thumbnails flag directly
                        $stmt = $connection->prepare('UPDATE media SET has_thumbnails = 1 WHERE id = :id');
                        $stmt->bindValue('id', $mediaId, \PDO::PARAM_INT);
                        $stmt->execute();
                        
                        \VideoThumbnail\Stdlib\Debug::log(sprintf('Updated has_thumbnails flag for media %d', $mediaId), __METHOD__);
                    } catch (\Exception $e) {
                        \VideoThumbnail\Stdlib\Debug::logError(sprintf('Database update error: %s', $e->getMessage()), __METHOD__);
                    }
                } else {
                    \VideoThumbnail\Stdlib\Debug::logError(sprintf('Thumbnail file not found: %s', $storagePath), __METHOD__);
                }
            }
            
            // Also make sure original/thumbnails flags are set properly
            $media->setHasThumbnails(true);
            $this->entityManager->persist($media);
            $this->entityManager->flush();
            
            \VideoThumbnail\Stdlib\Debug::log(sprintf('Thumbnail storage paths updated for media %d', $media->getId()), __METHOD__);
        } catch (\Exception $e) {
            \VideoThumbnail\Stdlib\Debug::logError(sprintf('Error updating thumbnail paths: %s', $e->getMessage()), __METHOD__);
        }
    }
    
    /**
     * Get a storage path.
     *
     * @param string $prefix The storage prefix (e.g., 'original', 'thumbnail')
     * @param string $storageId The unique storage ID of the media
     * @param string $extension Optional file extension
     * @return string The constructed storage path
     */
    protected function getStoragePath(string $prefix, string $storageId, string $extension = ''): string
    {
        return sprintf('%s/%s%s', $prefix, $storageId, strlen($extension) ? '.' . $extension : '');
    }
    
    protected function validateVideo($filePath, $originalName)
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException('Video file does not exist or is not readable');
        }

        // Validate file extension
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $validExtensions = ['mp4', 'mov', 'avi', 'wmv', 'mkv', 'webm', '3gp', '3g2', 'flv'];
        
        if (!in_array($extension, $validExtensions)) {
            throw new \RuntimeException(sprintf(
                'Invalid video file extension. Supported extensions are: %s',
                implode(', ', $validExtensions)
            ));
        }

        // Validate MIME type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath);
        
        if (strpos($mimeType, 'video/') !== 0) {
            throw new \RuntimeException(sprintf(
                'Invalid file type. Expected video file, got %s',
                $mimeType
            ));
        }

        // Validate file size
        $maxSize = 2147483648; // 2GB
        $fileSize = filesize($filePath);
        
        if ($fileSize > $maxSize) {
            throw new \RuntimeException(sprintf(
                'File size exceeds maximum allowed size of %s GB',
                $maxSize / 1073741824
            ));
        }

        return true;
    }

    protected function extractThumbnailWithFallback($videoPath, $initialTime, $duration)
    {
        // Try positions at 10%, 25%, and start of video
        $positions = [
            $initialTime,
            $duration * 0.25,
            1.0
        ];

        foreach ($positions as $timePosition) {
            try {
                $framePath = $this->videoFrameExtractor->extractFrame($videoPath, $timePosition);
                if ($framePath && file_exists($framePath) && filesize($framePath) > 0) {
                    return $framePath;
                }
            } catch (\Exception $e) {
                \VideoThumbnail\Stdlib\Debug::logError(
                    sprintf('Failed to extract frame at position %.2f: %s', $timePosition, $e->getMessage()),
                    __METHOD__
                );
                continue;
            }
        }

        return null;
    }
    
    /**
     * Get the renderer for this ingester.
     *
     * @return string
     */
    public function getRenderer()
    {
        return 'videothumbnail';
    }

    /**
     * Get the local path to the original media file.
     *
     * @param Media $media
     * @return string|null
     */
    protected function getOriginalFilePath(Media $media): ?string
    {
        $storageId = $media->getStorageId();
        $extension = $media->getExtension();
        $filename = $media->getFilename();

        if (!$filename && $storageId && $extension) {
            // Reconstruct filename if missing (might happen in some edge cases)
            $filename = $storageId . '.' . $extension;
            $this->logger->warn(sprintf('Reconstructed filename for media ID %d as %s', $media->getId(), $filename), ['method' => __METHOD__]);
        }

        if (!$filename) {
            $this->logger->err('Cannot determine filename for media.', ['method' => __METHOD__, 'media_id' => $media->getId()]);
            return null;
        }

        // Assuming 'original' is the standard directory for original files
        $storagePath = 'original' . DIRECTORY_SEPARATOR . $filename;
        
        try {
            $localPath = $this->fileStore->getLocalPath($storagePath);
            if ($localPath && file_exists($localPath)) {
                return $localPath;
            }
            $this->logger->warn(sprintf('Original file not found at expected local path: %s', $localPath ?: '(null)'), ['method' => __METHOD__, 'storage_path' => $storagePath]);
        } catch (\Exception $e) {
            $this->logger->err(sprintf('Error getting local path for storage path "%s": %s', $storagePath, $e->getMessage()), ['method' => __METHOD__]);
        }

        // Fallback: Try finding based on storage ID if filename path failed
        if ($storageId) {
            try {
                $localPathById = $this->fileStore->getLocalPath($storageId);
                if ($localPathById && file_exists($localPathById)) {
                    $this->logger->info(sprintf('Found original file using storage ID path: %s', $localPathById), ['method' => __METHOD__]);
                    return $localPathById;
                }
            } catch (\Exception $e) {
                 $this->logger->warn(sprintf('Error getting local path via storage ID "%s": %s', $storageId, $e->getMessage()), ['method' => __METHOD__]);
            }
        }

        $this->logger->err('Failed to locate the original file path for media.', ['method' => __METHOD__, 'media_id' => $media->getId()]);
        return null;
    }
}
