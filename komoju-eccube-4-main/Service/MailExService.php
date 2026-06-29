<?php

namespace Plugin\Komoju\Service;

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
use Plugin\Komoju\Service\ConfigService;

class MailExService extends MailService{

    protected $em;
    protected $mailHistoryRepository;


    public function __construct(
        EntityManagerInterface $entityManager,
        \Swift_Mailer $mailer,
        MailTemplateRepository $mailTemplateRepository,
        MailHistoryRepository $mailHistoryRepository,
        BaseInfoRepository $baseInfoRepository,
        EventDispatcherInterface $eventDispatcher,
        \Twig_Environment $twig,
        EccubeConfig $eccubeConfig
        ){
        $this->em = $entityManager;

        // Forward only as many args as the parent constructor declares,
        // in case MailService's arity changes across EC-CUBE versions.
        $parentParamCount = (new \ReflectionMethod(MailService::class, '__construct'))
            ->getNumberOfParameters();

        $parentArgs = [$mailer, $mailTemplateRepository, $mailHistoryRepository, $baseInfoRepository, $eventDispatcher, $twig, $eccubeConfig];
        $parentArgs = array_slice($parentArgs, 0, $parentParamCount);

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

        $message = (new \Swift_Message())
            ->setSubject('['.$this->BaseInfo->getShopName().'] '.$template->getMailSubject())
            ->setFrom([$this->BaseInfo->getEmail01() => $this->BaseInfo->getShopName()])
            ->setTo([$Order->getEmail()])
            ->setBcc($this->BaseInfo->getEmail01())
            ->setReplyTo($this->BaseInfo->getEmail03())
            ->setReturnPath($this->BaseInfo->getEmail04());

        // HTMLテンプレートが存在する場合
        $htmlFileName = $this->getHtmlTemplate($template->getFileName());
        if (!is_null($htmlFileName)) {
            $htmlBody = $this->twig->render($htmlFileName, [
                'Order' => $Order,
                'redirect_url'  => $redirect_url
            ]);

            $message
                ->setContentType('text/plain; charset=UTF-8')
                ->setBody($body, 'text/plain')
                ->addPart($htmlBody, 'text/html');
        } else {
            $message->setBody($body);
        }
        $count = $this->mailer->send($message);

        $MailHistory = new MailHistory();
        $MailHistory->setMailSubject($message->getSubject())
            ->setMailBody($message->getBody())
            ->setOrder($Order)
            ->setSendDate(new \DateTime());

        // HTML用メールの設定
        $multipart = $message->getChildren();
        if (count($multipart) > 0) {
            $MailHistory->setMailHtmlBody($multipart[0]->getBody());
        }

        $this->mailHistoryRepository->save($MailHistory);

        log_info('Order refund redirect mail sent', ['count' => $count]);

        return $message;
    }
}