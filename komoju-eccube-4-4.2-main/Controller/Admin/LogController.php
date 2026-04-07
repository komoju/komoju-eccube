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
use Plugin\komoju42\Repository\KomojuLogRepository;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Knp\Component\Pager\PaginatorInterface;
use Psr\Container\ContainerInterface;

class LogController extends AbstractController
{
    /**
     * @var KomojuLogRepository
     */
    protected $komoju_log_repo;
    protected $entityManager;
    protected $container;
    /**
     * ConfigController constructor.
     *
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->entityManager = $this->container->get('doctrine.orm.entity_manager');
        $this->komoju_log_repo = $this->entityManager->getRepository('Plugin\komoju42\Entity\KomojuLog');
    }

    /**
     * @Route("/%eccube_admin_route%/komoju42/log", name="komoju42_admin_log")
     * @Route("/%eccube_admin_route%/komoju42/log/page/{page_no}", requirements={"page_no" = "\d+"}, name="komoju42_admin_log_page")
     * @Template("@komoju42/admin/komoju_log.twig")
     */
    public function index(Request $request, PaginatorInterface $paginator, $page_no = null)
    {
        $page_count = $this->eccubeConfig->get('eccube_default_page_count');
        if($page_no){
        } else {
            $page_no=1;
        }

        $qb = $this->komoju_log_repo->createQueryBuilder('s');
        $qb->orderBy('s.id','DESC');
        $pagination = $paginator->paginate(
            $qb,
            $page_no,
            $page_count
        );
        return [
            'pagination' => $pagination
        ];
    }
}