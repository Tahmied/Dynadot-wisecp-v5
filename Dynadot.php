<?php
/**
 * WISECP · Web Hosting Billing and Digital Services Platform
 *
 * Copyright (c) WISECP LLC — All rights reserved.
 * Unlicensed copying, distribution or use is prohibited.
 *
 * Terms of service: https://wisecp.com/terms-of-service
 */
namespace WISECP\Modules\Registrars;

use Exception;
use RegistrarModule;
use WISECP\Modules\Registrars\Dynadot\ApiClient;

class Dynadot extends RegistrarModule
{
    private const CONTACT_PARAM = ['registrant' => 'registrant', 'administrative' => 'admin', 'technical' => 'technical', 'billing' => 'billing'];
    private const CONTACT_NODE = ['registrant' => 'Registrant', 'administrative' => 'Admin', 'technical' => 'Technical', 'billing' => 'Billing'];

    public function __construct()
    {
        parent::__construct();
    }



    private function initApi(): void
    {
        if ($this->api)
            return;
        include_once __DIR__ . DS . 'ApiClient.php';

        $settings = $this->config['settings'] ?? [];
        $key = $this->decode_str($settings['key'] ?? '');
        if ($key)
            $settings['key'] = $key;

        $this->api = new ApiClient($settings);
        $this->api->logger = fn($action, $req, $resp, $processed = '') => $this->save_log($action, $req, $resp, $processed);
    }



    public function config_fields($settings = []): array
    {
        return [
            'key' => [
                'type' => 'password',
                'name' => $this->lang['fields']['key'] ?? 'API Key',
                'description' => $this->lang['desc']['key'] ?? '',
                'value' => $settings['key'] ?? '',
            ],
            'coupon' => [
                'type' => 'text',
                'name' => $this->lang['fields']['coupon'] ?? 'Coupon Code',
                'description' => $this->lang['desc']['coupon'] ?? '',
                'value' => $settings['coupon'] ?? '',
            ],
        ];
    }

    public function config_fields_filter($fields = []): array
    {
        if (!is_array($fields))
            return [];
        if (array_key_exists('coupon', $fields))
            $fields['coupon'] = implode(', ', self::coupon_list((string) $fields['coupon']));

        return $fields;
    }

    public function testConnection($config = []): bool
    {
        $settings = $config['settings'] ?? [];
        if (($settings['key'] ?? '') === '') {
            $this->error = $this->lang['error6'] ?? 'Please enter the API information.';
            return false;
        }

        include_once __DIR__ . DS . 'ApiClient.php';
        $api = new ApiClient($settings);
        if ($api->call('list_domain') === false) {
            $this->error = $api->error;
            return false;
        }

        return true;
    }



    public function check($sld = null, $tlds = []): array
    {
        if ($sld == '' || !$tlds) {
            $this->error = $this->lang['error2'] ?? 'Domain and extension information did not come';
            return [];
        }

        $sld = idn_to_ascii($sld, 0, INTL_IDNA_VARIANT_UTS46);
        if (!is_array($tlds))
            $tlds = [$tlds];

        $this->initApi();

        $result = [];
        foreach ($tlds as $tld) {
            $response = $this->api->call('search', ['domain0' => $sld . '.' . $tld, 'show_price' => 0]);
            if ($response === false)
                continue;

            $available = ($response['SearchResponse']['SearchResults'][0]['Available'] ?? '') === 'yes';
            $result[$tld] = ['status' => $available ? 'available' : 'unavailable'];
        }

        return $result;
    }



