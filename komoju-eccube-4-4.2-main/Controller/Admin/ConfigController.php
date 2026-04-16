<?php

namespace Plugin\Komoju42\Controller\Admin;

use Eccube\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\ORM\EntityManagerInterface;
use Plugin\Komoju42\Service\ConfigService;
use Plugin\Komoju42\Form\Type\KomojuConfigType;

class ConfigController extends AbstractController
{
    protected $entityManager;
    protected $config_service;

    public function __construct(EntityManagerInterface $entityManager, ConfigService $configService){
        $this->entityManager = $entityManager;
        $this->config_service = $configService;
    }
    /**
     * @Route("/%eccube_admin_route%/Komoju42/config", name="Komoju42_admin_config")
     * @Template("@Komoju42/admin/komoju_config.twig")
     */
    public function index(Request $request){
        $config_data = $this->config_service->getConfigData();
        $form = $this->createForm(KomojuConfigType::class, $config_data);
        $form->handleRequest($request);
        if($form->isSubmitted() && $form->isValid()){
            $config_data = $form->getData();
            $this->config_service->saveConfig($config_data);
        }

        return [
            'form' => $form->createView(),
            'is_connected' => $this->config_service->hasPaymentMethods(),
        ];
    }
    /**
     * @Route("/%eccube_admin_route%/Komoju42/config/sync", name="Komoju42_admin_sync_methods", methods={"POST"})
     */
    public function syncPaymentMethods(Request $request){
        $token = $request->headers->get('X-CSRF-Token');
        if (!$this->isCsrfTokenValid('Komoju_config', $token)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $secret_key = isset($data['secret_key']) ? $data['secret_key'] : null;

        if(empty($secret_key)){
            try {
                $config_data = $this->config_service->getConfigData();
                $secret_key = !empty($config_data) ? $config_data['secret_key'] : null;
            } catch (\Exception $e) {
                // Config not yet saved
            }
        }

        if(empty($secret_key)){
            return new JsonResponse(['success' => false, 'message' => trans('komoju_payment.admin.config.error.secret_key.empty')], 400);
        }

        try {
            $result = $this->config_service->syncPaymentMethods($secret_key);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
        if($result){
            return new JsonResponse(['success' => true, 'message' => 'Payment methods synced successfully.']);
        }
        return new JsonResponse(['success' => false, 'message' => trans('komoju_payment.admin.config.connect_failed')], 400);
    }

}
