<?php
namespace VideoThumbnail\Site\BlockLayout;

use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\MediaRepresentation; // Added
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\Form\Form; // Optional: If you need a configuration form for the block
use VideoThumbnail\Form\VideoThumbnailBlockForm; // Added: Assuming this is your form class
use Omeka\Site\BlockLayout\BlockLayoutInterface; // Add this use statement

/**
 * Represents the Video Thumbnail block for public site pages.
 */
class VideoThumbnailBlock extends AbstractBlockLayout implements BlockLayoutInterface // Add implements clause
{
    /**
     * Get the display label for this block layout.
     *
     * @return string
     */
    public function getLabel()
    {
        // This is the name that appears in the "Add new block" dropdown
        return 'Video Thumbnail'; // @translate
    }

    /**
     * Get the configuration form for this block layout.
     *
     * @param PhpRenderer $view
     * @param \Omeka\Api\Representation\SiteRepresentation $site
     * @param \Omeka\Api\Representation\SitePageRepresentation $page
     * @param \Omeka\Api\Representation\SitePageBlockRepresentation $block
     * @return string Returns HTML form string or empty string if no form.
     */
    public function form(
        \Laminas\View\Renderer\PhpRenderer $view,
        \Omeka\Api\Representation\SiteRepresentation $site,
        ?\Omeka\Api\Representation\SitePageRepresentation $page = null,
        ?\Omeka\Api\Representation\SitePageBlockRepresentation $block = null
    ) {
        $mediaId = $block ? ($block->dataValue('media_id')) : null;
        $mediaTitle = '';
        $framePercent = $block ? $block->dataValue('frame_percent') : 10;
        if ($mediaId) {
            try {
                $media = $view->api()->read('media', $mediaId)->getContent();
                $mediaTitle = $media->displayTitle();
            } catch (\Exception $e) {
                $mediaTitle = $view->translate('Unknown media');
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
     * Render the block on the public site page.
     *
     * @param PhpRenderer $view
     * @param SitePageBlockRepresentation $block
     * @return string HTML output for the block.
     */
    public function render(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        $mediaId = $block->dataValue('media_id');
        $framePercent = $block->dataValue('frame_percent');
        if (!$mediaId) {
            return ''; // Return empty if no media selected
        }

        try {
            $media = $view->api()->read('media', $mediaId)->getContent();
            $thumbnailHtml = $media->thumbnail(null, 'medium');
            
            return $view->partial('common/block-layout/video-thumbnail', [
                'media' => $media,
                'block' => $block,
                'thumbnailHtml' => $thumbnailHtml,
                'framePercent' => $framePercent,
            ]);
        } catch (\Exception $e) {
            return '<p class="error">' . $view->translate('Error loading video thumbnail') . '</p>';
        }
    }
}