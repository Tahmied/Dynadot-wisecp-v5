<?php 
return [
    'meta'     => [
        'name'    => 'Dynadot',
        'version' => '1.0',
        'logo'    => 'logo.png',
    ],
    'settings' => [
        'whois-types'      => true,
        'dns-record-types' => ['A', 'AAAA', 'CNAME', 'FORWARD', 'TXT', 'MX', 'STEALTH'],
        'key'              => 'apikeywillbehere',
        'test-mode'        => 0,
        'adp'              => true,
        'cost-currency'    => 15,
        'whidden-amount'   => 0,
        'whidden-currency' => 4,
        'coupon'           => '899COM, SBDYNA13, DYNA12',
        'nameservers'      => [
            'dns1.bengalcloud.com',
            'dns2.bengalcloud.com',
            'dns3.bengalcloud.com',
            'dns4.bengalcloud.com',
        ],
    ],
];
