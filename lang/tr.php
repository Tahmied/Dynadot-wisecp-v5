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
        'key'    => 'API Anahtarı',
        'coupon' => 'Kupon Kodları',
    ],
    'desc'        => [
        'key'    => '',
        'coupon' => 'İsteğe bağlı. Virgülle ayrılmış bir ya da daha çok kupon kodu. Bu modülün Dynadot\'ta yaptığı her alan adı kaydı, transferi ve yenilemesinde kodlar sırayla denenir; Dynadot hiçbirini kabul etmezse sipariş normal fiyattan yapılır. Kuponsuz sipariş için boş bırakın.',
    ],
    'error2'      => 'Alan adı ve uzantı bilgisi eksik.',
    'error6'      => 'Lütfen API bilgilerini girin.',
];
