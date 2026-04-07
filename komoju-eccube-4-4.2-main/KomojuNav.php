<?php
/*
* Plugin Name : KomojuPaymentGateway
*
* Copyright (C) 2026 KOMOJU Co., Ltd. All Rights Reserved.
* https://komoju.com/
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Plugin\komoju42;

use Eccube\Common\EccubeNav;

class KomojuNav implements EccubeNav
{
    /**
     * @return array
     */
    public static function getNav()
    {
        return [
            'komoju' => [
                'name' => 'komoju_multipay.admin.nav.label',
                'icon' => 'fa-money-check-alt',
                'children' => [                    
                    'komoju_config' => [
                        'name' => 'komoju_multipay.admin.nav.config',
                        'url' => 'komoju42_admin_config',
                    ],
                    'komoju_log' => [
                        'name' => 'komoju_multipay.admin.nav.log',
                        'url' => 'komoju42_admin_log',
                    ]
                ],
            ],
        ];
    }
}