    public function register(): array|bool
    {
        $this->initApi();
        [$domain, , $tld] = $this->domain_parts();

        $year = (int) ($this->options['year'] ?? $this->service['period_time'] ?? 1) ?: 1;
        $dns = $this->options['dns'] ?? [];
        $whois = $this->options['whois'] ?? [];
        $wprivacy = (bool) ($this->addon_params['whois-privacy'] ?? false);
        $eppCode = $this->options['tcode'] ?? '';

        $params = ['domain' => $domain, 'duration' => $year];

        foreach (self::CONTACT_PARAM as $wcpType => $apiKey) {
            $contactId = $this->create_contact($whois[$wcpType] ?? []);
            if ($contactId === false)
                return false;
            $params[$apiKey . '_contact'] = $contactId;
        }

        if ($eppCode)
            $params['auth'] = $eppCode;

        $requireDocs = $this->config['settings']['doc-fields'][$tld] ?? [];
        if ($requireDocs) {
            if (!$this->docs) {
                $this->error = 'Required documents for domain name not defined';
                return false;
            }
            foreach ($requireDocs as $docId => $doc) {
                if (!isset($this->docs[$docId]) || strlen($this->docs[$docId]) < 1) {
                    $this->error = 'The document "' . self::get_doc_lang($doc['name']) . '" is not specified!';
                    return false;
                }
                $value = $this->docs[$docId];
                if (($doc['type'] ?? '') === 'file')
                    $value = base64_encode(file_get_contents($value));
                $params[$docId] = $value;
            }
        }

        $response = $this->call_with_coupons($eppCode ? 'transfer' : 'register', $params);
        if ($response === false) {
            $this->error = $this->api->error;
            return false;
        }

        $resType = $eppCode ? 'Transfer' : 'Register';
        if (($response[$resType . 'Response']['Status'] ?? '') !== 'success') {
            $this->error = $response[$resType . 'Response']['Error'] ?? 'Unknown';
            return false;
        }

        sleep(3);

        if ($dns) {
            $nsParams = ['domain' => $domain];
            foreach (array_values($dns) as $k => $ns)
                $nsParams['ns' . $k] = $ns;
            $this->api->call('set_ns', $nsParams);
        }

        if ($wprivacy)
            $this->api->call('set_privacy', ['domain' => $domain, 'option' => 'full']);

        return ['status' => 'SUCCESS', 'config' => ['entityID' => 1]];
    }

    public function transfer(): array|bool
    {
        return $this->register();
    }

    public function renew(): array|bool
    {
        $this->initApi();
        [$domain] = $this->domain_parts();
        $year = (int) ($this->options['year'] ?? $this->service['period_time'] ?? 1) ?: 1;

        if ($this->call_with_coupons('renew', ['domain' => $domain, 'duration' => $year]) === false) {
            $this->error = $this->api->error;
            return false;
        }

        return true;
    }



    public function save_nameservers(array $dns): bool
    {
        $this->initApi();
        [$domain] = $this->domain_parts();

        $params = ['domain' => $domain];
        foreach (array_values($dns) as $k => $ns)
            $params['ns' . $k] = idn_to_ascii($ns, 0, INTL_IDNA_VARIANT_UTS46);

        if ($this->api->call('set_ns', $params) === false) {
            $this->error = $this->api->error;
            return false;
        }

        return true;
    }



    public function get_contacts(): array|false
    {
        $this->initApi();
        $info = $this->domain_info();
        if ($info === false)
            return false;

        return $this->map_contacts($info['Whois'] ?? []);
    }

    public function save_contacts(array $whois): bool
    {
        $this->initApi();
        $info = $this->domain_info();
        if ($info === false)
            return false;

        foreach (self::CONTACT_NODE as $wcpType => $node) {
            $contactId = $info['Whois'][$node]['ContactId'] ?? 0;
            $data = $this->whois_process($whois[$wcpType] ?? []);
            $data['contact_id'] = $contactId;

            $modify = $this->api->call('edit_contact', $data);
            if ($modify === false) {
                $this->error = $this->api->error;
                return false;
            }
        }

        return true;
    }



    public function is_inactive(): bool
    {
        $this->initApi();
        $info = $this->domain_info();
        if ($info === false)
            return false;

        return ($info['Hold'] ?? '') === 'yes';
    }

    public function get_transfer_lock(): string
    {
        $this->initApi();
        $info = $this->domain_info();
        if ($info === false)
            throw new Exception($this->error ?: 'Failed to retrieve transfer lock status');

        return ($info['Locked'] ?? '') === 'yes' ? 'active' : 'passive';
    }

    public function get_whois_privacy(): string
    {
        $this->initApi();
        $info = $this->domain_info();
        if ($info === false)
            throw new Exception($this->error ?: 'Failed to retrieve whois privacy status');

        $privacy = $info['Privacy'] ?? 'none';
        return ($privacy !== 'none' && $privacy !== 'off') ? 'active' : 'passive';
    }

