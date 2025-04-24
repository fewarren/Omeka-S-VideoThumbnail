<?php
namespace VideoThumbnail\Form;

use Laminas\Form\Form;
use Laminas\Form\Element;

class ConfigForm extends Form
{
    public function init()
    {
        $this->add([
            'name' => 'percent',
            'type' => Element\Number::class,
            'options' => [
                'label' => _('Default Thumbnail Position (%)'),
                'info' => _('Percent into the video to extract the thumbnail (0-100).'),
            ],
            'attributes' => [
                'min' => 0,
                'max' => 100,
                'step' => 1,
                'required' => true,
            ],
        ]);
        $this->add([
            'name' => 'ffmpeg_path',
            'type' => Element\Text::class,
            'options' => [
                'label' => _('FFmpeg Path'),
                'info' => _('Path to the ffmpeg executable on your server.'),
            ],
            'attributes' => [
                'required' => true,
            ],
        ]);
        $this->add([
            'name' => 'run_batch',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => _('Regenerate All Thumbnails Now'),
                'info' => _('Check to start a background job to regenerate all video thumbnails.'),
            ],
            'attributes' => [
                'id' => 'run_batch',
            ],
        ]);
        $this->add([
            'name' => 'last_run',
            'type' => Element\Text::class,
            'options' => [
                'label' => _('Last Batch Run'),
            ],
            'attributes' => [
                'readonly' => true,
            ],
        ]);
        $this->add([
            'name' => 'debug',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Enable Debug Logging',
                'info' => 'Write debug messages to omeka-s/logs/videothumbnail_debug.log',
            ],
            'attributes' => [
                'id' => 'debug',
            ],
        ]);
        $this->add([
            'name' => 'submit',
            'type' => Element\Submit::class,
            'attributes' => [
                'value' => _('Save'),
                'class' => 'button',
            ],
        ]);
    }
}
