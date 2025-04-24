<?php
namespace VideoThumbnail\Job;

use Omeka\Job\AbstractJob;

class RegenerateThumbnails extends AbstractJob
{
    public function perform()
    {
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $settings = $services->get('Omeka\Settings');
        $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
        $percent = (int)$settings->get('videothumbnail_percent', 10);

        \VideoThumbnail\Media\Ingester\VideoThumbnail::debugLog('Batch job started', $entityManager);

        $mediaRepo = $entityManager->getRepository('Omeka\\Entity\\Media');
        $qb = $mediaRepo->createQueryBuilder('m')
            ->where('m.mediaType LIKE :type')
            ->setParameter('type', 'video/%');
        $mediaList = $qb->getQuery()->getResult();

        foreach ($mediaList as $media) {
            \VideoThumbnail\Media\Ingester\VideoThumbnail::debugLog('Processing media ID ' . $media->getId(), $entityManager);
            \VideoThumbnail\Media\Ingester\VideoThumbnail::extractAndSaveThumbnail($media, $percent, $ffmpegPath, $entityManager);
        }

        \VideoThumbnail\Media\Ingester\VideoThumbnail::debugLog('Batch job completed', $entityManager);
    }
}
