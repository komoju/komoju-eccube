<?php

namespace Plugin\komoju\Form\Type;

use Eccube\Form\Type\MasterType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Plugin\komoju\Entity\KomojuPay;
use Plugin\komoju\Repository\KomojuPayRepository;

class KomojuPayType extends AbstractType
{
    /**
     * @var KomojuPayRepository
     */
    protected $komoju_pay_repo;

    public function __construct(KomojuPayRepository $komoju_pay_repo){
        $this->komoju_pay_repo = $komoju_pay_repo;
    }
   /**
     * {@inheritdoc}
     */
    public function buildView(FormView $view, FormInterface $form, array $options)
    {
        /** @var KomojuPay[] $komoju_pays */
        $komoju_pays = $options['choice_loader']->loadChoiceList()->getChoices();
        foreach ($komoju_pays as $komoju_pay) {
            $id = $komoju_pay->getId();            
            $view->vars['checked'][$id] = $komoju_pay->isEnabled();
            $view->vars['disp_name'][$id] = $komoju_pay->getDispName();
            // echo "{$komoju_pay->getDispName()} : {$komoju_pay->isEnabled()} <br>";
        }
        // die();
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'class' => KomojuPay::class,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix()
    {
        return 'komoju_pay';
    }

    /**
     * {@inheritdoc}
     */
    public function getParent()
    {
        return MasterType::class;
    }
}
