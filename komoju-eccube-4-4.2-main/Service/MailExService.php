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

namespace Plugin\komoju42\Service;

use Psr\Container\ContainerInterface;
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
use Plugin\komoju42\Service\ConfigService;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mailer\MailerInterface;

class MailExService extends MailService{
    
    protected $container;
    protected $rec_order_repo;
    protected $em;
    protected $mailHistoryRepository;
    

    public function __construct(
        ContainerInterface $container,
        MailerInterface $mailer,
        MailTemplateRepository $mailTemplateRepository,
        MailHistoryRepository $mailHistoryRepository,
        BaseInfoRepository $baseInfoRepository,
        EventDispatcherInterface $eventDispatcher,
        \Twig_Environment $twig,
        EccubeConfig $eccubeConfig
        ){
        $this->container = $container;
        $this->em = $this->container->get('doctrine.orm.entity_manager');
        
        parent::__construct( $mailer, $mailTemplateRepository, $mailHistoryRepository, $baseInfoRepository, $eventDispatcher, $twig, $eccubeConfig);
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
    protected function initialMsg($Customer, $template){
        $message = (new Email())
            ->subject('[' . $this->BaseInfo->getShopName() . '] ' . $template->getMailSubject())
            ->from(new Address($this->BaseInfo->getEmail01(), $this->BaseInfo->getShopName()))
            ->to($Customer->getEmail())
            ->bcc($this->BaseInfo->getEmail01())
            ->replyTo($this->BaseInfo->getEmail03())
            ->returnPath($this->BaseInfo->getEmail04());
        return $message;
    }
    protected function isHtml($template_path){
        $fileName = explode('.', $template_path);
        return in_array("html", $fileName);
    }
}