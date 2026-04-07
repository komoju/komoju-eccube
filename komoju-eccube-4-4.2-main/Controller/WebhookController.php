<?php
namespace Plugin\komoju42\Controller;
include_once dirname(__FILE__) . '/../Resource/komoju_lib/init.php';

use Eccube\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Plugin\komoju42\Service\LogService;
use Plugin\komoju42\Service\ConfigService;
use Plugin\komoju42\Service\WebhookService;
use Komoju\WebhookEvent;


class WebhookController extends AbstractController
{
    protected $log_service;
    protected $config_service;
    protected $webhook_service;

    public function __construct(LogService $logService, ConfigService $configService, WebhookService $webhookService){
        $this->log_service = $logService;
        $this->config_service = $configService;
        $this->webhook_service = $webhookService;
    }

    /**
     * @Route("/plugin/komoju42/webhook", name="komoju42_webhook")
     */
    public function webhook(Request $request){
        try{
            $config_data = $this->config_service->getConfigData();
            $webhook_secret = $config_data['webhook_secret'];
            $data = WebhookEvent::constructEvent(
                $request->getContent(),
                $request->headers->get('X-Komoju-Signature'),
                $webhook_secret);
        }catch(\Exception $ex){
            $this->log_service->writeLog("webhook", "", "webhook verification failed: " . $ex->getMessage());
            return $this->json(['status' => 'error'], 400);
        }
        $type = $data->type;
        $this->log_service->writeLog("webhook[$type]", "", "");
        try {
            switch($type){
                case "payment.refunded":
                    $this->webhook_service->paymentRefunded($data);
                    break;
                case "payment.captured":
                    $this->webhook_service->paymentCaptured($data);
                break;
                case "payment.expired":
                    $this->webhook_service->paymentExpired($data);
                break;
                case "payment.failed":
                    $this->webhook_service->paymentFailed($data);
                break;
                case "payment.cancelled":
                    $this->webhook_service->paymentCanceled($data);
                break;
                case "payment.updated":
                    $this->webhook_service->paymentUpdated($data);
                break;
            }
        } catch (\Exception $ex) {
            $this->log_service->writeLog("webhook[$type]", "", "processing failed: " . $ex->getMessage());
            return $this->json(['status' => 'error', 'message' => 'processing failed'], 500);
        }
        return $this->json(['status' => 'success']);
    }
}