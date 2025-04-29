<?php
namespace VideoThumbnail\Site\BlockLayout;

use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Stdlib\ErrorStore;

class VideoThumbnailBlock extends AbstractBlockLayout
{
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;
    
    /**
     * Constructor
     *
     * @param \Laminas\Log\Logger $logger
     */
    /** @var \Laminas\ServiceManager\ServiceLocatorInterface */
    protected $serviceLocator;
    
    /**
     * Constructor
     *
     * @param \Laminas\Log\Logger $logger
     * @param \Laminas\ServiceManager\ServiceLocatorInterface $serviceLocator
     */
    public function __construct($logger = null, $serviceLocator = null)
    {
        $this->logger = $logger;
        $this->serviceLocator = $serviceLocator;
    }
    
    /**
     * Log a message if logger is available
     *
     * @param string $message
     * @param string $level
     */
    protected function log($message, $level = 'debug')
    {
        if ($this->logger) {
            if ($level == 'debug') {
                $this->logger->debug('[VideoThumbnail] ' . $message);
            } elseif ($level == 'info') {
                $this->logger->info('[VideoThumbnail] ' . $message);
            } elseif ($level == 'warn') {
                $this->logger->warn('[VideoThumbnail] ' . $message);
            } elseif ($level == 'err') {
                $this->logger->err('[VideoThumbnail] ' . $message);
            }
        } else {
            // Fallback to custom debug log
            \VideoThumbnail\Media\Ingester\VideoThumbnail::debugLog($message);
        }
    }
    
    /**
     * Get the service locator or try to find it through other means
     * 
     * @return \Laminas\ServiceManager\ServiceLocatorInterface|null
     */
    protected function getServices()
    {
        // First try: Use injected service locator
        if ($this->serviceLocator) {
            $this->log("Using injected service locator", 'debug');
            return $this->serviceLocator;
        }
        
        $services = null;
        
        // Second try: Get from Laminas Application
        if (class_exists('Laminas\\Mvc\\Application') && method_exists('Laminas\\Mvc\\Application', 'getInstance')) {
            try {
                $app = \Laminas\Mvc\Application::getInstance();
                if ($app && method_exists($app, 'getServiceManager')) {
                    $services = $app->getServiceManager();
                    $this->log("Got service manager from Laminas Application", 'debug');
                    return $services;
                }
            } catch (\Exception $e) {
                $this->log("Error getting service manager from Laminas: " . $e->getMessage(), 'err');
            }
        }
        
        // Third try: GLOBALS approach
        if (isset($GLOBALS['application']) && method_exists($GLOBALS['application'], 'getServiceManager')) {
            try {
                $services = $GLOBALS['application']->getServiceManager();
                $this->log("Got service manager from GLOBALS['application']", 'debug');
                return $services;
            } catch (\Exception $e) {
                $this->log("Error getting service manager from GLOBALS: " . $e->getMessage(), 'err');
            }
        }
        
        // Last resort - error
        $this->log("Could not get any service manager", 'err');
        error_log("VideoThumbnail: Could not access service manager");
        return null;
    }
    
    public function getLabel()
    {
        return 'Video Thumbnail'; // This is the name that appears in the "Add new block" menu
    }
    
    public function form(PhpRenderer $view, SiteRepresentation $site,
        SitePageRepresentation $page = null, SitePageBlockRepresentation $block = null
    ) {
        // Log debug information
        $mediaId = null;
        if ($block) {
            $mediaId = $block->dataValue('media_id');
            $this->log("Form method called with block ID: {$block->id()}, mediaId: {$mediaId}", 'debug');
        } else {
            $this->log("Form method called for new block", 'debug');
        }
        
        // Load required assets in prepareForm instead of here for Omeka S standards
        // Just add our additional CSS for styling
        try {
            $view->headLink()->appendStylesheet($view->assetUrl('css/block.css', 'VideoThumbnail'));
        } catch (\Exception $e) {
            $this->log('Error loading CSS: ' . $e->getMessage(), 'warn');
        }

        // Use the standard Omeka S block attachment form pattern
        return $view->partial('common/block-layout/video-thumbnail-form', [
            'block' => $block,
            'site' => $site
        ]);
    }
    
