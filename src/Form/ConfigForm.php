<?php
namespace VideoThumbnail\Form;

use Laminas\Form\Form;
use Laminas\Form\Element\Text;
use Laminas\Form\Element\Number;
use Laminas\Form\Element\Checkbox;

class ConfigForm extends Form
{
    public function init()
    {
        $this->add([
            'name' => 'videothumbnail_ffmpeg_path',
            'type' => Text::class,
            'options' => [
                'label' => 'FFmpeg Path', // @translate
                'info' => 'Full path to FFmpeg executable (e.g., /usr/bin/ffmpeg)', // @translate
            ],
            'attributes' => [
                'required' => true,
                'id' => 'videothumbnail_ffmpeg_path',
            ],
        ]);

        $this->add([
            'name' => 'videothumbnail_frames_count',
            'type' => Number::class,
            'options' => [
                'label' => 'Number of Frames', // @translate
                'info' => 'Number of frames to extract for selection (higher values require more processing time)', // @translate
            ],
            'attributes' => [
                'required' => true,
                'min' => 3,
                'max' => 20,
                'step' => 1,
                'value' => 5,
                'id' => 'videothumbnail_frames_count',
            ],
        ]);

        $this->add([
            'name' => 'videothumbnail_default_frame',
            'type' => Number::class,
            'options' => [
                'label' => 'Default Frame Position (% of video duration)', // @translate
                'info' => 'Default position for thumbnail extraction as percentage of video duration. Must be between 0 and 100. Values outside this range will be clamped.', // @translate
            ],
            'attributes' => [
                'required' => true,
                'min' => 0,
                'max' => 100,
                'step' => 1,
                'value' => 10,
                'id' => 'videothumbnail_default_frame',
            ],
        ]);
        
        // Add memory limit for batch processing
        $this->add([
            'name' => 'videothumbnail_memory_limit',
            'type' => Number::class,
            'options' => [
                'label' => 'Memory Limit for Batch Processing (MB)', // @translate
                'info' => 'Maximum allowed memory usage for batch thumbnail generation in MB. Increase for processing large batches or videos, decrease for limited environments.', // @translate
            ],
            'attributes' => [
                'required' => true,
                'min' => 50,
                'max' => 1024,
                'step' => 10,
                'value' => 100,
                'id' => 'videothumbnail_memory_limit',
            ],
        ]);
        
        // Add debug mode toggle
        $this->add([
            'name' => 'videothumbnail_debug_mode',
            'type' => Checkbox::class,
            'options' => [
                'label' => 'Enable Debug Mode', // @translate
                'info' => 'When enabled, detailed debug information will be logged to the Omeka-S error log', // @translate
            ],
            'attributes' => [
                'id' => 'videothumbnail_debug_mode',
            ],
        ]);
        
        // Add submit button
        $this->add([
            'name' => 'submit',
            'type' => 'Submit',
            'attributes' => [
                'value' => 'Save Settings', // @translate
                'id' => 'submit',
                'class' => 'button',
            ],
        ]);
    }
}
