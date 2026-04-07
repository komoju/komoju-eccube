<?php
/*
* Plugin Name : KomojuPaymentGateway
*
* Copyright (C) 2026 KOMOJU Co., Ltd. All Rights Reserved.
* https://ja.komoju.com/
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/


namespace Plugin\komoju\Controller\Admin;

use Eccube\Controller\AbstractController;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Plugin\komoju\Service\ConfigService;
use Plugin\komoju\Repository\KomojuConfigRepository;
use Plugin\komoju\Form\Type\KomojuConfigType;

class ConfigController extends AbstractController
{
    protected $config_service;
    protected $komoju_config_repo;

    public function __construct(ConfigService $configService, KomojuConfigRepository $komoju_config_repo){
        $this->config_service = $configService;
        $this->komoju_config_repo = $komoju_config_repo;
    }
    /**
     * @Route("/%eccube_admin_route%/komoju/config", name="komoju_admin_config")
     * @Template("@komoju/admin/komoju_config.twig")
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
        ];
    }
    /**
     * @Route("/%eccube_admin_route%/komoju/config/sync", name="komoju_admin_sync_methods", methods={"POST"})
     */
    public function syncPaymentMethods(Request $request){
        $token = $request->headers->get('X-CSRF-Token');
        if (!$this->isCsrfTokenValid('komoju_config', $token)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
        }

        $config_data = $this->config_service->getConfigData();
        if(empty($config_data) || empty($config_data['publishable_key'])){
            return new JsonResponse(['success' => false, 'message' => 'Publishable key is not configured.'], 400);
        }

        $result = $this->config_service->syncPaymentMethods($config_data['publishable_key']);
        if($result){
            return new JsonResponse(['success' => true, 'message' => 'Payment methods synced successfully.']);
        }
        return new JsonResponse(['success' => false, 'message' => 'Failed to sync payment methods. Please check your API key.'], 400);
    }

    private function getErrorMessages(\Symfony\Component\Form\Form $form) {
        $errors = array();

        foreach ($form->getErrors() as $key => $error) {
            if ($form->isRoot()) {
                $errors['#'][] = $error->getMessage();
            } else {
                $errors[] = $error->getMessage();
            }
        }

        foreach ($form->all() as $child) {
            if (!$child->isValid()) {
                $errors[$child->getName()] = $this->getErrorMessages($child);
            }
        }

        return $errors;
    }
}