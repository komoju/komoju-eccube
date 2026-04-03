<?php

namespace Plugin\komoju\Form\Type;

use Plugin\komoju\Entity\KomojuPay;
use Plugin\komoju\Entity\KomojuConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Validator\Constraints as Assert;


class KomojuConfigType extends AbstractType{
    
    public function buildForm(FormBuilderInterface $builder, array $option){
        $builder
            ->add('publishable_key', TextType::class, [
                'required'  =>  true,
                'constraints' => [
                    new Assert\NotBlank(array(
                        'message' => trans('komoju_multipay.admin.config.error.publishable_key.empty')
                        )
                    ),
                    new Assert\Regex(array(
                            'pattern' => '/^\w+$/',
                            'match' => true,
                            'message' => trans('komoju_multipay.admin.config.error.publishable_key.regex_invalid')
                        )
                    )
                ]
            ])
            ->add('secret_key', TextType::class, [
                'required'      =>  true,
                'constraints'   =>  [
                    new Assert\NotBlank(array(
                        'message'   =>  trans('komoju_multipay.admin.config.error.secret_key.empty')
                    )),
                    new Assert\Regex(array(
                            'pattern' => '/^\w+$/',
                            'match' => true,
                            'message' => trans('komoju_multipay.admin.config.error.secret_key.regex_invalid')
                        )
                    )
                ]
            ])
            ->add('merchant_uuid', TextType::class, [
                'required'      =>  true,
                'constraints'    =>  [
                    new Assert\NotBlank(array(
                        'message'   =>  trans('komoju_multipay.admin.config.error.merchant_uuid.empty')
                    )),
                    new Assert\Regex(array(
                            'pattern' => '/^\w+$/',
                            'match' => true,
                            'message' => trans('komoju_multipay.admin.config.error.merchant_uuid.regex_invalid')
                        )
                    )
                ]
            ])
            ->add('capture_on', ChoiceType::class, [
                'required'  =>  true,
                'expanded'  =>  false,
                'choices'   =>  [
                    'komoju_multipay.admin.config.label.capture_on.auth_only' => false,
                    'komoju_multipay.admin.config.label.capture_on.auth_capture' => true,
                ]
            ])
            ->add('komoju_pays', KomojuPayType::class, [
                'required' => true,
                'label' => 'komoju_multipay.admin.config.label.komoju_pay_type',
                'expanded' => true,
                'multiple' => true,
            ])
            ->add('webhook_secret', TextType::class, [
                'required'  =>  false,                
            ]);
    }    
}