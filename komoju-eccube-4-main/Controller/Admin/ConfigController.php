<?php

namespace Plugin\Komoju\Controller\Admin;

use Eccube\Controller\AbstractController;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\ORM\EntityManagerInterface;
use Plugin\Komoju\Service\ConfigService;
use Plugin\Komoju\Service\RepairService;
use Plugin\Komoju\Repository\KomojuConfigRepository;
use Plugin\Komoju\Entity\KomojuPay;
use Plugin\Komoju\Form\Type\KomojuConfigType;

class ConfigController extends AbstractController
{
    protected $entityManager;
    protected $config_service;
    protected $repair_service;
    protected $komoju_config_repo;

    public function __construct(
        EntityManagerInterface $entityManager,
        ConfigService $configService,
        RepairService $repairService,
        KomojuConfigRepository $komoju_config_repo
    ){
        $this->entityManager = $entityManager;
        $this->config_service = $configService;
        $this->repair_service = $repairService;
        $this->komoju_config_repo = $komoju_config_repo;
    }
    /**
     * @Route("/%eccube_admin_route%/Komoju/config", name="Komoju_admin_config")
     * @Template("@Komoju/admin/komoju_config.twig")
     */
    public function index(Request $request){
        $config_data = $this->config_service->getConfigData();
        $form = $this->createForm(KomojuConfigType::class, $config_data);
        $form->handleRequest($request);
        if($form->isSubmitted() && $form->isValid()){
            $config_data = $form->getData();
            $this->config_service->saveConfig($config_data);
        }

        $komoju_pay_repo = $this->entityManager->getRepository(KomojuPay::class);

        return [
            'form' => $form->createView(),
            'is_connected' => $this->config_service->hasPaymentMethods(),
            'komoju_pays' => $komoju_pay_repo->findBy([], ['sort_no' => 'ASC']),
            'has_repair_data' => $this->repair_service->hasBackupData(),
        ];
    }
    /**
     * @Route("/%eccube_admin_route%/Komoju/config/sync", name="Komoju_admin_sync_methods", methods={"POST"})
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

    /**
     * Repair endpoint: re-link historical orders to the freshly-installed
     * KOMOJU payment methods after an uninstall/reinstall cycle.
     *
     * Triggered explicitly by a button on the config page (with a confirmation
     * dialog), NOT automatically on enable. Runs in its own request-scoped
     * transaction so a failure here cannot poison EC-CUBE's plugin-enable
     * transaction.
     *
     * @Route("/%eccube_admin_route%/Komoju/config/repair", name="Komoju_admin_repair", methods={"POST"})
     */
    public function repair(Request $request){
        $token = $request->headers->get('X-CSRF-Token');
        if (!$this->isCsrfTokenValid('Komoju_config', $token)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
        }

        try {
            $summary = $this->repair_service->repair();
        } catch (\Exception $e) {
            log_error('KOMOJU: repair failed: ' . $e->getMessage());
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return new JsonResponse([
            'success' => true,
            'summary' => $summary,
            'message' => trans('komoju_payment.admin.config.repair.success', [
                '%orders_relinked%' => $summary['orders_relinked'],
                '%orders_restored%' => $summary['orders_restored'],
                '%orphans_deleted%' => $summary['orphans_deleted'],
            ]),
        ]);
    }
}
