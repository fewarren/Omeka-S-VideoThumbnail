<?php
namespace VideoThumbnail\Site\BlockLayout;

use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Video Thumbnail block layout
 */
class VideoThumbnailBlock extends AbstractBlockLayout
{
    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;
    
    /**
     * Constructor
     *
     * @param \Omeka\Settings\Settings $settings
     */
    public function __construct($settings)
    {
        $this->settings = $settings;
    }
    
    /**
     * Write debug info directly to a file
     */
    private function debugLog($message) 
    {
        // Try to use a path we're sure will be writable 
        $logPath = __DIR__ . '/../../../../videothumbnail_block_debug.log';
        
        // Append to log file with timestamp
        $entry = date('Y-m-d H:i:s') . ' - ' . $message . "\n";
        @file_put_contents($logPath, $entry, FILE_APPEND);
    }
    
    /**
     * Return the block label.
     *
     * @return string
     */
    public function getLabel()
    {
        return 'Video Thumbnail'; // @translate
    }

    /**
     * Render the block form.
     *
     * @param PhpRenderer $view
     * @param SiteRepresentation $site
     * @param SitePageRepresentation|null $page
     * @param SitePageBlockRepresentation|null $block
     * @return string
     */
    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        ?SitePageRepresentation $page = null,
        ?SitePageBlockRepresentation $block = null
    ) {
        // Add explicit debug output to verify execution
        $this->debugLog('VideoThumbnailBlock form() called - ' . date('Y-m-d H:i:s'));
        
        // Get existing data from block
        $mediaId = $block ? $block->dataValue('media_id') : null;
        $framePercent = $block ? $block->dataValue('frame_percent', 10) : 10;
        $mediaTitle = '';
        $this->debugLog('Block data - mediaId: ' . ($mediaId ?: 'null') . ', framePercent: ' . $framePercent);
        
        // Get media title if a media is selected
        if ($mediaId) {
            try {
                $media = $view->api()->read('media', $mediaId)->getContent();
                $mediaTitle = $media->displayTitle();
                $this->debugLog('Media title loaded: ' . $mediaTitle);
            } catch (\Exception $e) {
                // Media not found or error loading
                $mediaTitle = $view->translate('Unknown media');
                $this->debugLog('Error loading media: ' . $e->getMessage());
            }
        }
        
        // Add required assets
        $view->headScript()->appendFile($view->assetUrl('js/video-thumbnail-block-admin.js', 'VideoThumbnail'));
        $view->headScript()->appendFile($view->assetUrl('js/resource-select.js', 'Omeka'));
        $this->debugLog('Scripts added to view');
        
        // Return form template
        $result = $view->partial('common/block-layout/video-thumbnail-form', [
            'mediaId' => $mediaId,
            'mediaTitle' => $mediaTitle,
            'framePercent' => $framePercent,
            'site' => $site,
        ]);
        
        $this->debugLog('Form template rendered, length: ' . strlen($result));
        return $result;
    }

    /**
     * Render the block.
     *
     * @param PhpRenderer $view
     * @param SitePageBlockRepresentation $block
     * @return string
     */
    public function render(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        $this->debugLog('VideoThumbnailBlock render() called');
        $mediaId = $block->dataValue('media_id');
        
        if (!$mediaId) {
            $this->debugLog('No media ID found in block');
            return $view->partial('common/block-layout/video-thumbnail', [
                'media' => null,
                'framePercent' => 10,
                'thumbnailHtml' => null,
            ]);
        }
        
        try {
            // Get the media
            $media = $view->api()->read('media', $mediaId)->getContent();
            
            // Get frame percentage
            $framePercent = $block->dataValue('frame_percent', 10);
            
            // Get thumbnail HTML
            $thumbnailHtml = $media->thumbnail();
            
            $this->debugLog('Successfully loaded media ' . $mediaId . ' and thumbnail');
            
            // Return block template
            return $view->partial('common/block-layout/video-thumbnail', [
                'media' => $media,
                'framePercent' => $framePercent,
                'thumbnailHtml' => $thumbnailHtml,
            ]);
        } catch (\Exception $e) {
            $this->debugLog('Error rendering block: ' . $e->getMessage());
            // Return error message if media could not be loaded
            return '<p class="error">' . $view->translate('Error loading video thumbnail') . '</p>';
        }
    }
}