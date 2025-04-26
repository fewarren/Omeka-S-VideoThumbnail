<?php
namespace VideoThumbnail\Form;

use Laminas\Form\Form;
use Laminas\Form\Element;

/**
 * Form for configuring the Video Thumbnail site block.
 *
 * Allows users to select a media item (presumably a video) whose thumbnail
 * will be displayed by the block.
 */
class VideoThumbnailBlockForm extends Form
{
    /**
     * Adds form elements for selecting and displaying a video media item.
     *
     * Initializes the form with a hidden input for the media ID, a read-only text field to display the selected media, and a button to trigger media selection.
     */
    public function init()
    {
        $this->add([
            'name' => 'media_id',
            'type' => Element\Hidden::class,
            'attributes' => [
                'id' => 'vt-block-media-id', // Add an ID for easier JS targeting
            ],
        ]);

        $this->add([
            'name' => 'media_display_label', // To show the user what's selected
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Selected Media', // @translate
                'info' => 'The currently selected media item.', // @translate
            ],
            'attributes' => [
                'id' => 'vt-block-media-label',
                'readonly' => true, // User shouldn't edit this directly
                'placeholder' => 'No media selected', // @translate
            ],
        ]);

        $this->add([
            'name' => 'select_media_button',
            'type' => Element\Button::class,
            'options' => [
                'label' => 'Select Media', // @translate
            ],
            'attributes' => [
                'id' => 'vt-block-select-media-button',
                // Add data attributes or classes needed for your JS media selector
                'class' => 'vt-select-media button',
                'title' => 'Choose a video media item', // @translate
            ],
        ]);

        // You might add input filters here if needed, e.g., to ensure media_id is numeric
        // $this->getInputFilter()->add(...)
    }

    /**
     * Sets form data and updates the media display label based on provided values.
     *
     * If a display label is given, it is used directly; otherwise, the label is set to indicate the media ID or cleared if no media is selected.
     *
     * @param array|\ArrayAccess|\Traversable $data Form data to set.
     * @return Form The form instance.
     */
    public function setData($data)
    {
        // If media_display_label is provided in the data (e.g., fetched via API), use it.
        // Otherwise, you might need JS to update this label when media is selected.
        if (isset($data['media_display_label'])) {
            $this->get('media_display_label')->setValue($data['media_display_label']);
        } elseif (isset($data['media_id']) && !empty($data['media_id'])) {
             $this->get('media_display_label')->setValue(sprintf('Media ID: %s', $data['media_id']));
        } else {
             $this->get('media_display_label')->setValue(''); // Clear if no ID
        }

        return parent::setData($data);
    }
}