<?php
namespace VideoThumbnail\Site\BlockLayout;

use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Laminas\View\Renderer\PhpRenderer;

class VideoThumbnailBlock extends AbstractBlockLayout
{
    public function getLabel()
    {
        return 'Video Thumbnail';
    }

    public function form(PhpRenderer $view, ...$args)
    {
        $block = $args[2] ?? null;
        $mediaId = $block ? $block->dataValue('media_id') : null;
        $percent = $block ? $block->dataValue('percent', 10) : 10;
        $mediaTitle = '';
        if ($mediaId) {
            try {
                $media = $view->api()->read('media', $mediaId)->getContent();
                $mediaTitle = $media->displayTitle();
            } catch (\Exception $e) {}
        }
        \VideoThumbnail\Media\Ingester\VideoThumbnail::debugLog("Block form called: mediaId=$mediaId, percent=$percent");
        return $view->partial('common/block-layout/video-thumbnail-form', [
            'mediaId' => $mediaId,
            'mediaTitle' => $mediaTitle,
            'percent' => $percent,
        ]);
    }

    public function render(PhpRenderer $view, $block)
    {
        $mediaId = $block->dataValue('media_id');
        $percent = $block->dataValue('percent', 10);
        $thumbnailUrl = '';
        if ($mediaId) {
            try {
                $media = $view->api()->read('media', $mediaId)->getContent();
                $thumbnailUrl = \VideoThumbnail\Media\Ingester\VideoThumbnail::getThumbnailUrl($media);
            } catch (\Exception $e) {}
        }
        \VideoThumbnail\Media\Ingester\VideoThumbnail::debugLog("Block render called: mediaId=$mediaId, percent=$percent, thumbnailUrl=" . ($thumbnailUrl ?: 'none'));
        return $view->partial('common/block-layout/video-thumbnail', [
            'thumbnailUrl' => $thumbnailUrl,
        ]);
    }
}
