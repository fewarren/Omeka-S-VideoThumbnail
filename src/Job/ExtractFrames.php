<?php
namespace VideoThumbnail\Job;

use Omeka\Job\AbstractJob;
use Omeka\Entity\Media;
use Omeka\File\TempFileFactory;

class ExtractFrames extends AbstractJob
{
    // Other methods remain unchanged...

    public function perform()
    {
        $startTime = microtime(true);

        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $settings = $services->get('Omeka\Settings');
        $logger = $services->get('Omeka\Logger');

        $logger->info('VideoThumbnail: Job started at ' . date('Y-m-d H:i:s'));
        error_log('VideoThumbnail: Job started at ' . date('Y-m-d H:i:s'));
        error_log('VideoThumbnail: Initial memory usage: ' . $this->getMemoryUsage());

        // Get FFmpeg path from settings
        $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');

        // Auto-detect FFmpeg path if invalid
        if (!file_exists($ffmpegPath) || !is_executable($ffmpegPath)) {
            $possiblePaths = ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'];
            foreach ($possiblePaths as $path) {
                if (file_exists($path) && is_executable($path)) {
                    $ffmpegPath = $path;
                    break;
                }
            }

            if (!file_exists($ffmpegPath) || !is_executable($ffmpegPath)) {
                $errorMsg = 'FFmpeg not found or not executable. Please check the configuration.';
                $logger->err($errorMsg);
                error_log('VideoThumbnail: ' . $errorMsg);
                return;
            }
        }

        $args = $this->getJobArgs();
        $defaultFramePercent = isset($args['frame_position'])
            ? (float)$args['frame_position']
            : (float)$settings->get('videothumbnail_default_frame', 10);

        error_log('VideoThumbnail: Creating VideoFrameExtractor with FFmpeg path: ' . $ffmpegPath);
        $videoFrameExtractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);

        // Debugging: Check available services
        $availableServices = $services->getRegisteredServices();
        error_log('VideoThumbnail: Available services: ' . implode(', ', array_keys($availableServices['factories'])));

        // Attempt to retrieve TempFileFactory service
        try {
            $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        } catch (\Exception $e) {
            $logger->err('VideoThumbnail: Error retrieving TempFileFactory service: ' . $e->getMessage());
            error_log('VideoThumbnail: Error retrieving TempFileFactory service: ' . $e->getMessage());
            return;
        }

        // Attempt to retrieve File Manager service
        try {
            $fileManager = $services->get('Omeka\File\Manager');
        } catch (\Exception $e) {
            $logger->err('VideoThumbnail: Error retrieving File Manager service: ' . $e->getMessage());
            error_log('VideoThumbnail: Error retrieving File Manager service: ' . $e->getMessage());
            return;
        }

        error_log('VideoThumbnail: Querying for video media items');
        $dql = '
            SELECT m FROM Omeka\Entity\Media m 
            WHERE m.mediaType LIKE :video
        ';

        $query = $entityManager->createQuery($dql);
        $query->setParameters(['video' => 'video/%']);

        try {
            $medias = $query->getResult();
            $totalMed

