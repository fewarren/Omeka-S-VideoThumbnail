<?php
namespace VideoThumbnail\Form;

use Laminas\Form\Form;
use Laminas\Form\Element;

/**
 * Configuration form for VideoThumbnail module
 */
class ConfigForm extends Form
{
    public function init()
    {
        // FFmpeg path
        $this->add([
            'name' => 'videothumbnail_ffmpeg_path',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'FFmpeg Path',
                'info' => 'Full path to FFmpeg executable (e.g., /usr/bin/ffmpeg)',
            ],
            'attributes' => [
                'id' => 'videothumbnail_ffmpeg_path',
                'required' => true,
            ],
        ]);

        // Frames count
        $this->add([
            'name' => 'videothumbnail_frames_count',
            'type' => Element\Number::class,
            'options' => [
                'label' => 'Number of Frames',
                'info' => 'Number of frames to extract for selection',
            ],
            'attributes' => [
                'id' => 'videothumbnail_frames_count',
                'required' => true,
                'min' => 3,
                'max' => 20,
                'value' => 5,
            ],
        ]);

        // Default frame position
        $this->add([
            'name' => 'videothumbnail_default_frame',
            'type' => Element\Number::class,
            'options' => [
                'label' => 'Default Frame Position (%)',
                'info' => 'Default position as percentage of video duration',
            ],
            'attributes' => [
                'id' => 'videothumbnail_default_frame',
                'required' => true,
                'min' => 0,
                'max' => 100,
                'value' => 10,
            ],
        ]);

        // Memory limit
        $this->add([
            'name' => 'videothumbnail_memory_limit',
            'type' => Element\Number::class,
            'options' => [
                'label' => 'Memory Limit (MB)',
                'info' => 'Maximum memory usage in MB',
            ],
            'attributes' => [
                'id' => 'videothumbnail_memory_limit',
                'required' => true,
                'min' => 50,
                'max' => 1024,
                'value' => 512,
            ],
        ]);

        // Debug mode
        $this->add([
            'name' => 'videothumbnail_debug_mode',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Enable Debug Mode',
                'info' => 'Log detailed debug information',
            ],
            'attributes' => [
                'id' => 'videothumbnail_debug_mode',
            ],
        ]);

        // Log level
        $this->add([
            'name' => 'videothumbnail_log_level',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Log Level',
                'info' => 'Minimum log level to record',
                'value_options' => [
                    'error' => 'Error',
                    'warning' => 'Warning',
                    'info' => 'Info',
                    'debug' => 'Debug',
                ],
            ],
            'attributes' => [
                'id' => 'videothumbnail_log_level',
            ],
        ]);

        // Timestamp property
        $this->add([
            'name' => 'video_thumbnail_timestamp_property',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Timestamp metadata field',
                'info' => 'Metadata field containing timestamp',
            ],
            'attributes' => [
                'id' => 'video_thumbnail_timestamp_property',
            ],
        ]);

        // Supported formats - as a MultiCheckbox
        $this->add([
            'name' => 'videothumbnail_supported_formats',
            'type' => Element\MultiCheckbox::class,
            'options' => [
                'label' => 'Supported Video Formats',
                'info' => 'Select which video formats to process',
                'value_options' => [
                    'video/mp4' => 'MP4',
                    'video/webm' => 'WebM',
                    'video/quicktime' => 'QuickTime/MOV',
                    'video/x-msvideo' => 'AVI',
                    'video/x-ms-wmv' => 'WMV',
                    'video/x-matroska' => 'MKV',
                    'video/3gpp' => '3GP',
                    'video/3gpp2' => '3G2',
                    'video/x-flv' => 'FLV',
                ],
            ],
            'attributes' => [
                'id' => 'videothumbnail_supported_formats',
            ],
        ]);

        // Submit button
        $this->add([
            'name' => 'submit',
            'type' => Element\Submit::class,
            'attributes' => [
                'id' => 'submitbutton',
                'value' => 'Save Settings',
            ],
        ]);

        // Add CSRF protection for form security
        $this->add([
            'name' => 'csrf',
            'type' => Element\Csrf::class,
            'options' => [
                'csrf_options' => [
                    'timeout' => 3600,
                ],
            ],
        ]);

        // Apply input filters
        $inputFilter = $this->getInputFilter();

        $inputFilter->add([
            'name' => 'videothumbnail_ffmpeg_path',
            'required' => true,
            'filters' => [
                ['name' => 'StringTrim'],
            ],
            'validators' => [
                [
                    'name' => 'NotEmpty',
                    'options' => [
                        'messages' => [
                            'isEmpty' => 'FFmpeg path is required',
                        ],
                    ],
                ],
            ],
        ]);

        $inputFilter->add([
            'name' => 'videothumbnail_frames_count',
            'required' => true,
            'filters' => [
                ['name' => 'ToInt'],
            ],
            'validators' => [
                [
                    'name' => 'Between',
                    'options' => [
                        'min' => 3,
                        'max' => 20,
                        'inclusive' => true,
                    ],
                ],
            ],
        ]);

        $inputFilter->add([
            'name' => 'videothumbnail_default_frame',
            'required' => true,
            'filters' => [
                ['name' => 'ToInt'],
            ],
            'validators' => [
                [
                    'name' => 'Between',
                    'options' => [
                        'min' => 0,
                        'max' => 100,
                        'inclusive' => true,
                    ],
                ],
            ],
        ]);

        $inputFilter->add([
            'name' => 'videothumbnail_memory_limit',
            'required' => true,
            'filters' => [
                ['name' => 'ToInt'],
            ],
            'validators' => [
                [
                    'name' => 'Between',
                    'options' => [
                        'min' => 50,
                        'max' => 2048,
                        'inclusive' => true,
                    ],
                ],
            ],
        ]);

        $inputFilter->add([
            'name' => 'videothumbnail_log_level',
            'required' => false,
        ]);

        $inputFilter->add([
            'name' => 'video_thumbnail_timestamp_property',
            'required' => false,
            'filters' => [
                ['name' => 'StringTrim'],
            ],
        ]);

        $inputFilter->add([
            'name' => 'videothumbnail_debug_mode',
            'required' => false,
        ]);

        $inputFilter->add([
            'name' => 'videothumbnail_supported_formats',
            'required' => true,
            'validators' => [
                [
                    'name' => 'NotEmpty',
                    'options' => [
                        'messages' => [
                            'isEmpty' => 'At least one video format must be selected',
                        ],
                    ],
                ],
            ],
        ]);
    }
}