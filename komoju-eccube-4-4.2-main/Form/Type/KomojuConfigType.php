<?php

namespace Plugin\Komoju42\Form\Type;

use Plugin\Komoju42\Entity\KomojuConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Validator\Constraints as Assert;


class KomojuConfigType extends AbstractType{

    private function isCustomApiUrl(): bool
    {
        $url = getenv('KOMOJU_API_URL');
        return $url && $url !== 'https://komoju.com';
    }

    public function buildForm(FormBuilderInterface $builder, array $option){
        $keyConstraints = function ($emptyMessage, $regexMessage) {
            $constraints = [
                new Assert\NotBlank(array('message' => trans($emptyMessage))),
            ];
            if (!$this->isCustomApiUrl()) {
                $constraints[] = new Assert\Regex(array(
                    'pattern' => '/^\w+$/',
                    'match' => true,
                    'message' => trans($regexMessage),
                ));
            }
            return $constraints;
        };

        $builder
            ->add('publishable_key', TextType::class, [
                'required'  =>  true,
                'constraints' => $keyConstraints(
                    'komoju_payment.admin.config.error.publishable_key.empty',
                    'komoju_payment.admin.config.error.publishable_key.regex_invalid'
                )
            ])
            ->add('secret_key', TextType::class, [
                'required'      =>  true,
                'constraints'   =>  $keyConstraints(
                    'komoju_payment.admin.config.error.secret_key.empty',
                    'komoju_payment.admin.config.error.secret_key.regex_invalid'
                )
            ])
            ->add('merchant_uuid', TextType::class, [
                'required'      =>  true,
                'constraints'    =>  $keyConstraints(
                    'komoju_payment.admin.config.error.merchant_uuid.empty',
                    'komoju_payment.admin.config.error.merchant_uuid.regex_invalid'
                )
            ])
            ->add('capture_on', ChoiceType::class, [
                'required'  =>  true,
                'expanded'  =>  false,
                'data'      =>  true,
                'choices'   =>  [
                    'komoju_payment.admin.config.label.capture_on.auth_only' => false,
                    'komoju_payment.admin.config.label.capture_on.auth_capture' => true,
                ]
            ])
            ->add('webhook_secret', TextType::class, [
                'required'  =>  false,
            ])
            ->add('logging_enabled', CheckboxType::class, [
                'required' => false,
            ])
            ->add('log_retention_days', IntegerType::class, [
                'required' => false,
                'attr' => ['min' => 1],
                'constraints' => [
                    new Assert\PositiveOrZero(),
                ],
            ])
            ->add('order_number_format', TextType::class, [
                'required' => false,
                'attr' => ['placeholder' => 'ECC-{order_no}'],
            ]);
    }

    public function getBlockPrefix()
    {
        return 'Komoju_config';
    }
}