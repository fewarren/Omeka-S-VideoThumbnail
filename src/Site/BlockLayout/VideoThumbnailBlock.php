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

        // Fetch all video media items for dropdown
        $videoMedia = [];
        try {
            $response = $view->api()->search('media', [
                'limit' => 500,
                'property' => [],
                'sort_by' => 'created',
                'sort_order' => 'desc',
                'media_type' => 'video/%',
            ]);
            foreach ($response->getContent() as $media) {
                if (strpos($media->mediaType(), 'video/') === 0) {
                    $videoMedia[] = [
                        'id' => $media->id(),
                        'title' => $media->displayTitle(),
                        'mediaType' => $media->mediaType(),
                    ];
                }
            }
        } catch (\Exception $e) {
            error_log('VideoThumbnailBlock: Could not fetch video media: ' . $e->getMessage());
        }

        // Load Omeka Media Browser assets for admin block form
        $view->headScript()->appendFile($view->assetUrl('js/media-browser.js', 'Omeka'));
        $view->headLink()->appendStylesheet($view->assetUrl('css/media-browser.css', 'Omeka'));

        return $view->partial('common/block-layout/video-thumbnail-form', [
            'data' => $data,
            'mediaId' => $data['media_id'] ?? null,
            'videoMedia' => $videoMedia,
        ]);
    }
    
    public function render(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        $data = $block->data();
        $mediaId = $data['media_id'] ?? null;
        $site = $block->page() ? $block->page()->site() : null;
        
        error_log('VideoThumbnailBlock render method called with mediaId: ' . $mediaId);
        
        $media = null;
        if ($mediaId) {
            try {
                $media = $view->api()->read('media', $mediaId)->getContent();
            } catch (\Exception $e) {
                error_log('VideoThumbnailBlock error: ' . $e->getMessage());
            }
        }
        // Always render the partial, passing media (may be null), data, and site (may be null)
        return $view->partial('common/block-layout/video-thumbnail', [
            'media' => $media,
            'data' => $data,
            'site' => $site,
        ]);
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
            return;
        }
        // Extract and save thumbnail if media and percent are set
        if (isset($data['percent']) && is_numeric($data['percent'])) {
            try {
                $services = $GLOBALS['application']->getServiceManager();
                $entityManager = $services->get('Omeka\\EntityManager');
                $media = $entityManager->find('Omeka\\Entity\\Media', $data['media_id']);
                $settings = $services->get('Omeka\\Settings');
                $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
                \VideoThumbnail\Media\Ingester\VideoThumbnail::extractAndSaveThumbnail($media, $data['percent'], $ffmpegPath, $entityManager);
            } catch (\Exception $e) {
                error_log('VideoThumbnailBlock: Exception in onHydrate thumbnail extraction: ' . $e->getMessage());
            }
        }
    }
}
