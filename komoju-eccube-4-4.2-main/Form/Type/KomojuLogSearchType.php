<?php

namespace Plugin\Komoju42\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;

class KomojuLogSearchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('order_id', TextType::class, [
                'required' => false,
                'label' => 'komoju_multipay.admin.log.search.order_id',
            ])
            ->add('api', ChoiceType::class, [
                'required' => false,
                'label' => 'komoju_multipay.admin.log.search.api',
                'expanded' => false,
                'placeholder' => 'komoju_multipay.admin.log.search.api.all',
                'choices' => [
                    'komoju_multipay.admin.log.search.api.webhook' => 'webhook',
                    'komoju_multipay.admin.log.search.api.capture' => 'capture',
                    'komoju_multipay.admin.log.search.api.refund' => 'refund',
                    'komoju_multipay.admin.log.search.api.retrieve' => 'retrieve',
                ],
            ])
            ->add('date_from', DateType::class, [
                'required' => false,
                'label' => 'komoju_multipay.admin.log.search.date_from',
                'widget' => 'single_text',
                'input' => 'datetime',
            ])
            ->add('date_to', DateType::class, [
                'required' => false,
                'label' => 'komoju_multipay.admin.log.search.date_to',
                'widget' => 'single_text',
                'input' => 'datetime',
            ])
            ->add('keyword', TextType::class, [
                'required' => false,
                'label' => 'komoju_multipay.admin.log.search.keyword',
            ]);
    }
}
