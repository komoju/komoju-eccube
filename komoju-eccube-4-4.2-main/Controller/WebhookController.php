<?php
namespace Plugin\komoju42\Controller;
include_once dirname(__FILE__) . '/../Resource/komoju_lib/init.php';

use Eccube\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Psr\Container\ContainerInterface;
use Plugin\komoju42\Repository\KomojuConfigRepository;
use Plugin\komoju42\Form\Type\KomojuConfigType;
use Komoju\WebhookEvent;


class WebhookController extends AbstractController
{
    protected $container;
    protected $log_service;
    protected $config_service;
    protected $webhook_service;

    public function __construct(ContainerInterface $container){
        $this->container = $container;
        $this->log_service = $this->container->get("plg_komoju.service.komoju_log");
        $this->config_service = $this->container->get("plg_komoju.service.config");        
        $this->webhook_service = $this->container->get("plg_komoju.service.komoju_webhook");
    }

    /**
     * @Route("/plugin/komoju42/webhook", name="komoju_webhook")
     */
    public function webhook(Request $request){
        log_info("===========webhook is called=======");
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
        log_info("type : $type");

        $this->log_service->writeLog("webhook[$type]", "", "");
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
        return $this->json(['status'    =>  'success']);
    }
}