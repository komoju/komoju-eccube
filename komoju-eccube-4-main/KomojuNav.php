<?php
/*
* Plugin Name : KomojuPaymentGateway
*
* Copyright (C) 2018 Subspire Inc. All Rights Reserved.
* http://www.subspire.co.jp/
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

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
                'name' => 'komoju_multipay.admin.nav.label',
                'icon' => 'fa-money-check-alt',
                'children' => [
                    'Komoju_config' => [
                        'name' => 'komoju_multipay.admin.nav.config',
                        'url' => 'Komoju_admin_config',
                    ],
                    'Komoju_log' => [
                        'name' => 'komoju_multipay.admin.nav.log',
                        'url' => 'Komoju_admin_log',
                    ]
                ],
            ],
        ];
    }
}