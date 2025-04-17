<?php
namespace VideoThumbnail\Job;

use Omeka\Job\AbstractJob;
use Omeka\Entity\Media;
use Omeka\File\TempFileFactory;
use Laminas\ServiceManager\Exception\ServiceNotFoundException;

class ExtractFrames extends AbstractJob
{
    /**
     * Get formatted memory usage
     *
     * @return string Formatted memory usage
     */
    protected function getMemoryUsage()
    {
        $mem = memory_get_usage();
        return round($mem / 1048576, 2) . ' MB';
    }

    /**
     * Retrieve job arguments
     *
     * @return array The job arguments
     */
    protected function getJobArguments()
    {
        // Replace with actual logic to fetch job arguments
        return $this->getArg('args', []);
    }

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
        
        $args = $this->getJobArguments(); // Use the new method to fetch job arguments
        $defaultFramePercent = isset($args['frame_position']) 
            ? (float)$args['frame_position'] 
            : (float)$settings->get('videothumbnail_default_frame', 10);
        
        error_log('VideoThumbnail: Creating VideoFrameExtractor with FFmpeg path: ' . $ffmpegPath);
        $videoFrameExtractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');

        // Handle missing Omeka\File\Manager service
        try {
            $fileManager = $services->get('Omeka\File\Manager');
        } catch (ServiceNotFoundException $e) {
            $logger->err('Omeka\File\Manager service not found. Ensure it is configured correctly.');
            error_log('VideoThumbnail: Omeka\File\Manager service not found: ' . $e->getMessage());
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
            $totalMedias = count($medias);
            
            error_log('VideoThumbnail: Found ' . $totalMedias . ' video media items');
            $logger->info(sprintf('VideoThumbnail: Starting thumbnail regeneration for %d videos', $totalMedias));
            
            if ($totalMedias === 0*

