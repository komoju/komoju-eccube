<?php

namespace Plugin\Komoju42\Controller\Admin;

use Eccube\Controller\AbstractController;
use Plugin\Komoju42\Repository\KomojuLogRepository;
use Plugin\Komoju42\Repository\KomojuConfigRepository;
use Plugin\Komoju42\Form\Type\KomojuLogSearchType;
use Plugin\Komoju42\Service\LogService;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Knp\Component\Pager\PaginatorInterface;
use Doctrine\ORM\EntityManagerInterface;

class LogController extends AbstractController
{
    /**
     * @var KomojuLogRepository
     */
    protected $komoju_log_repo;

    /**
     * @var KomojuConfigRepository
     */
    protected $komoju_config_repo;

    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var LogService
     */
    protected $log_service;

    public function __construct(
        KomojuLogRepository $komoju_log_repo,
        KomojuConfigRepository $komoju_config_repo,
        EntityManagerInterface $entityManager,
        LogService $log_service
    ) {
        $this->komoju_log_repo = $komoju_log_repo;
        $this->komoju_config_repo = $komoju_config_repo;
        $this->entityManager = $entityManager;
        $this->log_service = $log_service;
    }

    /**
     * @Route("/%eccube_admin_route%/Komoju42/log", name="Komoju42_admin_log")
     * @Route("/%eccube_admin_route%/Komoju42/log/page/{page_no}", requirements={"page_no" = "\d+"}, name="Komoju42_admin_log_page")
     * @Template("@Komoju42/admin/komoju_log.twig")
     */
    public function index(Request $request, PaginatorInterface $paginator, $page_no = null)
    {
        $this->purgeExpiredLogs();

        $page_count = $this->eccubeConfig->get('eccube_default_page_count');
        $session = $request->getSession();

        $searchForm = $this->createForm(KomojuLogSearchType::class);

        if ($request->getMethod() === 'POST') {
            $searchForm->handleRequest($request);
            if ($searchForm->isSubmitted() && $searchForm->isValid()) {
                $searchData = $searchForm->getData();
                $session->set('komoju_log_search', $searchData);
                $page_no = 1;
            }
        } else {
            $searchData = $session->get('komoju_log_search');
            if ($searchData) {
                $searchForm->setData($searchData);
            }
        }

        if (!$page_no) {
            $page_no = 1;
        }

        $qb = $this->buildSearchQuery($searchData);

        $pagination = $paginator->paginate(
            $qb,
            $page_no,
            $page_count
        );
        return [
            'pagination' => $pagination,
            'searchForm' => $searchForm->createView(),
            // Number of operational (non-protected) logs the "delete
            // operational logs" button would remove. Order-history events
            // (is_protected = 1) are never counted/deleted here.
            'deletable_log_count' => $this->countDeletableLogs(),
        ];
    }

    /**
     * Count operational (non-protected) logs — the rows the bulk-delete button
     * is allowed to remove. Order-timeline history (is_protected = 1) is excluded.
     */
    private function countDeletableLogs(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from('Plugin\Komoju42\Entity\KomojuLog', 's')
            ->where('s.is_protected = 0 OR s.is_protected IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @Route("/%eccube_admin_route%/Komoju42/log/clear", name="Komoju42_admin_log_clear", methods={"GET"})
     */
    public function clearSearch(Request $request)
    {
        $request->getSession()->remove('komoju_log_search');
        return $this->redirectToRoute('Komoju42_admin_log');
    }

    /**
     * @Route("/%eccube_admin_route%/Komoju42/log/delete_all", name="Komoju42_admin_log_delete_all", methods={"POST"})
     */
    public function deleteAll(Request $request)
    {
        if (!$this->isCsrfTokenValid('komoju_log_delete_all', $request->request->get('_token'))) {
            $this->addError('komoju_payment.admin.order.error.invalid_request');
            return $this->redirectToRoute('Komoju42_admin_log');
        }

        $count = $this->entityManager->createQueryBuilder()
            ->delete('Plugin\Komoju42\Entity\KomojuLog', 's')
            ->where('s.is_protected = 0 OR s.is_protected IS NULL')
            ->getQuery()
            ->execute();

        $request->getSession()->remove('komoju_log_search');
        if ($count > 0) {
            $this->addSuccess('komoju_payment.admin.log.delete_all.success');
        } else {
            $this->addWarning('komoju_payment.admin.log.delete_all.nothing');
        }

        return $this->redirectToRoute('Komoju42_admin_log');
    }

    /**
     * @Route("/%eccube_admin_route%/Komoju42/log/export", name="Komoju42_admin_log_export", methods={"GET"})
     */
    public function export(Request $request)
    {
        $searchData = $request->getSession()->get('komoju_log_search');
        $qb = $this->buildSearchQuery($searchData);

        $response = new StreamedResponse();
        $response->setCallback(function () use ($qb) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                trans('komoju_payment.admin.log.label.create_at'),
                trans('komoju_payment.admin.log.label.api'),
                trans('komoju_payment.admin.log.label.order_id'),
                trans('komoju_payment.admin.log.label.msg'),
            ]);

            // Query::iterate() was deprecated in Doctrine ORM 2.7 and removed
            // in 3.0; toIterable() is the replacement. Note the iteration shape
            // also changed: iterate() yielded [$entity] (1-element arrays) so
            // the old code did `$log = $row[0]`, while toIterable() yields the
            // entity directly.
            $results = $qb->setMaxResults(50000)->getQuery()->toIterable();
            foreach ($results as $log) {
                fputcsv($handle, [
                    $log->getCreatedAt()->format('Y-m-d H:i:s'),
                    $log->getApi(),
                    $log->getOrderId(),
                    $log->getMsg(),
                ]);
                $this->entityManager->detach($log);
            }

            fclose($handle);
        });

        $filename = 'komoju_logs_' . date('Ymd_His') . '.csv';
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    private function buildSearchQuery($searchData = null)
    {
        $qb = $this->komoju_log_repo->createQueryBuilder('s');
        $qb->orderBy('s.id', 'DESC');

        if ($searchData) {
            if (!empty($searchData['order_id'])) {
                $qb->andWhere('s.order_id = :order_id')
                    ->setParameter('order_id', $searchData['order_id']);
            }
            if (!empty($searchData['api'])) {
                $qb->andWhere('s.api LIKE :api')
                    ->setParameter('api', $searchData['api'] . '%');
            }
            if (!empty($searchData['date_from'])) {
                $qb->andWhere('s.created_at >= :date_from')
                    ->setParameter('date_from', $searchData['date_from']);
            }
            if (!empty($searchData['date_to'])) {
                $dateTo = clone $searchData['date_to'];
                $dateTo->modify('+1 day');
                $qb->andWhere('s.created_at < :date_to')
                    ->setParameter('date_to', $dateTo);
            }
            if (!empty($searchData['keyword'])) {
                $qb->andWhere('s.msg LIKE :keyword')
                    ->setParameter('keyword', '%' . $searchData['keyword'] . '%');
            }
        }

        return $qb;
    }

    private function purgeExpiredLogs()
    {
        $config = $this->komoju_config_repo->get();
        if (!$config) {
            return;
        }

        $retentionDays = (int) $config->getLogRetentionDays();
        if ($retentionDays <= 0) {
            return;
        }

        $cutoff = new \DateTime();
        $cutoff->modify("-{$retentionDays} days");

        $this->entityManager->createQueryBuilder()
            ->delete('Plugin\Komoju42\Entity\KomojuLog', 's')
            ->where('s.created_at < :cutoff')
            ->andWhere('s.is_protected = 0 OR s.is_protected IS NULL')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