    public function toggle_whois_privacy(string $status): bool
    {
        $this->initApi();
        [$domain] = $this->domain_parts();

        $wpp = $this->api->call('set_privacy', ['domain' => $domain, 'option' => $status === 'enable' ? 'full' : 'off']);
        if ($wpp === false) {
            $this->error = $this->api->error;
            return false;
        }
        if (($wpp['SetPrivacyResponse']['Status'] ?? '') !== 'success') {
            $this->error = $wpp['SetPrivacyResponse']['Error'] ?? 'Unknown';
            return false;
        }

        return true;
    }

    public function get_auth_code(): string|bool
    {
        $this->initApi();
        [$domain] = $this->domain_parts();

        $details = $this->api->call('get_transfer_auth_code', ['domain' => $domain]);
        if ($details === false) {
            $this->error = $this->api->error;
            return false;
        }

        return $details['GetTransferAuthCodeResponse']['AuthCode'] ?? '';
    }



    public function addon_create(array $addon): bool
    {
        if (($addon['addon_key'] ?? '') !== 'whois-privacy')
            return true;
        return $this->toggle_whois_privacy('enable');
    }

    public function addon_cancel(array $addon): bool
    {
        if (($addon['addon_key'] ?? '') !== 'whois-privacy')
            return true;
        return $this->toggle_whois_privacy('disable');
    }



    public function sync(): array|false
    {
        $this->initApi();
        $info = $this->domain_info();
        if ($info === false)
            return false;
        if (!$info) {
            $this->error = 'Dynadot returned no information for this domain.';
            return false;
        }

        return [
            'creationtime' => self::ms_date('Y-m-d H:i', $info['Registration'] ?? ''),
            'endtime' => self::ms_date('Y-m-d H:i', $info['Expiration'] ?? ''),
            'status' => ($info['Hold'] ?? '') === 'yes' ? 'expired' : 'active',
        ];
    }

    public function transfer_sync(): array|false
    {
        return $this->sync();
    }



    public function get_info(array $params = []): array|false
    {
        $this->initApi();
        $domain = idn_to_ascii($params['domain'] ?? $this->options['domain'] ?? $this->service['name'] ?? '', 0, INTL_IDNA_VARIANT_UTS46);

        $info = $this->domain_info($domain);
        if ($info === false)
            return false;

        $result = [
            'creation_time' => self::ms_date('Y-m-d H:i', $info['Registration'] ?? ''),
            'end_time' => self::ms_date('Y-m-d H:i', $info['Expiration'] ?? ''),
        ];

        $privacy = $info['Privacy'] ?? 'none';
        if ($privacy !== 'none')
            $result['whois_privacy'] = ['status' => $privacy !== 'off' ? 'enable' : 'disable'];

        $nsInfo = $this->api->call('get_ns', ['domain' => $domain]);
        if ($nsInfo !== false && (int) ($nsInfo['GetNsResponse']['ResponseCode'] ?? -1) === 0) {
            $k = 0;
            foreach ($nsInfo['GetNsResponse']['NsContent'] ?? [] as $ns) {
                if ($ns === 'Name Servers')
                    continue;
                $k++;
                $result['ns' . $k] = $ns;
            }
        }

        $whois = $this->map_contacts($info['Whois'] ?? []);
        if ($whois)
            $result['whois'] = $whois;

        $result['transferlock'] = ($info['Locked'] ?? '') === 'yes';

        return $result;
    }

    public function domains()
    {
        $this->initApi();

        $data = $this->api->call('list_domain');
        if ($data === false) {
            $this->error = $this->api->error;
            return false;
        }

        $result = [];
        foreach ($data['ListDomainInfoResponse']['MainDomains'] ?? [] as $res) {
            if (!is_array($res))
                continue;
            $domain = $res['Name'] ?? '';
            if (!$domain)
                continue;

            $domain = idn_to_utf8($domain, 0, INTL_IDNA_VARIANT_UTS46);
            $order_id = 0;
            $user_data = [];
            $isImported = \WDB::select('id,owner_id AS user_id')->from('users_products');
            $isImported->where('type', '=', 'domain', '&&');
            $isImported->where('name', '=', $domain);
            $isImported = $isImported->build() ? $isImported->getAssoc() : false;
            if ($isImported) {
                $order_id = $isImported['id'];
                $user_data = \User::getData($isImported['user_id'], 'id,full_name,company_name', 'array');
            }

            $result[] = [
                'domain' => $domain,
                'creation_date' => self::ms_date('Y-m-d', $res['Registration'] ?? ''),
                'end_date' => self::ms_date('Y-m-d', $res['Expiration'] ?? ''),
                'order_id' => $order_id,
                'user_data' => $user_data,
            ];
        }

        return $result;
    }



