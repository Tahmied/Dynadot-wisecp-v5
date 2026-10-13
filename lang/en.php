<?php
/**
 * WISECP · Web Hosting Billing and Digital Services Platform
 *
 * Copyright (c) WISECP LLC — All rights reserved.
 * Unlicensed copying, distribution or use is prohibited.
 *
 * Terms of service: https://wisecp.com/terms-of-service
 */
return [
    'name'        => 'Dynadot',
    'description' => '',
    'fields'      => [
        'key'    => 'API Key',
        'coupon' => 'Coupon Codes',
    ],
    'desc'        => [
        'key'    => '',
        'coupon' => 'Optional. One or more coupon codes separated by commas. They are tried in order on every domain registration, transfer and renewal this module places at Dynadot; if Dynadot accepts none of them, the order goes through at the regular price. Leave it empty to order without a coupon.',
    ],
    'error2'      => 'Domain and extension information is missing.',
    'error6'      => 'Please enter the API information.',
];
