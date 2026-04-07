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

namespace Plugin\komoju42\Controller\Admin;

use Eccube\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\ORM\EntityManagerInterface;
use Plugin\komoju42\Service\ConfigService;
use Plugin\komoju42\Form\Type\KomojuConfigType;

class ConfigController extends AbstractController
{
    protected $entityManager;
    protected $config_service;

    public function __construct(EntityManagerInterface $entityManager, ConfigService $configService){
        $this->entityManager = $entityManager;
        $this->config_service = $configService;
    }
    /**
     * @Route("/%eccube_admin_route%/komoju42/config", name="komoju42_admin_config")
     * @Template("@komoju42/admin/komoju_config.twig")
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