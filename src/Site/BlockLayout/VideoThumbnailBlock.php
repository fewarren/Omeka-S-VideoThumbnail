<?php
namespace VideoThumbnail\Site\BlockLayout;

use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\MediaRepresentation; // Added
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\Form\Form; // Optional: If you need a configuration form for the block
use VideoThumbnail\Form\VideoThumbnailBlockForm; // Added: Assuming this is your form class
use Omeka\Site\BlockLayout\BlockLayoutInterface; // Add this use statement
use VideoThumbnail\Stdlib\Debug; // Added for debug integration

/**
 * Represents the Video Thumbnail block for public site pages.
 */
class VideoThumbnailBlock extends AbstractBlockLayout implements BlockLayoutInterface // Add implements clause
{
    /**
     * Returns the display label for the video thumbnail block layout.
     *
     * @return string The label shown in the "Add new block" dropdown.
     */
    public function getLabel()
    {
        // This is the name that appears in the "Add new block" dropdown
        return 'Video Thumbnail'; // @translate
    }

    /**
     * Generates the configuration form HTML for the video thumbnail block layout.
     *
     * Returns a form allowing users to select a media item and specify the frame percentage for the video thumbnail. If a media ID is provided, attempts to load and display the media title; otherwise, displays "Unknown media".
     *
     * @param \Laminas\View\Renderer\PhpRenderer $view
     * @param \Omeka\Api\Representation\SiteRepresentation $site
     * @param \Omeka\Api\Representation\SitePageRepresentation|null $page
     * @param \Omeka\Api\Representation\SitePageBlockRepresentation|null $block
     * @return string The rendered HTML form for block configuration, or an empty string if no form is needed.
     */
    public function form(
        \Laminas\View\Renderer\PhpRenderer $view,
        \Omeka\Api\Representation\SiteRepresentation $site,
        ?\Omeka\Api\Representation\SitePageRepresentation $page = null,
        ?\Omeka\Api\Representation\SitePageBlockRepresentation $block = null
    ) {
        Debug::log('Rendering video thumbnail block form', __METHOD__, [
            'siteId' => $site ? $site->id() : null,
            'pageId' => $page ? $page->id() : null,
            'blockId' => $block ? $block->id() : null
        ]);
        
        $mediaId = $block ? ($block->dataValue('media_id')) : null;
        $mediaTitle = '';
        $framePercent = $block ? $block->dataValue('frame_percent') : 10;
        
        Debug::log(sprintf(
            'Block form parameters: mediaId=%s, framePercent=%s',
            $mediaId ?: 'null',
            $framePercent
        ), __METHOD__);
        
        if ($mediaId) {
            try {
                $media = $view->api()->read('media', $mediaId)->getContent();
                $mediaTitle = $media->displayTitle();
                Debug::log(sprintf('Found media: %s (ID: %d)', $mediaTitle, $mediaId), __METHOD__);
            } catch (\Exception $e) {
                $mediaTitle = $view->translate('Unknown media');
                Debug::logError(sprintf(
                    'Error loading media ID %d: %s',
                    $mediaId,
                    $e->getMessage()
                ), __METHOD__);
            }
        }
        
        return $view->partial('common/block-layout/video-thumbnail-form', [
            'block' => $block,
            'mediaId' => $mediaId,
            'mediaTitle' => $mediaTitle,
            'framePercent' => $framePercent,
        ]);
    }

    /**
     * Renders the video thumbnail block for display on a public site page.
     *
     * Retrieves the associated media entity and generates the HTML output for the video thumbnail block. If the media cannot be loaded or is not specified, returns an empty string or an error message.
     *
     * @param PhpRenderer $view
     * @param SitePageBlockRepresentation $block
     * @return string HTML output for the video thumbnail block, or an error message if rendering fails.
     */
    public function render(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        $mediaId = $block->dataValue('media_id');
        $framePercent = $block->dataValue('frame_percent');
        
        Debug::log(sprintf(
            'Rendering video thumbnail block: mediaId=%s, framePercent=%s',
            $mediaId ?: 'null',
            $framePercent
        ), __METHOD__, [
            'blockId' => $block->id(),
            'mediaId' => $mediaId,
            'framePercent' => $framePercent
        ]);
        
        if (!$mediaId) {
            Debug::logWarning('No media ID provided for video thumbnail block', __METHOD__, [
                'blockId' => $block->id()
            ]);
            return ''; // Return empty if no media selected
        }

        try {
            Debug::log(sprintf('Loading media ID %d', $mediaId), __METHOD__, [
                'blockId' => $block->id(),
                'mediaId' => $mediaId
            ]);
            $media = $view->api()->read('media', $mediaId)->getContent();
            $thumbnailHtml = $media->thumbnail(null, 'medium');
            
            Debug::log('Successfully rendered video thumbnail block', __METHOD__);
            return $view->partial('common/block-layout/video-thumbnail', [
                'media' => $media,
                'block' => $block,
                'thumbnailHtml' => $thumbnailHtml,
                'framePercent' => $framePercent,
            ]);
        } catch (\Exception $e) {
            Debug::logError(sprintf(
                'Error rendering video thumbnail block for media ID %d: %s',
                $mediaId,
                $e->getMessage()
            ), __METHOD__, [
                'blockId' => $block->id(),
                'mediaId' => $mediaId
            ]);
            return '<p class="error">' . $view->translate('Error loading video thumbnail') . '</p>';
        }
    }
}