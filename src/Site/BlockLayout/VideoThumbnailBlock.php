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
    public function getLabel()
    {
        return 'Video Thumbnail'; // This is the name that appears in the "Add new block" menu
    }
    
    public function form(PhpRenderer $view, SiteRepresentation $site,
        SitePageRepresentation $page = null, SitePageBlockRepresentation $block = null
    ) {
        // Get the current values or set defaults
        $data = $block ? $block->data() : [];
        $data['media_id'] = $data['media_id'] ?? null;
        
        error_log('VideoThumbnailBlock form method called');
        return $view->partial('common/block-layout/video-thumbnail-form', [
            'data' => $data,
            'mediaId' => $data['media_id'] ?? null,
        ]);
    }
    
    public function render(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        $data = $block->data();
        $mediaId = $data['media_id'] ?? null;
        
        error_log('VideoThumbnailBlock render method called with mediaId: ' . $mediaId);
        
        // Only attempt to render if we have a media ID
        if (!$mediaId) {
            return 'No video media selected.';
        }
        
        try {
            $media = $view->api()->read('media', $mediaId)->getContent();
            return $view->partial('common/block-layout/video-thumbnail', [
                'media' => $media,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            error_log('VideoThumbnailBlock error: ' . $e->getMessage());
            return 'Error loading video media (' . $mediaId . ').';
        }
    }
    
    public function prepareForm(PhpRenderer $view)
    {
        // Load the JavaScript for media selection
        $view->headScript()->appendFile($view->assetUrl('js/block.js', 'VideoThumbnail'));
        $view->headLink()->appendStylesheet($view->assetUrl('css/block.css', 'VideoThumbnail'));
        
        error_log('VideoThumbnailBlock prepareForm method called');
    }
    
    public function prepareRender(PhpRenderer $view)
    {
        // No additional preparation needed for rendering
    }
    
    public function onHydrate(\Omeka\Entity\SitePageBlock $block, \Omeka\Stdlib\ErrorStore $errorStore)
    {
        $data = $block->getData();
        if (!isset($data['media_id']) || empty($data['media_id'])) {
            $errorStore->addError('media_id', 'A media item must be selected.');
        }
    }
}
