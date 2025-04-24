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
        // Get existing data from block
        $mediaId = $block ? $block->dataValue('media_id') : null;
        $framePercent = $block ? $block->dataValue('frame_percent', 10) : 10;
        $mediaTitle = '';
        
        // Get media title if a media is selected
        if ($mediaId) {
            try {
                $media = $view->api()->read('media', $mediaId)->getContent();
                $mediaTitle = $media->displayTitle();
            } catch (\Exception $e) {
                // Media not found or error loading
                $mediaTitle = $view->translate('Unknown media');
            }
        }
        
        // Add our script - Omeka will load its dependencies
        $view->headScript()->appendFile($view->assetUrl('js/video-thumbnail-block-admin.js', 'VideoThumbnail'));
        
        // Return form template
        return $view->partial('common/block-layout/video-thumbnail-form', [
            'mediaId' => $mediaId,
            'mediaTitle' => $mediaTitle,
            'framePercent' => $framePercent,
        ]);
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
        $mediaId = $block->dataValue('media_id');
        
        if (!$mediaId) {
            return ''; // Return empty if no media selected
        }
        
        try {
            // Get the media
            $media = $view->api()->read('media', $mediaId)->getContent();
            
            // Get frame percentage
            $framePercent = $block->dataValue('frame_percent', 10);
            
            // Get thumbnail HTML
            $thumbnailHtml = $media->thumbnail();
            
            // Return block template
            return $view->partial('common/block-layout/video-thumbnail', [
                'media' => $media,
                'framePercent' => $framePercent,
                'thumbnailHtml' => $thumbnailHtml,
            ]);
        } catch (\Exception $e) {
            // Return error message if media could not be loaded
            return '<p class="error">' . $view->translate('Error loading video thumbnail') . '</p>';
        }
    }
}