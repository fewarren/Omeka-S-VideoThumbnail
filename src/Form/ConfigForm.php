<?php
namespace VideoThumbnail\Form;

use Laminas\Form\Form;
use Laminas\Form\Element\Text;
use Laminas\Form\Element\Number;
use Laminas\Form\Element\Checkbox;

class ConfigForm extends Form
{
    protected $supportedFormats = [
        'video/mp4' => 'MP4',
        'video/webm' => 'WebM',
        'video/quicktime' => 'QuickTime/MOV',
        'video/x-msvideo' => 'AVI',
        'video/x-ms-wmv' => 'WMV',
        'video/x-matroska' => 'MKV',
        'video/3gpp' => '3GP',
        'video/3gpp2' => '3G2',
        'video/x-flv' => 'FLV'
    ];

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

        $this->add([
            'name' => 'videothumbnail_supported_formats',
            'type' => 'multiCheckbox',
            'options' => [
                'label' => 'Supported Video Formats', // @translate
                'value_options' => $this->supportedFormats,
                'info' => 'Select which video formats to process', // @translate,
            ],
            'attributes' => [
                'required' => true,
                'id' => 'videothumbnail_supported_formats',
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

        // Add debug mode checkbox
        $this->add([
            'name' => 'videothumbnail_debug_mode',
            'type' => Checkbox::class,
            'options' => [
                'label' => 'Enable Debug Mode', // @translate
                'info' => 'Log detailed debug information to videothumbnail.log in the Omeka S logs directory.', // @translate
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
            'attributes' => [
                'id' => 'videothumbnail_debug_mode',
                'value' => '1', // Default to enabled
            ],
        ]);

        $this->add([
            'name' => 'videothumbnail_log_level',
            'type' => 'select',
            'options' => [
                'label' => 'Log Level', // @translate
                'value_options' => [
                    'error' => 'Error',
                    'warning' => 'Warning',
                    'info' => 'Info',
                    'debug' => 'Debug'
                ],
                'info' => 'Minimum log level to record', // @translate,
            ],
            'attributes' => [
                'id' => 'videothumbnail_log_level',
                'value' => 'info',
            ],
        ]);

        $this->add([
            'name' => 'video_thumbnail_timestamp_property',
            'type' => Text::class,
            'options' => [
                'label' => 'Timestamp metadata field', // @translate
                'info' => 'Enter the term (e.g., dcterms:temporal) of the metadata field containing the desired thumbnail timestamp (in seconds or HH:MM:SS format). Leave blank to disable.', // @translate
            ],
            'attributes' => [
                'id' => 'video_thumbnail_timestamp_property',
            ],
        ]);

        // Add the debug mode checkbox if it doesn't exist, or ensure it defaults to true
        if (!$this->has('videothumbnail_debug_mode')) {
            $this->add([
                'name' => 'videothumbnail_debug_mode',
                'type' => Checkbox::class,
                'options' => [
                    'label' => 'Enable Debug Mode', // @translate
                    'info' => 'Log detailed debugging information to videothumbnail.log. Requires Omeka S logging to be enabled.', // @translate
                    'use_hidden_element' => true,
                    'checked_value' => '1',
                    'unchecked_value' => '0',
                ],
                'attributes' => [
                    'value' => '1', // Default to checked (true)
                    'id' => 'videothumbnail_debug_mode',
                ],
            ]);
        } else {
            // Ensure existing element defaults to true
            $element = $this->get('videothumbnail_debug_mode');
            $element->setAttribute('value', '1');
            $element->setOption('checked_value', '1');
            $element->setOption('unchecked_value', '0');
        }

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

        $inputFilter = $this->getInputFilter();

        $inputFilter->add([
            'name' => 'videothumbnail_ffmpeg_path',
            'required' => true,
            'filters' => [
                ['name' => 'StringTrim'],
            ],
            'validators' => [
                [
                    'name' => 'Callback',
                    'options' => [
                        'callback' => [$this, 'validateFfmpegPath'],
                        'messages' => [
                            'callbackValue' => 'FFmpeg executable not found or not executable'
                        ]
                    ]
                ]
            ]
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
                        'inclusive' => true
                    ]
                ]
            ]
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
                        'min' => 64,
                        'max' => 2048,
                        'inclusive' => true
                    ]
                ]
            ]
        ]);

        $inputFilter->add([
            'name' => 'videothumbnail_supported_formats',
            'required' => true,
            'validators' => [
                [
                    'name' => 'NotEmpty',
                    'options' => [
                        'messages' => [
                            'isEmpty' => 'At least one video format must be selected'
                        ]
                    ]
                ]
            ]
        ]);
    }

    public function validateFfmpegPath($value)
    {
        if (empty($value)) {
            return false;
        }

        // Check if path exists and is executable
        if (!file_exists($value)) {
            return false;
        }
        
        if (!is_executable($value)) {
            return false;
        }

        // Try to execute ffmpeg -version
        try {
            $output = [];
            $returnVar = 0;
            $command = escapeshellcmd($value) . ' -version 2>&1';
            exec($command, $output, $returnVar);

            if ($returnVar !== 0) {
                return false;
            }

            // Verify ffmpeg version string in output
            $versionString = implode("\n", $output);
            $result = strpos($versionString, 'ffmpeg version') !== false;
            
            return $result;
        } catch (\Exception $e) {
            error_log('Exception while testing FFmpeg: ' . $e->getMessage());
            return false;
        }
    }
}
