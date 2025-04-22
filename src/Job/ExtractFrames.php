<?php

namespace VideoThumbnail\Job;

use Omeka\Job\AbstractJob;
use VideoThumbnail\Stdlib\Debug;
use Omeka\Entity\Media;

class ExtractFrames extends AbstractJob
{
    protected $mediaId;
    protected $videoFrameExtractor;
    protected $entityManager;
    protected $totalFrames;
    protected $processedFrames = 0;

    public function perform()
    {
        $this->initializeJob();

        try {
            $this->reportProgress(0, 'Starting frame extraction');
            $media = $this->loadMedia();
            
            if (!$media) {
                throw new \RuntimeException(sprintf('Media %d not found', $this->mediaId));
            }

            $videoFile = $media->getOriginalFilePath();
            if (!file_exists($videoFile)) {
                throw new \RuntimeException('Video file not found');
            }

            // Get total frames for progress calculation
            $this->totalFrames = $this->videoFrameExtractor->countFrames($videoFile);
            
            // Extract frames with progress callback
            $frames = $this->videoFrameExtractor->extract(
                $videoFile,
                [$this, 'onFrameExtracted']
            );

            if (empty($frames)) {
                throw new \RuntimeException('No frames were extracted');
            }

            // Save frame data to media
            $this->saveFrameData($media, $frames);
            
            $this->reportProgress(100, 'Frame extraction complete');

        } catch (\Exception $e) {
            Debug::logError(sprintf(
                'Frame extraction failed for media %d: %s',
                $this->mediaId,
                $e->getMessage()
            ), __METHOD__);

            // Report error status
            $this->reportProgress(
                ($this->processedFrames / max(1, $this->totalFrames)) * 100,
                'Error: ' . $e->getMessage()
            );

            throw $e;
        }
    }

    protected function initializeJob()
    {
        $services = $this->getServiceLocator();
        
        $this->mediaId = $this->getArg('media_id');
        $this->videoFrameExtractor = $services->get('VideoThumbnail\VideoFrameExtractor');
        $this->entityManager = $services->get('Omeka\EntityManager');
    }

    protected function loadMedia()
    {
        return $this->entityManager->find(Media::class, $this->mediaId);
    }

    protected function saveFrameData(Media $media, array $frames)
    {
        $frameData = [];
        foreach ($frames as $frame) {
            $frameData[] = [
                'path' => $frame['path'],
                'timestamp' => $frame['timestamp'],
                'index' => $frame['index']
            ];
        }

        // Store frame data in media
        $mediaData = $media->getData() ?: [];
        $mediaData['video_frames'] = $frameData;
        $media->setData($mediaData);
        
        $this->entityManager->persist($media);
        $this->entityManager->flush();
    }

    public function onFrameExtracted($frameIndex)
    {
        $this->processedFrames++;
        $progress = ($this->processedFrames / $this->totalFrames) * 100;
        
        $this->reportProgress(
            $progress,
            sprintf('Extracted frame %d of %d', $this->processedFrames, $this->totalFrames)
        );
    }

    protected function reportProgress($percent, $message)
    {
        $job = $this->job;
        $status = $job->getStatus();

        // Only update if status has changed or progress has changed significantly
        if ($status !== $message || abs($job->getData('progress', 0) - $percent) >= 1) {
            $job->setStatus($message);
            $job->setData('progress', $percent);
            
            $this->entityManager->persist($job);
            $this->entityManager->flush();

            Debug::log(sprintf(
                'Job %d progress: %d%% - %s',
                $job->getId(),
                $percent,
                $message
            ), __METHOD__);
        }
    }
}