    public function render(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        $site = $block->page() ? $block->page()->site() : null;
        $attachments = $block->attachments();
        $media = null;
        
        // Add extra debugging for troubleshooting
        error_log("=== VideoThumbnail Render Start ===");
        error_log("Block ID: " . $block->id() . ", Data: " . json_encode($block->data()));
        error_log("Attachments count: " . count($attachments));
        $this->log("Render called for block ID: " . $block->id() . ", data: " . json_encode($block->data()), 'debug');
        
        // Try to get the media from attachments first (standard way)
        if (!empty($attachments)) {
            try {
                foreach ($attachments as $index => $attachment) {
                    if ($attachment->media()) {
                        $media = $attachment->media();
                        error_log("Found media in attachment #{$index}: " . $media->id() . ", " . $media->displayTitle());
                        $this->log("Loaded media from block attachment #{$index}: " . $media->id(), 'debug');
                        break;
                    }
                }
            } catch (\Exception $e) {
                error_log("Error loading media from attachment: " . $e->getMessage());
                $this->log("Error loading media from attachment: " . $e->getMessage(), 'err');
            }
        }
        
        // Fallback: Try to get media from the legacy media_id property if no attachments
        if (!$media) {
            $mediaId = $block->dataValue('media_id');
            if ($mediaId) {
                try {
                    error_log("Trying legacy media_id: {$mediaId}");
                    $this->log("Attempting to load media with legacy ID: {$mediaId}", 'debug');
                    $media = $view->api()->read('media', $mediaId)->getContent();
                    error_log("Successfully loaded legacy media: " . $media->id() . ", " . $media->displayTitle());
                    $this->log("Successfully loaded media using legacy method, ID: {$mediaId}, title: " . $media->displayTitle(), 'debug');
                } catch (\Exception $e) {
                    error_log("Error loading legacy media: " . $e->getMessage());
                    $this->log("Error loading media with legacy ID {$mediaId}: " . $e->getMessage(), 'err');
                }
            }
        }
        
        // Create an error notice if we have no media at this point
        if (!$media) {
            error_log("No media found for block ID: " . $block->id());
            $this->log("No media found for block ID: " . $block->id(), 'warn');
        } else {
            error_log("Successfully found media: " . $media->id() . ", title: " . $media->displayTitle());
            $this->log("Successfully found media: " . $media->id() . ", title: " . $media->displayTitle(), 'info');
            
            // Get media type for special handling of webm files
            $mediaType = '';
            if (method_exists($media, 'mediaType')) {
                $mediaType = $media->mediaType();
            } elseif (method_exists($media, 'getMediaType')) {
                $mediaType = $media->getMediaType();
            }
            
            $isWebm = (strpos($mediaType, 'video/webm') !== false);
            
            // Check if media has thumbnails and other properties
            $hasThumbnails = method_exists($media, 'hasThumbnails') ? $media->hasThumbnails() : false;
            error_log("Media has thumbnails: " . ($hasThumbnails ? 'Yes' : 'No'));
            
            if (method_exists($media, 'thumbnailUrl')) {
                $thumbnailUrl = $media->thumbnailUrl('square');
                error_log("Media thumbnail URL: " . ($thumbnailUrl ?: 'None'));
            }
            
            $needsRegeneration = !$hasThumbnails || $isWebm; // Always regenerate webm files
            
            // Regenerate thumbnail if needed or if it's a webm file
            if ($needsRegeneration) {
                $services = $this->getServices();
                if ($services) {
                    try {
                        error_log("Regenerating thumbnail for media ID: " . $media->id() . 
                                 ($isWebm ? " (webm format)" : ""));
                        $this->log("Regenerating thumbnail for media ID: " . $media->id() . 
                                  ($isWebm ? " (webm format)" : ""), 'info');
                        $entityManager = $services->get('Omeka\\EntityManager');
                        $settings = $services->get('Omeka\\Settings');
                        $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
                        $percent = $block->dataValue('percent', 10);
                        
                        // Create a fresh entity reference to avoid stale data
                        $mediaEntity = $entityManager->find('Omeka\\Entity\\Media', $media->id());
                        
                        if ($mediaEntity) {
                            error_log("Starting thumbnail generation...");
                            
                            // For webm files, try multiple thumbnails at different times if needed
                            $attempts = $isWebm ? 3 : 1;
                            $success = false;
                            
                            for ($i = 1; $i <= $attempts && !$success; $i++) {
                                $attemptPercent = $percent;
                                if ($isWebm && $i > 1) {
                                    // For additional attempts, try different positions
                                    $attemptPercent = $i == 2 ? 25 : 75;
                                    error_log("WebM retry attempt #$i, using percent: $attemptPercent");
                                }
                                
                                $success = \VideoThumbnail\Media\Ingester\VideoThumbnail::extractAndSaveThumbnail(
                                    $mediaEntity, $attemptPercent, $ffmpegPath, $entityManager
                                );
                                
                                if ($success) {
                                    error_log("Thumbnail generation successful on attempt #$i");
                                    break;
                                }
                            }
                            
                            error_log("Thumbnail generation final result: " . ($success ? 'Success' : 'Failed'));
                            
                            // Clear cache for the media object to force reload of thumbnail status
                            $entityManager->clear('Omeka\\Entity\\Media');
                            
                            // Refresh the API representation
                            try {
                                $media = $view->api()->read('media', $media->id())->getContent();
                                error_log("Media refreshed after thumbnail generation");
                            } catch (\Exception $refreshEx) {
                                error_log("Error refreshing media: " . $refreshEx->getMessage());
                            }
                        }
                    } catch (\Exception $e) {
                        error_log("Error regenerating thumbnail: " . $e->getMessage());
                        $this->log("Error regenerating thumbnail: " . $e->getMessage(), 'err');
                    }
                }
            }
        }
        
        error_log("=== VideoThumbnail Render End ===");
        
        // Check if we actually have a valid media object before proceeding
        if (!$media) {
            error_log("No valid media object found for rendering");
            return '<div class="video-thumbnail-block-error">No video selected. Please select a valid video media.</div>';
        }
        
        // Verify we have a thumbnail - this is critical
        $hasThumbnail = false;
        $thumbnailUrl = '';
        
        // Check multiple sizes to be thorough
        if (method_exists($media, 'thumbnailUrl')) {
            foreach (['large', 'medium', 'square'] as $size) {
                $url = $media->thumbnailUrl($size);
                if (!empty($url)) {
                    $thumbnailUrl = $url;
                    $hasThumbnail = true;
                    error_log("Found valid thumbnail URL for size {$size}: {$url}");
                    break;
                }
            }
        }
        
        // If we don't have a thumbnail after checking, try a last resort regeneration
        if (!$hasThumbnail && $media) {
            error_log("No thumbnails found for media ID {$media->id()}, attempting final regeneration");
            
            // Get services and try one more desperate attempt to generate thumbnails
            $services = $this->getServices();
            if ($services) {
                $entityManager = $services->get('Omeka\\EntityManager');
                $settings = $services->get('Omeka\\Settings');
                $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
                
                // Get a fresh entity reference
                $mediaEntity = $entityManager->find('Omeka\\Entity\\Media', $media->id());
                
                if ($mediaEntity) {
                    // Try at 10%, 25%, and 75% to be safe
                    foreach ([10, 25, 75] as $percent) {
                        error_log("Last-resort thumbnail generation, trying percent: {$percent}");
                        $success = \VideoThumbnail\Media\Ingester\VideoThumbnail::extractAndSaveThumbnail(
                            $mediaEntity, $percent, $ffmpegPath, $entityManager
                        );
                        
                        if ($success) {
                            error_log("Last-resort thumbnail generation succeeded at {$percent}%");
                            
                            // Clear cache and refresh the media representation
                            $entityManager->clear('Omeka\\Entity\\Media');
                            $media = $view->api()->read('media', $media->id())->getContent();
                            
                            // Check if we have thumbnails now
                            if (method_exists($media, 'thumbnailUrl')) {
                                $thumbnailUrl = $media->thumbnailUrl('large') ?: 
                                               $media->thumbnailUrl('medium') ?: 
                                               $media->thumbnailUrl('square');
                                
                                if (!empty($thumbnailUrl)) {
                                    $hasThumbnail = true;
                                    error_log("Successfully generated thumbnail after multiple attempts!");
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // If we still don't have a thumbnail, return helpful error with details
        if (!$hasThumbnail) {
            error_log("ERROR: Failed to generate thumbnail for media ID {$media->id()} ({$media->displayTitle()})");
            return '<div class="video-thumbnail-block-error">Could not generate thumbnail for this video. Please check that ffmpeg is installed correctly and the video file is accessible.</div>';
        }
        
        // Now that we're sure we have a media and thumbnail, render the partial
        $result = $view->partial('common/block-layout/video-thumbnail', [
            'media' => $media,
            'data' => $block->data(),
            'site' => $site,
            'block' => $block,
            // Pass the verified thumbnail URL directly to the template
            'verified_thumbnail_url' => $thumbnailUrl
        ]);
        
        return $result;
    }
    
    public function prepareForm(PhpRenderer $view)
    {
        $this->log('prepareForm: Start loading assets', 'debug');
        
        try {
            // Only load essentials to avoid conflicts with core Omeka S scripts
            if ($view->getHelperPluginManager()->has('assetUrl')) {
                // Load bare minimum JS needed for media selection
                $view->headScript()->appendFile($view->assetUrl('js/omeka.js', 'Omeka'));
                
                // Add our custom block script AFTER the core scripts
                $view->headScript()->appendFile($view->assetUrl('js/block.js', 'VideoThumbnail'));
                $view->headLink()->appendStylesheet($view->assetUrl('css/block.css', 'VideoThumbnail'));
                
                $this->log('prepareForm: Successfully loaded core assets and block assets', 'debug');
            } else {
                $this->log('prepareForm: assetUrl helper not available', 'warn');
            }
        } catch (\Exception $e) {
            $this->log('prepareForm: Error loading assets: ' . $e->getMessage(), 'err');
        }
    }
    
    public function prepareRender(PhpRenderer $view)
    {
        // No additional preparation needed for rendering
        $this->log('prepareRender called', 'debug');
    }
    
    public function onHydrate(\Omeka\Entity\SitePageBlock $block, \Omeka\Stdlib\ErrorStore $errorStore)
    {
        $data = $block->getData();
        $attachments = $block->getAttachments();
        $mediaId = null;
        $validAttachment = null;
        
        // Debug the incoming data and attachments
        $attachmentInfo = [];
        foreach ($attachments as $i => $attachment) {
            $mediaInfo = $attachment->getMedia() ? 'Media #' . $attachment->getMedia()->getId() : 'No media';
            $attachmentInfo[] = "Attachment {$i}: {$mediaInfo}";
        }
        $this->log("onHydrate called with data: " . print_r($data, true) . " and attachments: " . print_r($attachmentInfo, true), 'debug');
        
        // Check for media in attachments (standard Omeka S way)
        if (!empty($attachments)) {
            // Handle case where there are multiple attachments - keep only the first valid one
            if (count($attachments) > 1) {
                $this->log("Multiple attachments found! This block should only use one media item.", 'warn');
            }
            
            // Find the first valid attachment
            foreach ($attachments as $attachment) {
                if ($attachment->getMedia()) {
                    $mediaId = $attachment->getMedia()->getId();
                    $this->log("Found media ID {$mediaId} in block attachments", 'debug');
                    $validAttachment = $attachment;
                    
                    // Add media_id to data for backwards compatibility
                    if (!isset($data['media_id']) || $data['media_id'] != $mediaId) {
                        $data['media_id'] = $mediaId;
                        $block->setData($data);
                        $this->log("Updated block data with media_id {$mediaId} for backwards compatibility", 'debug');
                    }
                    break;
                }
            }
            
            // If there were multiple attachments, remove all but the valid one
            if (count($attachments) > 1 && $validAttachment) {
                // Create a new ArrayCollection with just the valid attachment
                $newAttachments = new \Doctrine\Common\Collections\ArrayCollection();
                $newAttachments->add($validAttachment);
                
                // Replace the block's attachments with our single-item collection
                $block->setAttachments($newAttachments);
                $this->log("Removed extra attachments, keeping only media ID {$mediaId}", 'info');
            }
        }
        
        // Fallback to the old approach if no media in attachments
        if (!$mediaId && isset($data['media_id']) && !empty($data['media_id'])) {
            $mediaId = $data['media_id'];
            $this->log("Using legacy media_id {$mediaId} from block data", 'debug');
            
            // If we have a media_id in the data but no attachments, create an attachment
            try {
                $services = $this->getServices();
                if ($services) {
                    $entityManager = $services->get('Omeka\\EntityManager');
                    $media = $entityManager->find('Omeka\\Entity\\Media', $mediaId);
                    
                    if ($media && empty($attachments)) {
                        // Create a new attachment with this media
                        $this->log("Creating attachment for legacy media_id {$mediaId}", 'debug');
                        $attachment = new \Omeka\Entity\SiteBlockAttachment();
                        $attachment->setBlock($block);
                        $attachment->setMedia($media);
                        $entityManager->persist($attachment);
                        $block->getAttachments()->add($attachment);
                    }
                }
            } catch (\Exception $e) {
                $this->log("Error creating attachment for legacy media: " . $e->getMessage(), 'err');
            }
        }
        
        // Skip validation for any block - we'll handle media selection dynamically
        // This prevents the "A media item must be selected" error
        $this->log("onHydrate: Media ID found: {$mediaId}", 'debug');
        
        $this->log("onHydrate: Processing block with media ID {$mediaId}", 'info');
        
        // Only proceed if we have a media ID
        if ($mediaId) {
            try {
                // Log the hydration process
                $this->log("Starting thumbnail extraction in onHydrate for media ID {$mediaId}, percent {$data['percent']}", 'info');
                
                // Get services
                $services = $this->getServices();
                if (!$services) {
                    throw new \Exception("Could not access service manager");
                }
                
                // Get the entity manager and media
                $entityManager = $services->get('Omeka\\EntityManager');
                $media = $entityManager->find('Omeka\\Entity\\Media', $mediaId);
                
                if (!$media) {
                    throw new \Exception("Media with ID {$mediaId} not found");
                }
                
                $settings = $services->get('Omeka\\Settings');
                $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
                
                // We must extract thumbnails - always process the media if it's present
                // This ensures thumbnails are regenerated even when re-selecting a media
                // or changing the percent value
                $percent = isset($data['percent']) && is_numeric($data['percent']) ? $data['percent'] : 10;
                $this->log("Using ffmpeg path: $ffmpegPath with percent: $percent", 'debug');
                \VideoThumbnail\Media\Ingester\VideoThumbnail::extractAndSaveThumbnail($media, $percent, $ffmpegPath, $entityManager);
                $this->log("Completed thumbnail extraction in onHydrate", 'info');
            } catch (\Exception $e) {
                $message = 'Exception in onHydrate thumbnail extraction: ' . $e->getMessage();
                $this->log($message, 'err');
            }
        }
    }
}