    private function tld_catalogue(): array|false
    {
        $this->initApi();

        $maxPages = 30;
        $currency = '';
        $result = [];
        $query = ['currency' => 'usd', 'show_multi_year' => 'true'];

        for ($i = 0; $i < $maxPages; $i++) {
            $response = $this->api->rest('domains/get_tld_price', $query);
            if ($response === false) {
                $this->error = $this->api->error;
                return false;
            }

            $data = $response['data'] ?? [];
            $rows = $data['tld_price_list'] ?? [];
            if (!is_array($rows) || !$rows)
                break;

            if ($currency === '')
                $currency = strtoupper(trim((string) ($data['currency'] ?? '')));

            $added = 0;
            foreach ($rows as $row) {
                $name = strtolower(ltrim(trim((string) ($row['tld'] ?? '')), '.'));
                if ($name === '' || isset($result[$name]))
                    continue;

                $min = max(1, (int) ($row['min_duration'] ?? 1));
                $max = min(10, max($min, (int) ($row['max_duration'] ?? 0) ?: $min));

                $registerTotals = self::year_totals($row['all_years_register_price'] ?? [], $min, $max);
                $renewTotals = self::year_totals($row['all_years_renew_price'] ?? [], $min, $max);
                if (!isset($registerTotals[$min]))
                    continue;

                // keep only the years that have BOTH a register and a renew total
                $both = array_keys(array_intersect_key($registerTotals, $renewTotals));
                if ($both) {
                    $registerTotals = array_intersect_key($registerTotals, array_flip($both));
                    $renewTotals = array_intersect_key($renewTotals, array_flip($both));
                }
                $maxYears = max(array_keys($registerTotals));

                // transfer_price can be "--" (not transferable), so don't treat it as 0
                $tp = str_replace(',', '', (string) ($row['transfer_price'] ?? ''));

                $result[$name] = [
                    'register' => round($registerTotals[$min] / $min, 2),
                    'renewal' => isset($renewTotals[$min]) ? round($renewTotals[$min] / $min, 2) : 0.0,
                    'transfer' => is_numeric($tp) ? round((float) $tp, 2) : 0.0,
                    'transfer_ok' => is_numeric($tp),
                    'register_totals' => $registerTotals,
                    'renew_totals' => $renewTotals,
                    'min_years' => $min,
                    'max_years' => $maxYears,
                    'currency' => $currency,
                ];
                $added++;
            }

            // page_size 0 = API returned everything in one response
            $pageSize = (int) ($data['page_size'] ?? 0);
            if ($pageSize < 1 || $added === 0 || count($rows) < $pageSize)
                break;

            $query['page_index'] = (int) ($data['page'] ?? 0) + 1;
            $query['count_per_page'] = $pageSize;
        }

        return $result;
    }

    // Dynadot baler bal returns cumulative totals like - index 0 = price for 1 year, index 1 = 2 years...
    private static function year_totals(mixed $prices, int $min, int $max): array
    {
        if (!is_array($prices))
            return [];
        $prices = array_values($prices);
        $offset = ($min > 1 && count($prices) === $max - $min + 1) ? $min : 1;

        $totals = [];
        $prev = 0.0;
        for ($year = $min; $year <= $max; $year++) {
            $value = $prices[$year - $offset] ?? null;
            if ($value === null)
                break;
            $value = str_replace(',', '', (string) $value);
            if (!is_numeric($value))
                break;
            $value = round((float) $value, 2);
            if ($value < $prev)
                break; // bad data: cumulative total went down
            $totals[$year] = $value;
            $prev = $value;
        }

        return $totals;
    }

    public function cost_prices($type = 'domain')
    {
        $catalogue = $this->tld_catalogue();
        if ($catalogue === false)
            return false;

        $result = [];
        foreach ($catalogue as $name => $row)
            $result[$name] = [
                'register' => $row['register'],
                'transfer' => $row['transfer'],
                'renewal' => $row['renewal'],
            ];

        return $result;
    }

