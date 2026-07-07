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
    const CONFIG_ENABLED_SHOP_IDS = 'PCCUSTOMERID_ENABLED_SHOP_IDS';
    const CONFIG_COUNTRIES = 'PCCUSTOMERID_COUNTRIES';
    const CONFIG_CANARY_STATE_IDS = 'PCCUSTOMERID_CANARY_STATE_IDS';
    const CONFIG_CANARY_POSTCODE_REGEX = 'PCCUSTOMERID_CANARY_POSTCODE_REGEX';
    const CONFIG_USE_DNI_FIELD = 'PCCUSTOMERID_USE_DNI_FIELD';

    const FIELD_NAME = 'dni';
    const FIELD_MAX_LENGTH = 16;
    const DEFAULT_CANARY_POSTCODE_REGEX = '^(35|38)\d{3}$';
    const DEFAULT_USA_SHOP_DOMAIN_NEEDLE = 'seedstockersusa.com';
    const SHOP_TREE_ID = 'pccustomerid-shops-tree';

    /**
     * Kept in sync with Address::$definition['fields']['dni']['validate'] (Validate::isDniLite):
     * ASCII letters/digits, dash and dot only. Accepting anything wider here (e.g. spaces or
     * slashes) would let a value pass our own check and then fail native Address validation
     * with a fatal exception when the address is saved.
     */
    const DNI_VALUE_REGEX = '/^[0-9A-Za-z\-.]{1,16}$/';

    /**
     * Used as the HelperTreeShops "table" attribute (checkbox name prefix) and as
     * $helper->table below. This module has no dedicated database table; the value only
     * namespaces form field names, matching the convention used by other modules' BO forms.
     */
    public $table = 'pccustomerid';

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
            'Adds a conditional, country-specific ID number field (DNI or equivalent) to customer address forms for customs clearance.',
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
                'countries' => $this->getConfiguredCountryIds(),
                'countryES' => (int) Country::getByIso('ES'),
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
     *
     * Two independent rules, both configurable from the BO, and applicable to any
     * PrestaShop store using this module (not specific to Switzerland/Spain):
     *  - PCCUSTOMERID_COUNTRIES: countries where the ID is always required.
     *  - the Canary Islands sub-region rule for Spain (province, with a postcode fallback).
     */
    private function requiresCustomerId(int $idCountry, int $idState = 0, string $postcode = ''): bool
    {
        if (!$idCountry) {
            return false;
        }

        if (in_array($idCountry, $this->getConfiguredCountryIds(), true)) {
            return true;
        }

        $esCountryId = (int) Country::getByIso('ES');
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

    private function getConfiguredCountryIds(): array
    {
        return $this->csvToIntArray((string) $this->getConfigValue(self::CONFIG_COUNTRIES, ''));
    }

    private function getCanaryStateIds(): array
    {
        return $this->csvToIntArray((string) $this->getConfigValue(self::CONFIG_CANARY_STATE_IDS, ''));
    }

    private function getEnabledShopIds(): array
    {
        return $this->csvToIntArray((string) $this->getConfigValue(self::CONFIG_ENABLED_SHOP_IDS, ''));
    }

    private function csvToIntArray(string $csv): array
    {
        return array_values(array_unique(array_filter(array_map('intval', explode(',', $csv)))));
    }

    private function isEnabledForCurrentShop(): bool
    {
        return in_array((int) $this->context->shop->id, $this->getEnabledShopIds(), true);
    }

    /**
     * Thin wrapper: Configuration::get() already cascades shop -> shop group -> global
     * using the current context when $idShop is left null. All the settings below are
     * saved globally (see postProcessConfiguration()), so in practice this always
     * resolves to the single stored value - the cascade is simply harmless here.
     */
    private function getConfigValue(string $key, $default = false)
    {
        return Configuration::get($key, null, null, null, $default);
    }

    private function getConfigKeys(): array
    {
        return [
            self::CONFIG_ENABLED_SHOP_IDS,
            self::CONFIG_COUNTRIES,
            self::CONFIG_CANARY_STATE_IDS,
            self::CONFIG_CANARY_POSTCODE_REGEX,
            self::CONFIG_USE_DNI_FIELD,
        ];
    }

    private function installDefaultConfiguration(): bool
    {
        $allShopIds = array_map(static function (array $shop): int {
            return (int) $shop['id_shop'];
        }, Shop::getShops(false));

        $usaShopIds = $this->detectShopIdsByDomain(self::DEFAULT_USA_SHOP_DOMAIN_NEEDLE);
        $enabledShopIds = array_values(array_diff($allShopIds, $usaShopIds));

        Configuration::updateValue(self::CONFIG_ENABLED_SHOP_IDS, implode(',', $enabledShopIds));
        Configuration::updateValue(self::CONFIG_COUNTRIES, (string) (int) Country::getByIso('CH'));
        Configuration::updateValue(self::CONFIG_CANARY_STATE_IDS, implode(',', $this->detectCanaryStateIds()));
        Configuration::updateValue(self::CONFIG_CANARY_POSTCODE_REGEX, self::DEFAULT_CANARY_POSTCODE_REGEX);
        Configuration::updateValue(self::CONFIG_USE_DNI_FIELD, 1);

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
        $enabledShopIds = array_map('intval', (array) Tools::getValue('checkBoxShopAsso_' . $this->name, []));
        Configuration::updateValue(self::CONFIG_ENABLED_SHOP_IDS, implode(',', array_unique($enabledShopIds)));

        $countries = array_map('intval', (array) Tools::getValue(self::CONFIG_COUNTRIES, []));
        Configuration::updateValue(self::CONFIG_COUNTRIES, implode(',', array_unique(array_filter($countries))));

        $canaryStates = array_map('intval', (array) Tools::getValue(self::CONFIG_CANARY_STATE_IDS, []));
        Configuration::updateValue(self::CONFIG_CANARY_STATE_IDS, implode(',', array_unique(array_filter($canaryStates))));

        $regex = (string) Tools::getValue(self::CONFIG_CANARY_POSTCODE_REGEX);
        if ($regex === '' || false === @preg_match('#' . $regex . '#', '')) {
            $regex = self::DEFAULT_CANARY_POSTCODE_REGEX;
        }
        Configuration::updateValue(self::CONFIG_CANARY_POSTCODE_REGEX, $regex);

        Configuration::updateValue(self::CONFIG_USE_DNI_FIELD, Tools::getValue(self::CONFIG_USE_DNI_FIELD) ? 1 : 0);
    }

    private function postProcessAutoDetect(): void
    {
        Configuration::updateValue(self::CONFIG_CANARY_STATE_IDS, implode(',', $this->detectCanaryStateIds()));
    }

    private function getActiveCountries(): array
    {
        return Country::getCountries((int) $this->context->language->id, true);
    }

    private function getSpainStates(): array
    {
        $idCountryEs = (int) Country::getByIso('ES');
        if (!$idCountryEs) {
            return [];
        }

        return State::getStatesByIdCountry($idCountryEs);
    }

    private function renderShopsTree(): string
    {
        $selected = array_combine($this->getEnabledShopIds(), $this->getEnabledShopIds());

        $tree = new HelperTreeShops(self::SHOP_TREE_ID, $this->trans('Shops where the module is active', [], 'Modules.Pccustomerid.Admin'));
        $tree->setSelectedShops($selected ?: []);
        $tree->setAttribute('table', $this->name);

        return $tree->render();
    }

    private function getConfigFieldsValues(): array
    {
        return [
            self::CONFIG_COUNTRIES => $this->getConfiguredCountryIds(),
            self::CONFIG_CANARY_STATE_IDS => $this->getCanaryStateIds(),
            self::CONFIG_CANARY_POSTCODE_REGEX => (string) $this->getConfigValue(
                self::CONFIG_CANARY_POSTCODE_REGEX,
                self::DEFAULT_CANARY_POSTCODE_REGEX
            ),
            self::CONFIG_USE_DNI_FIELD => (int) $this->getConfigValue(self::CONFIG_USE_DNI_FIELD, true),
        ];
    }

    private function getShopsFieldset(): array
    {
        return [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Shops', [], 'Modules.Pccustomerid.Admin'),
                    'icon' => 'icon-shop',
                ],
                'input' => [
                    [
                        'type' => 'html',
                        'name' => 'pccustomerid_shops_tree',
                        'label' => $this->trans('Active in these shops', [], 'Modules.Pccustomerid.Admin'),
                        'html_content' => $this->renderShopsTree(),
                        'desc' => $this->trans(
                            'Uncheck a shop to fully disable the module there (e.g. a USA shop that should not ask for a customs ID number).',
                            [],
                            'Modules.Pccustomerid.Admin'
                        ),
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Modules.Pccustomerid.Admin'),
                ],
            ],
        ];
    }

    private function getGeneralFieldset(): array
    {
        return [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Destinations requiring the ID number', [], 'Modules.Pccustomerid.Admin'),
                    'icon' => 'icon-globe',
                ],
                'input' => [
                    [
                        'type' => 'select',
                        'name' => self::CONFIG_COUNTRIES,
                        'label' => $this->trans('Countries', [], 'Modules.Pccustomerid.Admin'),
                        'desc' => $this->trans(
                            'The ID number is always required for addresses in these countries.',
                            [],
                            'Modules.Pccustomerid.Admin'
                        ),
                        'multiple' => true,
                        'size' => 8,
                        'options' => [
                            'query' => $this->getActiveCountries(),
                            'id' => 'id_country',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'name' => self::CONFIG_CANARY_STATE_IDS,
                        'label' => $this->trans('Canary Islands provinces (Spain)', [], 'Modules.Pccustomerid.Admin'),
                        'desc' => $this->trans(
                            'In addition to the countries above, the ID number is required for Spanish addresses in these provinces (or matching the postcode fallback below).',
                            [],
                            'Modules.Pccustomerid.Admin'
                        ),
                        'multiple' => true,
                        'size' => 4,
                        'options' => [
                            'query' => $this->getSpainStates(),
                            'id' => 'id_state',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'text',
                        'name' => self::CONFIG_CANARY_POSTCODE_REGEX,
                        'label' => $this->trans('Canary Islands postcode fallback (regex)', [], 'Modules.Pccustomerid.Admin'),
                        'desc' => $this->trans(
                            'Used when the province above cannot be matched, e.g. ^(35|38)\d{3}$',
                            [],
                            'Modules.Pccustomerid.Admin'
                        ),
                    ],
                    [
                        'type' => 'switch',
                        'name' => self::CONFIG_USE_DNI_FIELD,
                        'label' => $this->trans('Use native ps_address.dni field', [], 'Modules.Pccustomerid.Admin'),
                        'desc' => $this->trans(
                            'Informational: this module always stores the value in the native ps_address.dni column.',
                            [],
                            'Modules.Pccustomerid.Admin'
                        ),
                        'values' => [
                            ['value' => 1, 'label' => $this->trans('Yes', [], 'Modules.Pccustomerid.Admin')],
                            ['value' => 0, 'label' => $this->trans('No', [], 'Modules.Pccustomerid.Admin')],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Modules.Pccustomerid.Admin'),
                ],
                'buttons' => [
                    'detect' => [
                        'title' => $this->trans('Run auto-detection (Canary provinces)', [], 'Modules.Pccustomerid.Admin'),
                        'name' => 'submitPccustomeridDetect',
                        'type' => 'submit',
                        'class' => 'btn btn-default pull-right',
                        'icon' => 'process-icon-refresh',
                    ],
                ],
            ],
        ];
    }

    private function renderForm(): string
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = 'id_module';
        $helper->submit_action = 'submitPccustomerid';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name
            . '&tab_module=' . $this->tab
            . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([
            $this->getShopsFieldset(),
            $this->getGeneralFieldset(),
        ]);
    }
}
