<?php
/*
* Plugin Name : KomojuPaymentGateway
*
* Copyright (C) 2018 Subspire Inc. All Rights Reserved.
* http://www.subspire.co.jp/
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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Plugin\komoju\Repository\KomojuConfigRepository;
use Plugin\komoju\Form\Type\KomojuConfigType;

class ConfigController extends AbstractController
{
    protected $container;
    protected $config_service;
    protected $komoju_config_repo;

    public function __construct(ContainerInterface $container, KomojuConfigRepository $komoju_config_repo){
        $this->container = $container;
        $this->komoju_config_repo = $komoju_config_repo;
        $this->config_service = $container->get("plg_komoju.service.config");
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
        $this->config_service->enablePlugin();

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