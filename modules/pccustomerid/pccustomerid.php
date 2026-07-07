<?php
/**
 * PC Customer ID for customers adress
 *
 * @author    perpetualcode
 * @copyright Perpetualcode
 * @license   Proprietary
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Pccustomerid extends Module
{
    const CONFIG_ENABLED = 'PCCUSTOMERID_ENABLED';
    const CONFIG_EXCLUDED_SHOP_IDS = 'PCCUSTOMERID_EXCLUDED_SHOP_IDS';
    const CONFIG_CH_COUNTRY_ID = 'PCCUSTOMERID_CH_COUNTRY_ID';
    const CONFIG_ES_COUNTRY_ID = 'PCCUSTOMERID_ES_COUNTRY_ID';
    const CONFIG_CANARY_STATE_IDS = 'PCCUSTOMERID_CANARY_STATE_IDS';
    const CONFIG_CANARY_POSTCODE_REGEX = 'PCCUSTOMERID_CANARY_POSTCODE_REGEX';
    const CONFIG_USE_DNI_FIELD = 'PCCUSTOMERID_USE_DNI_FIELD';

    const FIELD_NAME = 'dni';
    const FIELD_MAX_LENGTH = 16;
    const DEFAULT_CANARY_POSTCODE_REGEX = '^(35|38)\d{3}$';
    const DEFAULT_USA_SHOP_DOMAIN_NEEDLE = 'seedstockersusa.com';

    /**
     * Kept in sync with Address::$definition['fields']['dni']['validate'] (Validate::isDniLite):
     * ASCII letters/digits, dash and dot only. Accepting anything wider here (e.g. spaces or
     * slashes) would let a value pass our own check and then fail native Address validation
     * with a fatal exception when the address is saved.
     */
    const DNI_VALUE_REGEX = '/^[0-9A-Za-z\-.]{1,16}$/';

    public function __construct()
    {
        $this->name = 'pccustomerid';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'perpetualcode';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '8.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->trans('PC Customer ID for customers adress', [], 'Modules.Pccustomerid.Admin');
        $this->description = $this->trans(
            'Adds a conditional, country-specific ID number field (DNI or equivalent) to customer address forms for customs clearance (Switzerland, Canary Islands).',
            [],
            'Modules.Pccustomerid.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Are you sure you want to uninstall this module? Its configuration will be removed. Any ID number already saved on customer addresses will be kept.',
            [],
            'Modules.Pccustomerid.Admin'
        );
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('additionalCustomerAddressFields')
            && $this->registerHook('actionValidateCustomerAddressForm')
            && $this->registerHook('displayAdditionalCustomerAddressFields')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->installDefaultConfiguration();
    }

    public function uninstall()
    {
        foreach ($this->getConfigKeys() as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    /**
     * additionalCustomerAddressFields: build the (hidden-by-default, JS-driven) dni field.
     *
     * This runs inside CustomerAddressFormatter::getFormat(), before submitted id_state /
     * postcode values have been copied onto the form fields, so it must not try to decide
     * whether the field is required - that decision (and its enforcement) belongs entirely
     * to hookActionValidateCustomerAddressForm(), which runs after the form is fully
     * populated from the request.
     */
    public function hookAdditionalCustomerAddressFields(array $params)
    {
        if (!$this->isEnabledForCurrentShop()) {
            return [];
        }

        $field = new FormField();
        $field->setName(self::FIELD_NAME)
            ->setType('text')
            ->setLabel($this->trans('ID number', [], 'Modules.Pccustomerid.Shop'))
            ->setRequired(false)
            ->setValue($this->getRequestDniValue())
            ->setMaxLength(self::FIELD_MAX_LENGTH)
            ->addAvailableValue('placeholder', $this->trans('DNI or equivalent', [], 'Modules.Pccustomerid.Shop'))
            ->addAvailableValue('comment', $this->getHelpText());

        return [$field];
    }

    /**
     * actionValidateCustomerAddressForm: the only place that actually enforces the rule.
     */
    public function hookActionValidateCustomerAddressForm(array $params)
    {
        if (!isset($params['form']) || !($params['form'] instanceof CustomerAddressForm)) {
            return true;
        }

        if (!$this->isEnabledForCurrentShop()) {
            return true;
        }

        /** @var CustomerAddressForm $form */
        $form = $params['form'];

        // Core prefixes hook-added fields with "<module name>_<field name>" when merging
        // them into CustomerAddressFormatter's format array (see
        // classes/form/CustomerAddressFormatter.php), so that is the key to use with
        // AbstractForm::getField(). The FormField's own name (used for the HTML attribute
        // and for Address::dni mapping on submit) stays "dni".
        $field = $form->getField($this->name . '_' . self::FIELD_NAME);
        if (!$field) {
            return true;
        }

        $idCountry = (int) $form->getValue('id_country');
        $idState = (int) $form->getValue('id_state');
        $postcode = (string) $form->getValue('postcode');

        if (!$this->requiresCustomerId($idCountry, $idState, $postcode)) {
            return true;
        }

        $value = trim((string) $field->getValue());

        if ($value === '') {
            $field->addError($this->trans(
                'ID number is required for this shipping destination.',
                [],
                'Modules.Pccustomerid.Shop'
            ));

            return false;
        }

        if (!preg_match(self::DNI_VALUE_REGEX, $value)) {
            $field->addError($this->trans(
                'ID number format is invalid.',
                [],
                'Modules.Pccustomerid.Shop'
            ));

            return false;
        }

        return true;
    }

    /**
     * displayAdditionalCustomerAddressFields: show the stored value in the "my addresses" cards.
     */
    public function hookDisplayAdditionalCustomerAddressFields(array $params)
    {
        if (!$this->isEnabledForCurrentShop()) {
            return '';
        }

        $address = isset($params['address']) ? $params['address'] : null;
        $dni = '';

        if ($address instanceof Address) {
            $dni = (string) $address->dni;
        } elseif (is_array($address) && !empty($address['id'])) {
            $addressObject = new Address((int) $address['id']);
            if (Validate::isLoadedObject($addressObject)) {
                $dni = (string) $addressObject->dni;
            }
        }

        $dni = trim($dni);
        if ($dni === '') {
            return '';
        }

        return '<p class="pc-customer-id-display">'
            . htmlspecialchars($this->trans('ID number', [], 'Modules.Pccustomerid.Shop'), ENT_QUOTES, 'UTF-8')
            . ': '
            . htmlspecialchars($dni, ENT_QUOTES, 'UTF-8')
            . '</p>';
    }

    /**
     * actionFrontControllerSetMedia: only load front assets on pages that can render an
     * address form, and hand the JS its full configuration in one shot.
     */
    public function hookActionFrontControllerSetMedia()
    {
        if (!$this->isEnabledForCurrentShop()) {
            return;
        }

        $controller = $this->context->controller;
        if (!Validate::isLoadedObject($controller) || !property_exists($controller, 'php_self')) {
            return;
        }

        if (!in_array($controller->php_self, ['address', 'addresses', 'order', 'authentication'], true)) {
            return;
        }

        $controller->registerStylesheet(
            'pccustomerid-front',
            'modules/' . $this->name . '/views/css/front.css',
            ['media' => 'all', 'priority' => 150]
        );

        $controller->registerJavascript(
            'pccustomerid-front',
            'modules/' . $this->name . '/views/js/front.js',
            ['position' => 'bottom', 'priority' => 150]
        );

        Media::addJsDef([
            'pcCustomerIdConfig' => [
                'enabled' => true,
                'fieldName' => self::FIELD_NAME,
                'countryCH' => (int) $this->getConfigValue(self::CONFIG_CH_COUNTRY_ID),
                'countryES' => (int) $this->getConfigValue(self::CONFIG_ES_COUNTRY_ID),
                'canaryStateIds' => $this->getCanaryStateIds(),
                'canaryPostcodeRegex' => (string) $this->getConfigValue(
                    self::CONFIG_CANARY_POSTCODE_REGEX,
                    self::DEFAULT_CANARY_POSTCODE_REGEX
                ),
                'label' => $this->trans('ID number', [], 'Modules.Pccustomerid.Shop'),
                'placeholder' => $this->trans('DNI or equivalent', [], 'Modules.Pccustomerid.Shop'),
                'helpText' => $this->getHelpText(),
                'requiredError' => $this->trans(
                    'ID number is required for this shipping destination.',
                    [],
                    'Modules.Pccustomerid.Shop'
                ),
            ],
        ]);
    }

    /**
     * Central decision function: does this destination require the customer ID?
     */
    private function requiresCustomerId(int $idCountry, int $idState = 0, string $postcode = ''): bool
    {
        if (!$idCountry) {
            return false;
        }

        $chCountryId = (int) $this->getConfigValue(self::CONFIG_CH_COUNTRY_ID);
        if ($chCountryId && $idCountry === $chCountryId) {
            return true;
        }

        $esCountryId = (int) $this->getConfigValue(self::CONFIG_ES_COUNTRY_ID);
        if ($esCountryId && $idCountry === $esCountryId) {
            if ($idState && in_array($idState, $this->getCanaryStateIds(), true)) {
                return true;
            }

            $postcode = (string) preg_replace('/\s+/', '', $postcode);
            $pattern = (string) $this->getConfigValue(
                self::CONFIG_CANARY_POSTCODE_REGEX,
                self::DEFAULT_CANARY_POSTCODE_REGEX
            );

            return $postcode !== '' && 1 === @preg_match('#' . $pattern . '#', $postcode);
        }

        return false;
    }

    private function getHelpText(): string
    {
        return $this->trans(
            'In many cases, customs authorities may request an ID number (DNI or equivalent) to process or release the shipment. If this information is missing, the shipment may be held or delayed by customs.',
            [],
            'Modules.Pccustomerid.Shop'
        );
    }

    /**
     * Echo back what the customer submitted (so a rejected form doesn't lose the value),
     * falling back to the stored value when an existing address is being displayed/edited,
     * or to an empty value for a brand new address.
     */
    private function getRequestDniValue(): string
    {
        $posted = Tools::getValue(self::FIELD_NAME, null);
        if ($posted !== null) {
            return (string) $posted;
        }

        $idAddress = (int) Tools::getValue('id_address');
        if ($idAddress > 0) {
            $address = new Address($idAddress);
            if (Validate::isLoadedObject($address)) {
                return (string) $address->dni;
            }
        }

        return '';
    }

    private function getCanaryStateIds(): array
    {
        $csv = (string) $this->getConfigValue(self::CONFIG_CANARY_STATE_IDS, '');
        $ids = array_filter(array_map('intval', explode(',', $csv)));

        return array_values($ids);
    }

    private function isEnabledForCurrentShop(): bool
    {
        $idShop = (int) $this->context->shop->id;

        $excludedCsv = (string) $this->getConfigValue(self::CONFIG_EXCLUDED_SHOP_IDS, '');
        $excluded = array_filter(array_map('intval', explode(',', $excludedCsv)));
        if (in_array($idShop, $excluded, true)) {
            return false;
        }

        return (bool) $this->getConfigValue(self::CONFIG_ENABLED, true);
    }

    /**
     * Thin wrapper: Configuration::get() already cascades shop -> shop group -> global
     * using the current context when $idShop is left null, which is exactly the
     * multistore behaviour we want everywhere at runtime.
     */
    private function getConfigValue(string $key, $default = false)
    {
        return Configuration::get($key, null, null, null, $default);
    }

    private function getConfigKeys(): array
    {
        return [
            self::CONFIG_ENABLED,
            self::CONFIG_EXCLUDED_SHOP_IDS,
            self::CONFIG_CH_COUNTRY_ID,
            self::CONFIG_ES_COUNTRY_ID,
            self::CONFIG_CANARY_STATE_IDS,
            self::CONFIG_CANARY_POSTCODE_REGEX,
            self::CONFIG_USE_DNI_FIELD,
        ];
    }

    private function installDefaultConfiguration(): bool
    {
        Configuration::updateValue(self::CONFIG_ENABLED, 1);
        Configuration::updateValue(self::CONFIG_CH_COUNTRY_ID, (int) Country::getByIso('CH'));
        Configuration::updateValue(self::CONFIG_ES_COUNTRY_ID, (int) Country::getByIso('ES'));
        Configuration::updateValue(self::CONFIG_CANARY_STATE_IDS, implode(',', $this->detectCanaryStateIds()));
        Configuration::updateValue(self::CONFIG_CANARY_POSTCODE_REGEX, self::DEFAULT_CANARY_POSTCODE_REGEX);
        Configuration::updateValue(self::CONFIG_USE_DNI_FIELD, 1);

        $usaShopIds = $this->detectShopIdsByDomain(self::DEFAULT_USA_SHOP_DOMAIN_NEEDLE);
        Configuration::updateValue(self::CONFIG_EXCLUDED_SHOP_IDS, implode(',', $usaShopIds));

        foreach ($usaShopIds as $idShop) {
            Configuration::updateValue(self::CONFIG_ENABLED, 0, false, null, (int) $idShop);
        }

        return true;
    }

    private function detectCanaryStateIds(): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT s.id_state FROM `' . _DB_PREFIX_ . 'state` s
             INNER JOIN `' . _DB_PREFIX_ . 'country` c ON c.id_country = s.id_country
             WHERE c.iso_code = "ES"
             AND (s.name LIKE "%Canar%" OR s.name LIKE "%Palmas%" OR s.name LIKE "%Tenerife%")'
        );

        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_unique(array_map(static function (array $row): int {
            return (int) $row['id_state'];
        }, $rows)));
    }

    private function detectShopIdsByDomain(string $needle): array
    {
        $ids = [];

        foreach (Shop::getShops(false) as $shop) {
            $domain = (string) ($shop['domain'] ?? '');
            $domainSsl = (string) ($shop['domain_ssl'] ?? '');
            if (stripos($domain, $needle) !== false || stripos($domainSsl, $needle) !== false) {
                $ids[] = (int) $shop['id_shop'];
            }
        }

        return $ids;
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitPccustomerid')) {
            $this->postProcessConfiguration();
            $output .= $this->displayConfirmation(
                $this->trans('Settings updated.', [], 'Modules.Pccustomerid.Admin')
            );
        } elseif (Tools::isSubmit('submitPccustomeridDetect')) {
            $this->postProcessAutoDetect();
            $output .= $this->displayConfirmation(
                $this->trans('Auto-detection completed.', [], 'Modules.Pccustomerid.Admin')
            );
        }

        return $output . $this->renderForm();
    }

    private function postProcessConfiguration(): void
    {
        $idShopGroup = (Shop::getContext() === Shop::CONTEXT_GROUP) ? (int) Shop::getContextShopGroupID() : null;
        $idShop = (Shop::getContext() === Shop::CONTEXT_SHOP) ? (int) Shop::getContextShopID() : null;

        Configuration::updateValue(
            self::CONFIG_ENABLED,
            Tools::getValue(self::CONFIG_ENABLED) ? 1 : 0,
            false,
            $idShopGroup,
            $idShop
        );
        Configuration::updateValue(
            self::CONFIG_EXCLUDED_SHOP_IDS,
            $this->sanitizeCsvIntList(Tools::getValue(self::CONFIG_EXCLUDED_SHOP_IDS)),
            false,
            $idShopGroup,
            $idShop
        );
        Configuration::updateValue(
            self::CONFIG_CH_COUNTRY_ID,
            (int) Tools::getValue(self::CONFIG_CH_COUNTRY_ID),
            false,
            $idShopGroup,
            $idShop
        );
        Configuration::updateValue(
            self::CONFIG_ES_COUNTRY_ID,
            (int) Tools::getValue(self::CONFIG_ES_COUNTRY_ID),
            false,
            $idShopGroup,
            $idShop
        );
        Configuration::updateValue(
            self::CONFIG_CANARY_STATE_IDS,
            $this->sanitizeCsvIntList(Tools::getValue(self::CONFIG_CANARY_STATE_IDS)),
            false,
            $idShopGroup,
            $idShop
        );

        $regex = (string) Tools::getValue(self::CONFIG_CANARY_POSTCODE_REGEX);
        if ($regex === '' || false === @preg_match('#' . $regex . '#', '')) {
            $regex = self::DEFAULT_CANARY_POSTCODE_REGEX;
        }
        Configuration::updateValue(self::CONFIG_CANARY_POSTCODE_REGEX, $regex, false, $idShopGroup, $idShop);

        Configuration::updateValue(
            self::CONFIG_USE_DNI_FIELD,
            Tools::getValue(self::CONFIG_USE_DNI_FIELD) ? 1 : 0,
            false,
            $idShopGroup,
            $idShop
        );
    }

    private function postProcessAutoDetect(): void
    {
        $idShopGroup = (Shop::getContext() === Shop::CONTEXT_GROUP) ? (int) Shop::getContextShopGroupID() : null;
        $idShop = (Shop::getContext() === Shop::CONTEXT_SHOP) ? (int) Shop::getContextShopID() : null;

        Configuration::updateValue(self::CONFIG_CH_COUNTRY_ID, (int) Country::getByIso('CH'), false, $idShopGroup, $idShop);
        Configuration::updateValue(self::CONFIG_ES_COUNTRY_ID, (int) Country::getByIso('ES'), false, $idShopGroup, $idShop);
        Configuration::updateValue(
            self::CONFIG_CANARY_STATE_IDS,
            implode(',', $this->detectCanaryStateIds()),
            false,
            $idShopGroup,
            $idShop
        );
    }

    private function sanitizeCsvIntList($raw): string
    {
        $ids = array_filter(array_map('intval', preg_split('/[,\s]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY)));

        return implode(',', array_values(array_unique($ids)));
    }

    private function renderForm(): string
    {
        $shopContextLabel = $this->trans('All shops', [], 'Modules.Pccustomerid.Admin');
        if (Shop::getContext() === Shop::CONTEXT_GROUP) {
            $shopContextLabel = $this->trans('Current shop group', [], 'Modules.Pccustomerid.Admin');
        } elseif (Shop::getContext() === Shop::CONTEXT_SHOP) {
            $shopContextLabel = $this->trans('Current shop only', [], 'Modules.Pccustomerid.Admin');
        }

        $this->context->smarty->assign([
            'pc_action_url' => $this->context->link->getAdminLink('AdminModules') . '&configure=' . $this->name,
            'pc_shop_context_label' => $shopContextLabel,
            'pc_config' => [
                'enabled' => (bool) $this->getConfigValue(self::CONFIG_ENABLED, true),
                'excluded_shop_ids' => (string) $this->getConfigValue(self::CONFIG_EXCLUDED_SHOP_IDS, ''),
                'ch_country_id' => (int) $this->getConfigValue(self::CONFIG_CH_COUNTRY_ID),
                'es_country_id' => (int) $this->getConfigValue(self::CONFIG_ES_COUNTRY_ID),
                'canary_state_ids' => (string) $this->getConfigValue(self::CONFIG_CANARY_STATE_IDS, ''),
                'canary_postcode_regex' => (string) $this->getConfigValue(
                    self::CONFIG_CANARY_POSTCODE_REGEX,
                    self::DEFAULT_CANARY_POSTCODE_REGEX
                ),
                'use_dni_field' => (bool) $this->getConfigValue(self::CONFIG_USE_DNI_FIELD, true),
            ],
        ]);

        // Module::display() only resolves templates under views/templates/{hook,front}/
        // (or the module root) - it never looks in views/templates/admin/, which is why
        // it reported "No template found for module" for the BO configuration page.
        // Admin templates must be fetched directly instead.
        return $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');
    }
}
