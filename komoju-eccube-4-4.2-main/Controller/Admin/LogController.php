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

namespace Plugin\Komoju42\Controller\Admin;

use Eccube\Controller\AbstractController;
use Plugin\Komoju42\Repository\KomojuLogRepository;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Knp\Component\Pager\PaginatorInterface;

class LogController extends AbstractController
{
    /**
     * @var KomojuLogRepository
     */
    protected $komoju_log_repo;
    /**
     * ConfigController constructor.
     *
     * @param KomojuLogRepository $komoju_log_repo
     */
    public function __construct(KomojuLogRepository $komoju_log_repo)
    {
        $this->komoju_log_repo = $komoju_log_repo;
    }

    /**
     * @Route("/%eccube_admin_route%/Komoju42/log", name="Komoju42_admin_log")
     * @Route("/%eccube_admin_route%/Komoju42/log/page/{page_no}", requirements={"page_no" = "\d+"}, name="Komoju42_admin_log_page")
     * @Template("@Komoju42/admin/komoju_log.twig")
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