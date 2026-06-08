<?php
namespace Plugin\Komoju42\Controller;
include_once dirname(__FILE__) . '/../Resource/komoju_lib/init.php';

use Eccube\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Plugin\Komoju42\Service\LogService;
use Plugin\Komoju42\Service\ConfigService;
use Plugin\Komoju42\Service\WebhookService;
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
     * @Route("/plugin/Komoju42/webhook", name="Komoju42_webhook")
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
            $this->log_service->writeLog("webhook", "", "verification failed: " . $ex->getMessage());
            return $this->json(['status' => 'error'], 400);
        }
        $type = $data->type;

        try {
            switch($type){
                case "payment.authorized":
                    $this->webhook_service->paymentAuthorized($data);
                break;
                case "payment.refunded":
                case "payment.refund.created":
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
