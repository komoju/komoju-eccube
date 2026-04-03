<?php
/*
* Plugin Name : StripePaymentGateway
*
* Copyright (C) 2018 Subspire Inc. All Rights Reserved.
* http://www.subspire.co.jp/
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/


namespace Plugin\komoju\Controller\Admin;

use Eccube\Controller\AbstractController;
use Plugin\komoju\Repository\KomojuLogRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
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
     * @Route("/%eccube_admin_route%/komoju/log", name="komoju_admin_log")
     * @Route("/%eccube_admin_route%/komoju/page/{page_no}", requirements={"page_no" = "\d+"}, name="komoju_admin_log_page")
     * @Template("@komoju/admin/komoju_log.twig")
     */
    public function index(Request $request, $page_no = null, PaginatorInterface $paginator)
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