<?php
/*
* Plugin Name : StripePaymentGateway
*
* Copyright (C) 2018 Subspire Inc. All Rights Reserved.
* http://www.subspire.co.jp/
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Plugin\komoju;

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
                        'url' => 'komoju_admin_config',
                    ],
                    'komoju_log' => [
                        'name' => 'komoju_multipay.admin.nav.log',
                        'url' => 'komoju_admin_log',
                    ]
                ],
            ],
        ];
    }
}