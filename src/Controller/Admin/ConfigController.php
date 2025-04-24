<?php
namespace VideoThumbnail\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use VideoThumbnail\Form\ConfigForm;

class ConfigController extends AbstractActionController
{
    public function indexAction()
    {
        $this->api()->authorize('update', 'Omeka:Module'); // Permission check
        $form = $this->getForm(ConfigForm::class);
        $request = $this->getRequest();

        if ($request->isPost()) {
            $form->setData($request->getPost());
            if ($form->isValid()) {
                $data = $form->getData();
                $settings = $this->settings();
                $settings->set('videothumbnail_percent', $data['percent']);
                $settings->set('videothumbnail_ffmpeg_path', $data['ffmpeg_path']);
                $settings->set('videothumbnail_debug', !empty($data['debug']));
                if (!empty($data['run_batch'])) {
                    $this->jobDispatcher()->dispatch(\VideoThumbnail\Job\RegenerateThumbnails::class, []);
                    $settings->set('videothumbnail_last_run', date('Y-m-d H:i:s'));
                    $this->messenger()->addSuccess('Batch job started.');
                } else {
                    $this->messenger()->addSuccess('Settings saved.');
                }
                return $this->redirect()->toRoute('admin/video-thumbnail/config');
            }
        } else {
            $settings = $this->settings();
            $form->setData([
                'percent' => $settings->get('videothumbnail_percent', 10),
                'ffmpeg_path' => $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg'),
                'last_run' => $settings->get('videothumbnail_last_run', 'Never'),
                'debug' => $settings->get('videothumbnail_debug', false),
            ]);
        }

        return new ViewModel([
            'form' => $form,
        ]);
    }
}
