<?php

namespace Plugin\Komoju42;

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
                        'url' => 'Komoju42_admin_config',
                    ],
                    'Komoju_log' => [
                        'name' => 'komoju_payment.admin.nav.log',
                        'url' => 'Komoju42_admin_log',
                    ]
                ],
            ],
        ];
    }
}