    public function tlds()
    {
        $catalogue = $this->tld_catalogue();
        if ($catalogue === false)
            return false;

        $costCid = (int) ($this->config['settings']['cost-currency'] ?? 4);
        $result = [];
        foreach ($catalogue as $name => $row) {
            $known = $row['currency'] !== '' ? \Money::Currency($row['currency']) : null;
            $currency = (int) ($known['id'] ?? 0) > 0 ? $row['currency'] : $costCid;

            $pricing = ['register' => [], 'renewal' => [], 'transfer' => []];
            foreach ($row['register_totals'] as $year => $total)
                $pricing['register'][$year] = [$currency => ['cost' => $total]];
            foreach ($row['renew_totals'] as $year => $total)
                $pricing['renewal'][$year] = [$currency => ['cost' => $total]];
            if ($row['transfer_ok'])
                $pricing['transfer'][1] = [$currency => ['cost' => $row['transfer']]];

            $result[$name] = [
                'min_years' => $row['min_years'],
                'max_years' => $row['max_years'],
                'cost_currency' => $currency,
                'register' => $row['register'],
                'renewal' => $row['renewal'],
                'transfer' => $row['transfer'],
                'pricing' => $pricing,
            ];
        }

        return $result;
    }

    public function get_dns_records(): array|false
    {
        $this->initApi();
        $info = $this->domain_info();
        if ($info === false)
            return false;

        $nss = $info['NameServerSettings'] ?? [];
        if (($nss['Type'] ?? '') !== 'Dynadot DNS')
            return [];

        $result = [];
        foreach ($nss['MainDomains'] ?? [] as $k => $r) {
            $record = [
                'identity' => $k,
                'type' => strtoupper($r['RecordType'] ?? ''),
                'name' => '@',
                'value' => $r['Value'] ?? '',
                'ttl' => 7207,
                'priority' => '',
            ];
            if ($record['type'] === 'MX')
                $record['priority'] = $r['Value2'] ?? '';
            $result[] = $record;
        }

        return $result;
    }

    public function add_dns_record($type, $name, $value, $ttl, $priority): bool
    {
        $records = $this->get_dns_records();
        if ($records === false)
            return false;

        $records[] = ['type' => $type, 'value' => $value, 'priority' => $priority ?: 10];

        return $this->set_dns($records);
    }

    public function update_dns_record($type = '', $name = '', $value = '', $identity = '', $ttl = '', $priority = ''): bool
    {
        $records = $this->get_dns_records();
        if ($records === false)
            return false;

        $found = false;
        foreach ($records as &$record)
            if ((string) $record['identity'] === (string) $identity) {
                $found = true;
                $record['type'] = $type;
                $record['value'] = $value;
                $record['priority'] = $priority;
            }
        unset($record);

        if (!$found) {
            $this->error = 'Invalid identity ID';
            return false;
        }

        return $this->set_dns($records);
    }

    public function delete_dns_record($type = '', $name = '', $value = '', $identity = ''): bool
    {
        $records = $this->get_dns_records();
        if ($records === false)
            return false;

        $kept = array_filter($records, fn($r) => (string) $r['identity'] !== (string) $identity);
        if (count($kept) === count($records)) {
            $this->error = 'Invalid identity ID';
            return false;
        }

        return $this->set_dns(array_values($kept));
    }



    private function domain_parts(): array
    {
        $sld = $this->options['sld'] ?? $this->options['name'] ?? '';
        $tld = $this->options['tld'] ?? '';
        $domain = $this->options['domain'] ?? ($sld && $tld ? $sld . '.' . $tld : ($this->service['name'] ?? ''));

        return [
            idn_to_ascii($domain, 0, INTL_IDNA_VARIANT_UTS46),
            idn_to_ascii($sld, 0, INTL_IDNA_VARIANT_UTS46),
            $tld,
        ];
    }

    private function call_with_coupons(string $command, array $params): array|false
    {
        $node = ucfirst($command) . 'Response';
        $response = false;
        foreach ([...self::coupon_list((string) ($this->config['settings']['coupon'] ?? '')), ''] as $coupon) {
            $try = $params;
            if ($coupon !== '')
                $try['coupon'] = $coupon;
            $response = $this->api->call($command, $try);
            $refused = $response === false ? $this->api->answered : ($response[$node]['Status'] ?? 'success') !== 'success';
            if (!$refused)
                return $response;
        }

        return $response;
    }

    private static function ms_date(string $format, mixed $value): string
    {
        $ms = trim((string) (is_scalar($value) ? $value : ''));
        if ($ms === '' || !ctype_digit($ms))
            return '';

        return \DateManager::timetostr($format, intdiv((int) $ms, 1000));
    }

