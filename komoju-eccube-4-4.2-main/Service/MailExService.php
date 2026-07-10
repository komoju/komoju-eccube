<?php

namespace Plugin\Komoju42\Service;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\BaseInfo;
use Eccube\Entity\MailTemplate;
use Eccube\Entity\MailHistory;
use Eccube\Service\MailService;
use Eccube\Event\EventArgs;
use Eccube\Repository\MailHistoryRepository;
use Eccube\Repository\MailTemplateRepository;
use Eccube\Repository\BaseInfoRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Eccube\Common\EccubeConfig;
use Plugin\Komoju42\Service\ConfigService;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mailer\MailerInterface;
use Psr\Container\ContainerInterface;

class MailExService extends MailService{

    protected $em;
    protected $mailHistoryRepository;


    public function __construct(
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        MailTemplateRepository $mailTemplateRepository,
        MailHistoryRepository $mailHistoryRepository,
        BaseInfoRepository $baseInfoRepository,
        EventDispatcherInterface $eventDispatcher,
        \Twig\Environment $twig,
        EccubeConfig $eccubeConfig,
        ?ContainerInterface $container = null
        ){
        $this->em = $entityManager;

        // MailService::__construct() takes 8 args on EC-CUBE 4.2 (last is
        // $container) but 7 on 4.3. Forward $container only when needed.
        $parentParamCount = (new \ReflectionMethod(MailService::class, '__construct'))
            ->getNumberOfParameters();

        $parentArgs = [$mailer, $mailTemplateRepository, $mailHistoryRepository, $baseInfoRepository, $eventDispatcher, $twig, $eccubeConfig];
        if ($parentParamCount >= 8) {
            $parentArgs[] = $container;
        }

        parent::__construct(...$parentArgs);
        $this->mailHistoryRepository = $mailHistoryRepository;
    }

    public function sendRefundRedirectMail($Order, $redirect_url){
        $template = $this->em->getRepository(MailTemplate::class)->findOneBy([
            'name'  =>  ConfigService::MAIL_TEMPLATE_REFUND_REDIRECT
        ]);
        $body = $this->twig->render($template->getFileName(), [
            'Order' => $Order,
            'redirect_url' => $redirect_url,
        ]);

        $message = (new Email())
            ->subject('[' . $this->BaseInfo->getShopName() . '] ' . $template->getMailSubject())
            ->from(new Address($this->BaseInfo->getEmail01(), $this->BaseInfo->getShopName()))
            ->to($Order->getEmail())
            ->bcc($this->BaseInfo->getEmail01())
            ->replyTo($this->BaseInfo->getEmail03())
            ->returnPath($this->BaseInfo->getEmail04());

        // HTMLテンプレートが存在する場合
        $htmlFileName = $this->getHtmlTemplate($template->getFileName());
        if (!is_null($htmlFileName)) {
            $htmlBody = $this->twig->render($htmlFileName, [
                'Order' => $Order,
                'redirect_url'  => $redirect_url
            ]);

            $message->text($body, 'text/plain')
                ->html($htmlBody, 'text/html');
        } else {
            $message->text($body);
        }
        $count = $this->mailer->send($message);

        $MailHistory = new MailHistory();
        $MailHistory->setMailSubject($message->getSubject())
            ->setMailBody($message->getTextBody())
            ->setOrder($Order)
            ->setSendDate(new \DateTime());

        // Symfony Mailer (EC-CUBE 4.2/4.3): store the rendered HTML body
        // directly. The old SwiftMailer getChildren() API no longer exists.
        $htmlBody = $message->getHtmlBody();
        if (!empty($htmlBody)) {
            $MailHistory->setMailHtmlBody($htmlBody);
        }

        $this->mailHistoryRepository->save($MailHistory);

        log_info('Order refund redirect mail sent', ['count' => $count]);

        return $message;
    }
}