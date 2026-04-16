<?php

namespace Plugin\Komoju;

use Eccube\Common\EccubeNav;

class KomojuNav implements EccubeNav
{
    /**
     * @return array
     */
    public static function getNav()
    {
        return [
            'Komoju' => [
                'name' => 'komoju_payment.admin.nav.label',
                'icon' => 'fa-money-check-alt',
                'children' => [
                    'Komoju_config' => [
                        'name' => 'komoju_payment.admin.nav.config',
                        'url' => 'Komoju_admin_config',
                    ],
                    'Komoju_log' => [
                        'name' => 'komoju_payment.admin.nav.log',
                        'url' => 'Komoju_admin_log',
                    ]
                ],
            ],
        ];
    }
}