    private static function coupon_list(string $value): array
    {
        $codes = array_map([self::class, 'clean_coupon'], preg_split('/[\s,;]+/', $value) ?: []);

        return array_slice(array_values(array_unique(array_filter($codes, 'strlen'))), 0, 10);
    }

    private static function clean_coupon(string $code): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9._-]/', '', trim($code)), 0, 64);
    }

    private function domain_info(string $domain = ''): array|false
    {
        if ($domain === '')
            [$domain] = $this->domain_parts();

        $response = $this->api->call('domain_info', ['domain' => $domain]);
        if ($response === false) {
            $this->error = $this->api->error;
            return false;
        }

        return $response['DomainInfoResponse']['DomainInfo'] ?? [];
    }

    private function whois_process(array $whois): array
    {
        return [
            'name' => $whois['Name'] ?? '',
            'organization' => $whois['Company'] ?? '',
            'email' => $whois['EMail'] ?? '',
            'address1' => $whois['AddressLine1'] ?? '',
            'address2' => $whois['AddressLine2'] ?? '',
            'city' => $whois['City'] ?? '',
            'state' => $whois['State'] ?? '',
            'zip' => $whois['ZipCode'] ?? '',
            'country' => $whois['Country'] ?? '',
            'phonecc' => $whois['PhoneCountryCode'] ?? '',
            'phonenum' => $whois['Phone'] ?? '',
            'faxcc' => $whois['FaxCountryCode'] ?? '',
            'faxnum' => $whois['Fax'] ?? '',
        ];
    }

    private function create_contact(array $whois): int|false
    {
        $response = $this->api->call('create_contact', $this->whois_process($whois));
        if ($response === false) {
            $this->error = $this->api->error;
            return false;
        }

        $id = (int) ($response['CreateContactResponse']['CreateContactContent']['ContactId'] ?? 0);
        if ($id < 1) {
            $this->error = $response['CreateContactResponse']['Error'] ?? 'Contact id is 0';
            return false;
        }

        return $id;
    }

    private function map_contacts(array $whoisBlock): array
    {
        $whois = [];
        foreach (self::CONTACT_NODE as $wcpType => $node) {
            $contactId = $whoisBlock[$node]['ContactId'] ?? 0;
            if (!$contactId)
                continue;

            $response = $this->api->call('get_contact', ['contact_id' => $contactId]);
            if ($response === false || (int) ($response['GetContactResponse']['ResponseCode'] ?? -1) !== 0)
                continue;

            $c = $response['GetContactResponse']['GetContact'] ?? [];
            $name = $c['Name'] ?? '';
            $smash = \Filter::name_smash($name);

            $whois[$wcpType] = [
                'FirstName' => $smash['first'] ?? '',
                'LastName' => $smash['last'] ?? '',
                'Name' => $name,
                'Company' => $c['Organization'] ?? '',
                'EMail' => $c['Email'] ?? '',
                'AddressLine1' => $c['Address1'] ?? '',
                'AddressLine2' => $c['Address2'] ?? '',
                'City' => $c['City'] ?? '',
                'State' => $c['State'] ?? '',
                'ZipCode' => $c['ZipCode'] ?? '',
                'Country' => $c['Country'] ?? '',
                'PhoneCountryCode' => $c['PhoneCc'] ?? '',
                'Phone' => $c['PhoneNum'] ?? '',
                'FaxCountryCode' => $c['FaxCc'] ?? '',
                'Fax' => $c['FaxNum'] ?? '',
            ];
        }

        return $whois;
    }

    private function set_dns(array $records): bool
    {
        $this->initApi();
        [$domain] = $this->domain_parts();

        $params = ['domain' => $domain];
        foreach (array_values($records) as $k => $r) {
            $params['main_record_type' . $k] = strtolower($r['type']);
            $params['main_record' . $k] = $r['value'];
            if ($r['type'] === 'MX')
                $params['main_recordx' . $k] = $r['priority'] ?? '';
        }

        $response = $this->api->call('set_dns2', $params);
        if ($response === false) {
            $this->error = $this->api->error;
            return false;
        }
        if (($response['SetDnsResponse']['Status'] ?? '') !== 'success') {
            $this->error = $response['SetDnsResponse']['Error'] ?? 'Unknown';
            return false;
        }

        return true;
    }
}
