<?php
/**
 * 2007-2018 PrestaShop
 *
 * DISCLAIMER
 ** Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2018 PrestaShop SA
 * @license   http://addons.prestashop.com/en/content/12-terms-and-conditions-of-use
 * International Registered Trademark & Property of PrestaShop SA
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__).'/libraries/sdk/stripe/init.php';

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class Stripe_official extends PaymentModule
{
    /* status */
    const _FLAG_NULL_ = 0;

    const _FLAG_ERROR_ = 1;

    const _FLAG_WARNING_ = 2;

    const _FLAG_SUCCESS_ = 4;

    const _FLAG_STDERR_ = 1;

    const _FLAG_STDOUT_ = 2;

    const _FLAG_STDIN_ = 4;

    const _FLAG_MAIL_ = 8;

    const _FLAG_NO_FLUSH__ = 16;

    const _FLAG_FLUSH__ = 32;

    const _PENDING_SOFORT_ = 4;

    /* tab section shape */
    private $section_shape = 1;

    /* refund */
    public $refund = 0;

    public $mail = '';

    public $errors = array();

    public $warning = array();

    public $success;

    public $addons_track;

    public function __construct()
    {
        $this->name = 'stripe_official';
        $this->tab = 'payments_gateways';
        $this->version = '1.6.0';
        $this->author = '202 ecommerce';
        $this->bootstrap = true;
        $this->display = 'view';
        $this->module_key = 'bb21cb93bbac29159ef3af00bca52354';
        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
        $this->currencies = true;
        /* curl check */
        if (is_callable('curl_init') === false) {
            $this->errors[] = $this->l('To be able to use this module, please activate cURL (PHP extension).');
        }

        parent::__construct();

        $this->meta_title = $this->l('Stripe');
        $this->displayName = $this->l('Stripe payment module');
        $this->description = $this->l('Start accepting stripe payments today, directly from your shop!');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?', $this->name);

        /* Use a specific name to bypass an Order confirmation controller check */
        if (in_array(Tools::getValue('controller'), array('orderconfirmation', 'order-confirmation'))) {
            $this->displayName = $this->l('Payment by Stripe');
        }
    }

    public function install()
    {
        $partial_refund_state = Configuration::get('STRIPE_PARTIAL_REFUND_STATE');

        /* Create Order State for Stripe */
        if ($partial_refund_state === false) {
            $order_state = new OrderState();
            $langs = Language::getLanguages();
            foreach ($langs as $lang) {
                $order_state->name[$lang['id_lang']] = pSQL('Stripe Partial Refund');
            }
            $order_state->invoice = false;
            $order_state->send_email = false;
            $order_state->logable = true;
            $order_state->color = '#FFDD99';
            $order_state->save();

            Configuration::updateValue('STRIPE_PARTIAL_REFUND_STATE', $order_state->id);
        }

        if (!parent::install()) {
            return false;
        }

        if (!Configuration::updateValue('STRIPE_MODE', 1)
            || !Configuration::updateValue('STRIPE_REFUND_MODE', 1)
            || !Configuration::updateValue('STRIPE_SECURE', 1)
            || !Configuration::updateValue('STRIPE_MINIMUM_AMOUNT_3DS', 50)
            || !Configuration::updateValue('STRIPE_ENABLE_IDEAL', 0)
            || !Configuration::updateValue('STRIPE_ENABLE_SOFORT', 0)
            || !Configuration::updateValue('STRIPE_ENABLE_GIROPAY', 0)
            || !Configuration::updateValue('STRIPE_ENABLE_BANCONTACT', 0)
            || !Configuration::updateValue('STRIPE_ENABLE_APPLEPAY', 0)
            || !Configuration::updateValue('STRIPE_ENABLE_GOOGLEPAY', 0)
            || !Configuration::updateValue('STRIPE_PRODUCT_PAYMENT', 0)) {
                 return false;
        }

        if (!$this->registerHook('header')
            || !$this->registerHook('orderConfirmation')
            || !$this->registerHook('displayBackOfficeHeader')
            || !$this->registerHook('paymentOptions')
            || !$this->registerHook('adminOrder')
            || !$this->registerHook('displayProductAdditionalInfo')) {
            return false;
        }

        if (!$this->createStripePayment()) {
            return false;
        }

        if (!$this->installOrderState()) {
            return false;
        }


        return true;
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName('STRIPE_KEY')
            && Configuration::deleteByName('STRIPE_TEST_KEY')
            && Configuration::deleteByName('STRIPE_PUBLISHABLE')
            && Configuration::deleteByName('STRIPE_SECURE')
            && Configuration::deleteByName('STRIPE_TEST_PUBLISHABLE')
            && Configuration::deleteByName('STRIPE_PARTIAL_REFUND_STATE')
            && Configuration::deleteByName('STRIPE_OS_SOFORT_WAITING')
            && Configuration::deleteByName('STRIPE_MODE')
            && Configuration::deleteByName('STRIPE_REFUND_MODE')
            && Configuration::deleteByName('STRIPE_ENABLE_IDEAL')
            && Configuration::deleteByName('STRIPE_ENABLE_SOFORT')
            && Configuration::deleteByName('STRIPE_ENABLE_GIROPAY')
            && Configuration::deleteByName('STRIPE_ENABLE_BANCONTACT')
            && Configuration::deleteByName('STRIPE_ENABLE_APPLEPAY')
            && Configuration::deleteByName('STRIPE_ENABLE_GOOGLEPAY')
            && Configuration::deleteByName('STRIPE_PRODUCT_PAYMENT')
            && Configuration::deleteByName('STRIPE_MINIMUM_AMOUNT_3DS');
    }

    /**
     * Create order state
     * @return boolean
     */
    public function installOrderState()
    {
        if (!Configuration::get('STRIPE_OS_SOFORT_WAITING')
            || !Validate::isLoadedObject(new OrderState(Configuration::get('STRIPE_OS_SOFORT_WAITING')))) {
            $order_state = new OrderState();
            $order_state->name = array();
            foreach (Language::getLanguages() as $language) {
                if (Tools::strtolower($language['iso_code']) == 'fr') {
                    $order_state->name[$language['id_lang']] = 'En attente de paiement Sofort';
                } else {
                    $order_state->name[$language['id_lang']] = 'Awaiting for Sofort payment';
                }
            }
            $order_state->send_email = false;
            $order_state->color = '#4169E1';
            $order_state->hidden = false;
            $order_state->delivery = false;
            $order_state->logable = false;
            $order_state->invoice = false;
            if ($order_state->add()) {
                $source = _PS_MODULE_DIR_.'stripe_official/views/img/cc-sofort.png';
                $destination = _PS_ROOT_DIR_.'/img/os/'.(int) $order_state->id.'.gif';
                copy($source, $destination);
            }
            Configuration::updateValue('STRIPE_OS_SOFORT_WAITING', (int) $order_state->id);
        }
        return true;
    }

    /* Create Database Stripe Payment */
    protected function createStripePayment()
    {
        $db = Db::getInstance();
        $query = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'stripe_payment` (
            `id_payment` int(11) NOT NULL AUTO_INCREMENT,
            `id_stripe` varchar(255) NOT NULL,
            `name` varchar(255) NOT NULL,
            `id_cart` int(11) NOT NULL,
            `last4` varchar(4) NOT NULL,
            `type` varchar(255) NOT NULL,
            `amount` varchar(255) NOT NULL,
            `refund` varchar(255) NOT NULL,
            `currency` varchar(255) NOT NULL,
            `result` tinyint(4) NOT NULL,
            `state` tinyint(4) NOT NULL,
            `date_add` datetime NOT NULL,
            PRIMARY KEY (`id_payment`),
           KEY `id_cart` (`id_cart`)
       ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8 AUTO_INCREMENT=1';
        $db->Execute($query);

        return true;
    }

    public function loadAddonTracker()
    {
        $track_query = 'utm_source=back-office&utm_medium=module&utm_campaign=back-office-%s&utm_content=%s';
        $lang = new Language(Configuration::get('PS_LANG_DEFAULT'));

        if ($lang && Validate::isLoadedObject($lang)) {
            $track_query = sprintf($track_query, Tools::strtoupper($lang->iso_code), $this->name);
            $this->context->smarty->assign('url_track', $track_query);
            return true;
        }

        return false;
    }

    public function retrieveAccount($secret_key)
    {
        \Stripe\Stripe::setApiKey($secret_key);
        try {
            \Stripe\Account::retrieve();
        } catch (Exception $e) {
            error_log($e->getMessage());
            $this->errors[] = $e->getMessage();
            return false;
        }
        return true;
    }

    /*
     ** @Method: getContent
     ** @description: render main content
     **
     ** @arg:
     ** @return: (none)
     */
    public function getContent()
    {
        /* Check if SSL is enabled */
        if (!Configuration::get('PS_SSL_ENABLED')) {
            $this->warning[] = $this->l('You must enable SSL on the store if you want to use this module');
            $this->errors[] = $this->l('A SSL certificate is required to process credit card payments using Stripe. Please consult the FAQ.');
        }

        /* Do Log In  */
        if (Tools::isSubmit('submit_login')) {
            if (Tools::getValue('STRIPE_MODE') == 1) {
                $secret_key = trim(Tools::getValue('STRIPE_TEST_KEY'));
                $publishable_key = trim(Tools::getValue('STRIPE_TEST_PUBLISHABLE'));

                if (!empty($secret_key) && !empty($publishable_key)) {
                    if (strpos($secret_key, 'test') !== false && strpos($publishable_key, 'test') !== false) {
                        if ($this->retrieveAccount($secret_key, $publishable_key)) {
                            Configuration::updateValue('STRIPE_TEST_KEY', $secret_key);
                            Configuration::updateValue('STRIPE_TEST_PUBLISHABLE', $publishable_key);
                        }
                    } else {
                        $this->errors[] = $this->l('mode test with API key live');
                    }
                } else {
                    $this->errors[] = $this->l('Client ID and Secret Key fields are mandatory');
                }

                Configuration::updateValue('STRIPE_MODE', Tools::getValue('STRIPE_MODE'));
            } else {
                $secret_key = trim(Tools::getValue('STRIPE_KEY'));
                $publishable_key = trim(Tools::getValue('STRIPE_PUBLISHABLE'));

                if (!empty($secret_key) && !empty($publishable_key)) {
                    if (strpos($secret_key, 'live') !== false && strpos($publishable_key, 'live') !== false) {
                        if ($this->retrieveAccount($secret_key, $publishable_key)) {
                            Configuration::updateValue('STRIPE_KEY', $secret_key);
                            Configuration::updateValue('STRIPE_PUBLISHABLE', $publishable_key);
                        }
                    } else {
                        $this->errors['keys'] = $this->l('mode live with API key test');
                    }
                } else {
                    $this->errors[] = $this->l('Client ID and Secret Key fields are mandatory');
                }

                Configuration::updateValue('STRIPE_MODE', Tools::getValue('STRIPE_MODE'));
            }

            if (!count($this->errors)) {
                $this->success = $this->l('Data succesfuly saved.');
            }

            Configuration::updateValue('STRIPE_ENABLE_IDEAL', Tools::getValue('ideal'));
            Configuration::updateValue('STRIPE_ENABLE_SOFORT', Tools::getValue('sofort'));
            Configuration::updateValue('STRIPE_ENABLE_GIROPAY', Tools::getValue('giropay'));
            Configuration::updateValue('STRIPE_ENABLE_BANCONTACT', Tools::getValue('bancontact'));
            Configuration::updateValue('STRIPE_ENABLE_APPLEPAY', Tools::getValue('applepay'));
            Configuration::updateValue('STRIPE_ENABLE_GOOGLEPAY', Tools::getValue('googlepay'));
            if (Tools::getValue('applepay') !== false || Tools::getValue('googlepay') !== false) {
                Configuration::updateValue('STRIPE_PRODUCT_PAYMENT', Tools::getValue('product_payment'));
            }
        }

        if (!Configuration::get('STRIPE_KEY') && !Configuration::get('STRIPE_PUBLISHABLE')
            && !Configuration::get('STRIPE_TEST_KEY') && !Configuration::get('STRIPE_TEST_PUBLISHABLE')) {
            $this->errors[] = $this->l('Keys are empty.');
        }

        /* Do Secure */
        if (Tools::isSubmit('submit_secure')) {
            if (Tools::getValue('STRIPE_SECURE') == 2) {
                if (Tools::getValue('3ds_amount') == '') {
                    $this->errors[] = $this->l('3DS amount is empty');
                }

                if (Tools::getValue('3ds_amount') != '' && !Validate::isInt(Tools::getValue('3ds_amount'))) {
                    $this->errors[] = $this->l('3DS amount is not valid. Excpeted integer only.');
                }
            }

            if (!count($this->errors)) {
                Configuration::updateValue('STRIPE_SECURE', Tools::getValue('STRIPE_SECURE'));
                Configuration::updateValue('STRIPE_MINIMUM_AMOUNT_3DS', Tools::getValue('3ds_amount'));
                $this->success = $this->l('Data succesfuly saved.');
            }
        }

        /* Do Refund */
        if (Tools::isSubmit('submit_refund_id')) {
            $refund_id = Tools::getValue('STRIPE_REFUND_ID');
            if (!empty($refund_id)) {
                $refund = Db::getInstance()->ExecuteS('SELECT * FROM '._DB_PREFIX_.'stripe_payment WHERE `id_stripe` = "'.pSQL($refund_id).'"');
            } else {
                $this->errors[] = $this->l('Please make sure to put a Stripe Id');
                return false;
            }

            if ($refund) {
                $this->refund = 1;
                Configuration::updateValue('STRIPE_REFUND_ID', Tools::getValue('STRIPE_REFUND_ID'));
            } else {
                $this->refund = 0;
                $this->errors[] = $this->l('This Stipe ID doesn\'t exist, please check it again');
                Configuration::updateValue('STRIPE_REFUND_ID', '');
            }

            $amount = null;
            $mode = Tools::getValue('STRIPE_REFUND_MODE');
            if ($mode == 0) {
                $amount = Tools::getValue('STRIPE_REFUND_AMOUNT');
            }

            $this->apiRefund($refund[0]['id_stripe'], $refund[0]['currency'], $mode, $refund[0]['id_cart'], $amount);

            if (!count($this->errors)) {
                $this->success = $this->l('Data succesfuly saved.');
            }
        }

        /* generate url track */
        $this->loadAddonTracker();

        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == "on") {
            $domain = Tools::getShopDomainSsl(true, true);
        } else {
            $domain = Tools::getShopDomain(true, true);
        }

        $this->context->controller->addJS($this->_path.'/views/js/faq.js');
        $this->context->controller->addJS($this->_path.'/views/js/back.js');
        $this->context->controller->addJS($this->_path.'/views/js/PSTabs.js');
        $this->context->controller->addCSS($this->_path.'/views/css/started.css');
        $this->context->controller->addCSS($this->_path.'/views/css/tabs.css');

        if ((Configuration::get('STRIPE_TEST_KEY') != '' && Configuration::get('STRIPE_TEST_PUBLISHABLE') != '')
            || (Configuration::get('STRIPE_KEY') != '' && Configuration::get('STRIPE_PUBLISHABLE') != '')) {
            $keys_configured = true;
        } else {
            $keys_configured = false;
        }

        $this->context->smarty->assign(array(
            'logo' => $domain.__PS_BASE_URI__.basename(_PS_MODULE_DIR_).'/'.$this->name.'/views/img/Stripe_logo.png',
            'new_base_dir', $this->_path,
            'keys_configured' => $keys_configured,
        ));

        $this->displaySomething();
        $this->displayForm();
        $this->displayTransaction();
        $this->displaySecure();
        $this->displayRefundForm();

        if (count($this->warning)) {
            $this->context->smarty->assign('warnings', $this->displayWarning($this->warning));
        }

        if (!empty($this->success) && !count($this->errors)) {
            $this->context->smarty->assign('success', $this->displayConfirmation($this->success));
        }

        if (count($this->errors)) {
            $this->context->smarty->assign('errors', $this->displayError($this->errors));
        }

        return $this->display($this->_path, 'views/templates/admin/main.tpl');
    }

    /*
     ** @Method: displaySecure
     ** @description: just display 3d secure configuration
     **
     ** @arg: (none)
     ** @return: (none)
     */
    public function displaySecure()
    {
        $fields_form = array();
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('Options'),
            ),
            'input' => array(
                array(
                    'type' => 'radio',
                    'name' => 'STRIPE_SECURE',
                    'desc' => $this->l(''),
                    'values' => array(
                        array(
                            'id' => 'secure_none',
                            'value' => 0,
                            'label' => $this->l('No 3D-Secure authentication requested'),
                        ),
                        array(
                            'id' => 'secure_any',
                            'value' => 1,
                            'label' => $this->l('Request 3D-Secure authentication on all charges'),
                        ),
                        array(
                            'id' => 'secure_custom',
                            'value' => 2,
                            'label' => $this->l('Request 3D-Secure authentication on charges above 50 EUR/USD/GBP only'),
                        ),
                    ),
                ),
                array(
                    'type' => 'text',
                    'name' => '3ds_amount',
                ),
            ),
            'submit' => array(
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right button',
            ),
        );

        $submit_action = 'submit_secure';
        $fields_value = array(
            'STRIPE_SECURE' => Configuration::get('STRIPE_SECURE'),
            '3ds_amount' => Configuration::get('STRIPE_MINIMUM_AMOUNT_3DS'),
        );

        $this->context->smarty->assign('secure_form', $this->renderGenericForm($fields_form, $fields_value, $this->getSectionShape(), $submit_action));
    }

    /*
     ** Display Form
     */
    public function displayForm()
    {
        $this->context->smarty->assign(array(
            'stripe_mode' => Configuration::get('STRIPE_MODE'),
            'stripe_key' => Configuration::get('STRIPE_KEY'),
            'stripe_publishable' => Configuration::get('STRIPE_PUBLISHABLE'),
            'stripe_test_publishable' => Configuration::get('STRIPE_TEST_PUBLISHABLE'),
            'stripe_test_key' => Configuration::get('STRIPE_TEST_KEY'),
            'ideal' => Configuration::get('STRIPE_ENABLE_IDEAL'),
            'sofort' => Configuration::get('STRIPE_ENABLE_SOFORT'),
            'giropay' => Configuration::get('STRIPE_ENABLE_GIROPAY'),
            'bancontact' => Configuration::get('STRIPE_ENABLE_BANCONTACT'),
            'applepay' => Configuration::get('STRIPE_ENABLE_APPLEPAY'),
            'googlepay' => Configuration::get('STRIPE_ENABLE_GOOGLEPAY'),
            'product_payment' => Configuration::get('STRIPE_PRODUCT_PAYMENT'),
            'url_webhhoks' => $this->context->link->getModuleLink($this->name, 'webhook', array(), true),
        ));
    }

    /*
     ** Display All Stripe transactions
     */
    public function displayTransaction($refresh = 0, $token_ajax = null, $id_employee = null)
    {
        $token_module = '';
        if ($token_ajax && $id_employee) {
            $employee = new Employee($id_employee);
            $this->context->employee = $employee;
            $token_module = Tools::getAdminTokenLite('AdminModules', $this->context);
        }

        $tenta = array();
        if ($token_module == $token_ajax || $refresh == 0) {
            $this->getSectionShape();
            $orders = Db::getInstance()->ExecuteS('SELECT * FROM '._DB_PREFIX_.'stripe_payment ORDER BY date_add DESC');

            foreach ($orders as $order) {
                if ($order['result'] == 0) {
                    $result = 'n';
                } elseif ($order['result'] == 1) {
                    $result = '';
                } elseif ($order['result'] == 2) {
                    $result = 2;
                } elseif ($order['result'] == self::_PENDING_SOFORT_) {
                    $result = 4;
                } else {
                    $result = 3;
                }

                $refund = Tools::safeOutput($order['amount']) - Tools::safeOutput($order['refund']);
                array_push($tenta, array(
                    'date' => Tools::safeOutput($order['date_add']),
                    'last_digits' => Tools::safeOutput($order['last4']),
                    'type' => Tools::strtolower($order['type']),
                    'amount' => Tools::safeOutput($order['amount']),
                    'currency' => Tools::safeOutput(Tools::strtoupper($order['currency'])),
                    'refund' => $refund,
                    'id_stripe' => Tools::safeOutput($order['id_stripe']),
                    'name' => Tools::safeOutput($order['name']),
                    'result' => $result,
                    'state' => Tools::safeOutput($order['state']) ? $this->l('Test') : $this->l('Live'),
                ));
            }

            $this->context->smarty->assign(array(
                'refresh' => $refresh,
                'token_stripe' => Tools::getAdminTokenLite('AdminModules'),
                'id_employee' => $this->context->employee->id,
                'path' => Tools::getShopDomainSsl(true, true).$this->_path,
            ));
        }

        $this->context->smarty->assign('tenta', $tenta);

        if ($refresh) {
            $this->context->smarty->assign('module_dir', $this->_path);
            return $this->fetch(_PS_MODULE_DIR_ . $this->name . '/views/templates/admin/_partials/transaction.tpl');
        }
    }

    /*
     ** Display Submit form for Refund
     */
    public function displayRefundForm()
    {
        $fields_form = array();
        $fields_value = array();

        $fields_form[1]['form'] = array(
            'legend' => array(
                'title' => $this->l('Choose an Order you want to Refund'),
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => $this->l('Stripe Payment ID'),
                    'desc' => '<i>'.$this->l('To process a refund, please input Stripe’s payment ID below, which can be found in the « Payments » tab of this plugin').'</i>',
                    'name' => 'STRIPE_REFUND_ID',
                    'class' => 'fixed-width-xxl',
                    'required' => true
                ),
                array(
                    'type' => 'radio',
                    'desc' => '<i>'.$this->l('We’ll submit any refund you make to your customer’s bank immediately.').'<br>'.
                        $this->l('Your customer will then receive the funds from a refund approximately 2-3 business days after the date on which the refund was initiated.').'<br>'.
                        $this->l('Refunds take 5 to 10 days to appear on your cutomer’s statement.').'</i>',
                    'name' => 'STRIPE_REFUND_MODE',
                    'size' => 50,
                    'values' => array(
                        array(
                            'id' => 'active_on_refund',
                            'value' => 1,
                            'label' => $this->l('Full refund')
                        ),
                        array(
                            'id' => 'active_off_refund',
                            'value' => 0,
                            'label' => $this->l('Partial Refund')
                        )
                    ),
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Amount'),
                    'desc' => $this->l('Please, enter an amount your want to refund'),
                    'name' => 'STRIPE_REFUND_AMOUNT',
                    'size' => 20,
                    'id' => 'refund_amount',
                    'class' => 'fixed-width-sm',
                    'required' => true
                ),
            ),
            'submit' => array(
                'title' => $this->l('Request Refund'),
                'class' => 'btn btn-default pull-right button',
            ),
        );
        $this->refund = 1;

        $submit_action = 'submit_refund_id';
        $fields_value = array(
            'STRIPE_REFUND_ID' => Configuration::get('STRIPE_REFUND_ID'),
            'STRIPE_REFUND_MODE' => Configuration::get('STRIPE_REFUND_MODE'),
            'STRIPE_REFUND_AMOUNT' => Configuration::get('STRIPE_REFUND_AMOUNT'),
        );

        $this->context->smarty->assign('refund_form', $this->renderGenericForm($fields_form, $fields_value, $this->getSectionShape(), $submit_action));
    }

    /*
     ** @Method: displaySomething
     ** @description: just display something (it's something)
     **
     ** @arg: (none)
     ** @return: (none)
     */
    public function displaySomething()
    {
        $return_url = '';

        if (Configuration::get('PS_SSL_ENABLED')) {
            $domain = Tools::getShopDomainSsl(true);
        } else {
            $domain = Tools::getShopDomain(true);
        }

        if (isset($_SERVER['REQUEST_URI'])) {
            $return_url = urlencode($domain.$_SERVER['REQUEST_URI'].$this->getSectionShape());
        }

        $this->context->smarty->assign('return_url', $return_url);
    }

    /*
     ** @Method: renderGenericForm
     ** @description: render generic form for prestashop
     **
     ** @arg: $fields_form, $fields_value, $submit = false, array $tpls_vars = array()
     ** @return: (none)
     */
    public function renderGenericForm($fields_form, $fields_value = array(), $fragment = false, $submit = false, array $tpl_vars = array())
    {
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
        $helper->title = $this->displayName;
        $helper->show_toolbar = false;
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;

        if ($fragment !== false) {
            $helper->token .= '#'.$fragment;
        }

        if ($submit) {
            $helper->submit_action = $submit;
        }

        $helper->tpl_vars = array_merge(array(
            'fields_value' => $fields_value,
            'id_language' => $this->context->language->id,
            'back_url' => $this->context->link->getAdminLink('AdminModules')
            .'&configure='.$this->name
            .'&tab_module='.$this->tab
            .'&module_name='.$this->name.($fragment !== false ? '#'.$fragment : '')
        ), $tpl_vars);

        return $helper->generateForm($fields_form);
    }

    /*
     ** @Method: apiRefund
     ** @description: Make a Refund (charge) with Stripe
     **
     ** @arg: amount, id_stripe
     ** @amount: if null total refund
     ** @currency: "USD", "EUR", etc..
     ** @mode: (boolean) ? total : partial
     ** @return: (none)
     */
    public function apiRefund($refund_id, $currency, $mode, $id_card, $amount = null)
    {
        $secret_key = $this->getSecretKey();
        if ($this->retrieveAccount($secret_key, '', 1) && !empty($refund_id)) {
            $refund = Db::getInstance()->ExecuteS('SELECT * FROM '._DB_PREFIX_.'stripe_payment WHERE `id_stripe` = "'.pSQL($refund_id).'"');
            if ($mode == 1) { /* Total refund */
                try {
                    $ch = \Stripe\Charge::retrieve($refund_id);
                    $ch->refunds->create();
                } catch (Exception $e) {
                    // Something else happened, completely unrelated to Stripe
                    $this->errors[] = $e->getMessage();
                    return false;
                }

                Db::getInstance()->Execute(
                    'UPDATE `'._DB_PREFIX_.'stripe_payment` SET `result` = 2, `date_add` = NOW(), `refund` = "'
                    .pSQL($refund[0]['amount']).'" WHERE `id_stripe` = "'.pSQL($refund_id).'"'
                );
            } else { /* Partial refund */
                if (!preg_match('/BIF|DJF|JPY|KRW|PYG|VND|XAF|XPF|CLP|GNF|KMF|MGA|RWF|VUV|XOF/i', $currency)) {
                    $ref_amount = $amount * 100;
                }
                try {
                    $ch = \Stripe\Charge::retrieve($refund_id);
                    $ch->refunds->create(array('amount' => $ref_amount));
                } catch (Exception $e) {
                    // Something else happened, completely unrelated to Stripe
                    $this->errors[] = $e->getMessage();
                    return false;
                }

                $amount += ($refund[0]['refund']);
                if ($amount == $refund[0]['amount']) {
                    $result = 2;
                } else {
                    $result = 3;
                }

                if ($amount <= $refund[0]['amount']) {
                    Db::getInstance()->Execute(
                        'UPDATE `'._DB_PREFIX_.'stripe_payment` SET `result` = '.(int)$result.', `date_add` = NOW(), `refund` = "'
                        .pSQL($amount).'" WHERE `id_stripe` = "'.pSQL($refund_id).'"'
                    );
                }
            }

            $id_order = Order::getOrderByCartId($id_card);
            $order = new Order($id_order);
            $state = Db::getInstance()->getValue('SELECT `result` FROM '._DB_PREFIX_.'stripe_payment WHERE `id_stripe` = "'.pSQL($refund_id).'"');

            if ($state == 2) {
                /* Refund State */
                $order->setCurrentState(7);
            } elseif ($state == 3) {
                /* Partial Refund State */
                $order->setCurrentState(Configuration::get('STRIPE_PARTIAL_REFUND_STATE'));
            }
            $this->success = $this->l('Refunds processed successfully');
        } else {
            $this->errors[] = $this->l('Invalid Stripe credentials, please check your configuration.');
        }
    }

    public function isZeroDecimalCurrency($currency)
    {
        // @see: https://support.stripe.com/questions/which-zero-decimal-currencies-does-stripe-support
        $zeroDecimalCurrencies = array(
            'BIF',
            'CLP',
            'DJF',
            'GNF',
            'JPY',
            'KMF',
            'KRW',
            'MGA',
            'PYG',
            'RWF',
            'VND',
            'VUV',
            'XAF',
            'XOF',
            'XPF'
        );
        return in_array($currency, $zeroDecimalCurrencies);
    }

    public function createOrder($charge, $params)
    {
        if (($charge->status == 'succeeded' && $charge->object == 'charge' && $charge->id)
            || ($charge->status == 'pending' && $charge->object == 'charge' && $charge->id && $params['type'] == 'sofort')) {
            /* The payment was approved */
            $message = 'Stripe Transaction ID: '.$charge->id;
            $secure_key = isset($params['secureKey']) ? $params['secureKey'] : false;
            try {
                $paid = $this->isZeroDecimalCurrency($params['currency']) ? $params['amount'] : $params['amount'] / 100;
                /* Add transaction on Prestashop back Office (Order) */
                if ($params['type'] == 'sofort' && $charge->status == 'pending') {
                    $status = Configuration::get('STRIPE_OS_SOFORT_WAITING');
                } else {
                    $status = Configuration::get('PS_OS_PAYMENT');
                }
                $this->validateOrder(
                    (int)$charge->metadata->cart_id,
                    (int)$status,
                    $paid,
                    $this->l('Payment by Stripe'),
                    $message,
                    array(),
                    null,
                    false,
                    $secure_key
                );
            } catch (PrestaShopException $e) {
                $this->_error[] = (string)$e->getMessage();
            }

            /* Add transaction on database */
            if ($params['type'] == 'sofort' && $charge->status == 'pending') {
                $result = self::_PENDING_SOFORT_;
            } else {
                $result = 1;
            }
            $this->addTentative(
                $charge->id,
                $charge->source->owner->name,
                $params['type'],
                $charge->amount,
                0,
                $charge->currency,
                $result,
                (int)$charge->metadata->cart_id
            );
            $id_order = Order::getOrderByCartId($params['cart_id']);

            $ch = \Stripe\Charge::retrieve($charge->id);
            $ch->description = "Order id: ".$id_order." - ".$params['carHolderEmail'];
            $ch->save();

            /* Ajax redirection Order Confirmation */
            return die(Tools::jsonEncode(array(
                'chargeObject' => $charge,
                'code' => '1',
                'url' => Context::getContext()->link->getPageLink('order-confirmation', true).'?id_cart='.(int)$charge->metadata->cart_id.'&id_module='.(int)$this->id.'&id_order='.(int)$id_order.'&key='.$secure_key,
            )));
        } else {
            $this->addTentative(
                $charge->id,
                $charge->source->owner->name,
                $params['type'],
                $charge->amount,
                0,
                $charge->currency,
                0,
                (int)$params['cart_id']
            );
            die(Tools::jsonEncode(array(
                'code' => '0',
                'msg' => $this->l('Payment declined. Unknown error, please use another card or contact us.'),
            )));
        }
    }

    public function chargeWebhook(array $params)
    {
        if (!$this->retrieveAccount($this->getSecretKey(), '', 1)) {
            die(Tools::jsonEncode(array('code' => '0', 'msg' => $this->l('Invalid Stripe credentials, please check your configuration.'))));
        }
        try {
            // Create the charge on Stripe's servers - this will charge the user's card
            \Stripe\Stripe::setApiKey($this->getSecretKey());
            \Stripe\Stripe::setAppInfo("StripePrestashop", $this->version, Configuration::get('PS_SHOP_DOMAIN_SSL'));

            $cart = new Cart($params['cart_id']);
            $address_delivery = new Address($cart->id_address_delivery);
            $state_delivery = State::getNameById($address_delivery->id_state);
            $cardHolderName = $params['cardHolderName'];

            $charge = \Stripe\Charge::create(
                array(
                    "amount" => $params['amount'], // amount in cents, again
                    "currency" => $params['currency'],
                    "source" => $params['token'],
                    "description" => $params['carHolderEmail'],
                    "shipping" => array("address" => array("city" => $address_delivery->city,
                        "country" => Country::getIsoById($address_delivery->id_country), "line1" => $address_delivery->address1,
                        "line2" => $address_delivery->address2, "postal_code" => $address_delivery->postcode,
                        "state" => $state_delivery), "name" => $cardHolderName),
                    "metadata" => array(
                        "cart_id" => $params['cart_id'],
                        "verification_url" => Configuration::get('PS_SHOP_DOMAIN'),
                    )
                )
            );
        } catch (\Stripe\Error\Card $e) {
            $refund = $params['amount'];
            $this->addTentative($e->getMessage(), $params['cardHolderName'], $params['type'], $refund, $refund, $params['currency'], 0, (int)$params['cart_id']);
            die(Tools::jsonEncode(array(
                'code' => '0',
                'msg' => $e->getMessage(),
            )));
        }

        $this->createOrder($charge, $params);
    }

    public function chargev2(array $params)
    {
        if (!$this->retrieveAccount($this->getSecretKey(), '', 1)) {
            die(Tools::jsonEncode(array('code' => '0', 'msg' => $this->l('Invalid Stripe credentials, please check your configuration.'))));
        }

        try {
            // Create the charge on Stripe's servers - this will charge the user's card
            \Stripe\Stripe::setApiKey($this->getSecretKey());
            \Stripe\Stripe::setAppInfo("StripePrestashop", $this->version, Configuration::get('PS_SHOP_DOMAIN_SSL'));

            $address_delivery = new Address($this->context->cart->id_address_delivery);
            $state_delivery = State::getNameById($address_delivery->id_state);

            if (!$params['cardHolderName'] || $params['cardHolderName'] == '') {
                $cardHolderName = $this->context->customer->firstname.' '.$this->context->customer->lastname;
            } else {
                $cardHolderName = $params['cardHolderName'];
            }

            $charge = \Stripe\Charge::create(
                array(
                    "amount" => $params['amount'], // amount in cents, again
                    "currency" => $params['currency'],
                    "source" => $params['token'],
                    "description" => $this->context->customer->email,
                    "shipping" => array("address" => array("city" => $address_delivery->city,
                        "country" => $this->context->country->iso_code, "line1" => $address_delivery->address1,
                        "line2" => $address_delivery->address2, "postal_code" => $address_delivery->postcode,
                        "state" => $state_delivery), "name" => $cardHolderName),
                    "metadata" => array(
                        "cart_id" => $this->context->cart->id,
                        "verification_url" => Configuration::get('PS_SHOP_DOMAIN'),
                    )
                )
            );
        } catch (\Stripe\Error\Card $e) {
            $refund = $params['amount'];
            $this->addTentative($e->getMessage(), $params['cardHolderName'], $params['type'], $refund, $refund, $params['currency'], 0, (int)$this->context->cart->id);
            die(Tools::jsonEncode(array(
                'code' => '0',
                'msg' => $e->getMessage(),
            )));
        }
        $params['cart_id'] = $this->context->cart->id;
        $params['carHolderEmail'] = $this->context->customer->email;
        $params['secureKey'] =  $this->context->customer->secure_key;
        $this->createOrder($charge, $params);
    }

    /*
     ** @Method: addTentative
     ** @description: Add Payment on Database
     **
     ** @return: (none)
     */
    private function addTentative($id_stripe, $name, $type, $amount, $refund, $currency, $result, $id_cart = 0, $mode = null)
    {
        if ($id_cart == 0) {
            $id_cart = (int)$this->context->cart->id;
        }

        if ($type == 'American Express') {
            $type = 'amex';
        } elseif ($type == 'Diners Club') {
            $type = 'diners';
        }

        // @see: https://support.stripe.com/questions/which-zero-decimal-currencies-does-stripe-support
        $zeroDecimalCurrencies = array(
            'BIF',
            'CLP',
            'DJF',
            'GNF',
            'JPY',
            'KMF',
            'KRW',
            'MGA',
            'PYG',
            'RWF',
            'VND',
            'VUV',
            'XAF',
            'XOF',
            'XPF'
        );

        if (!in_array($currency, $zeroDecimalCurrencies)) {
            $amount /= 100;
            $refund /= 100;
        }

        if ($mode === null) {
            $mode = Configuration::get('STRIPE_MODE');
        }

        /* Add request on Database */
        Db::getInstance()->Execute(
            'INSERT INTO '._DB_PREFIX_
            .'stripe_payment (id_stripe, name, id_cart, type, amount, refund, currency, result, state, date_add)
            VALUES ("'.pSQL($id_stripe).'", "'.pSQL($name).'", \''.(int)$id_cart.'\', "'.pSQL(Tools::strtolower($type)).'", "'
            .pSQL($amount).'", "'.pSQL($refund).'", "'.pSQL(Tools::strtolower($currency)).'", '.(int)$result.', '.(int)$mode.', NOW())'
        );
    }

    /*
     ** @Method: getSectionShape
     ** @description: get section shape fragment
     **
     ** @arg:
     ** @return: (none)
     */
    private function getSectionShape()
    {
        return 'stripe_step_'.(int)$this->section_shape++;
    }

    public function getSecretKey()
    {
        if (Configuration::get('STRIPE_MODE')) {
            return Configuration::get('STRIPE_TEST_KEY');
        } else {
            return Configuration::get('STRIPE_KEY');
        }
    }

    public function getPublishableKey()
    {
        if (Configuration::get('STRIPE_MODE')) {
            return Configuration::get('STRIPE_TEST_PUBLISHABLE');
        } else {
            return Configuration::get('STRIPE_PUBLISHABLE');
        }
    }

    public function updateConfigurationKey($oldKey, $newKey, $defaultValue)
    {
        if (Configuration::hasKey($oldKey)) {
            $set = '';

            if ($oldKey == '_PS_STRIPE_secure' && Configuration::get($oldKey) == '0') {
                $set = ',`value`=2';
            }

            $sql = "UPDATE `"._DB_PREFIX_."configuration`
                    SET `name`='".pSQL($newKey)."'".$set."
                    WHERE `name`='".pSQL($oldKey)."'";

            return Db::getInstance()->execute($sql);
        } else {
            Configuration::updateValue($newKey, $defaultValue);
            return true;
        }
    }

    public function hookDisplayBackOfficeHeader($params)
    {
        if (Tools::getIsset('controller') && Tools::getValue('controller') == 'AdminModules' && Tools::getIsset('configure') && Tools::getValue('configure') == $this->name) {
            Media::addJsDef(array(
                'transaction_refresh_url' => $this->context->link->getAdminLink('AdminAjaxTransaction', true, array(), array('ajax' => 1, 'action' => 'refresh')),
            ));
        }
    }

    public function hookHeader()
    {
        $moduleId = Module::getModuleIdByName($this->name);

        $currencyAvailable = false;
        foreach (Currency::checkPaymentCurrencies($moduleId) as $currency) {
            if ($currency['id_currency'] == $this->context->currency->id) {
                $currencyAvailable = true;
            }
        }

        if (($this->context->controller->php_self == 'order' && $currencyAvailable === true) || ($this->context->controller->php_self == 'product' && $currencyAvailable === true)) {
            $amount = $this->context->cart->getOrderTotal();
            $secure_mode = Configuration::get('STRIPE_SECURE');
            $currency = $this->context->currency->iso_code;
            $publishable_key = $this->getPublishableKey();
            $language_iso = $this->context->language->iso_code;

            $must_enable_3ds = false;
            if ($amount > Configuration::get('STRIPE_MINIMUM_AMOUNT_3DS')) {
                $must_enable_3ds = true;
            }

            $address_invoice = new Address($this->context->cart->id_address_invoice);

            $billing_address = array(
                'line1' => $address_invoice->address1,
                'line2' => $address_invoice->address2,
                'city' => $address_invoice->city,
                'zip_code' => $address_invoice->postcode,
                'country' => $address_invoice->country,
                'phone' => $address_invoice->phone_mobile ? $address_invoice->phone_mobile : $address_invoice->phone,
                'email' => $this->context->customer->email,
            );

            $amount = $this->isZeroDecimalCurrency($currency) ? $amount : $amount * 100;

            if ($this->context->controller->php_self == 'product') {
                $productPayment = true;
                $productPrice = Product::getPriceStatic(Tools::getValue('id_product'), true, null, 2);
                $amount = $this->isZeroDecimalCurrency($currency) ? $productPrice : $productPrice * 100;
            } else {
                $productPayment = false;
                $productPrice = 0;
            }

            $carriers = Carrier::getCarriers($this->context->language->id, true, false, $this->context->country->id_zone);

            foreach ($carriers as &$carrier) {
                $c = new Carrier($carrier['id_carrier']);

                if ($carrier['shipping_method'] == 1) {
                    $carrier['price'] = round($c->getMaxDeliveryPriceByWeight($this->context->country->id_zone)*100);
                } else {
                    $carrier['price'] = round($c->getDeliveryPriceByPrice($amount, $this->context->country->id_zone)*100);
                }
            }

            $currency = $this->context->currency->iso_code;

            $js_def = array(
                'must_enable_3ds' => $must_enable_3ds,
                'mode' => Configuration::get('STRIPE_MODE'),
                'currency_stripe' => $currency,
                'amount_ttl' => $amount,
                'secure_mode' => $secure_mode,
                'baseDir' => $this->context->link->getBaseLink($this->context->shop->id, true),
                'billing_address' => Tools::jsonEncode($billing_address),
                'module_dir' => $this->_path,
                'ajaxUrlStripe' => $this->context->link->getModuleLink('stripe_official', 'ajax', array(), true),
                'StripePubKey' => $publishable_key,
                'stripeLanguageIso' => $language_iso,
                'productPayment' => $productPayment,
                'carriersRequest' => Tools::jsonEncode($carriers),
                'currencyStripe' => $currency,
                'paymentRequestUrlStripe'=> $this->context->link->getModuleLink('stripe_official', 'paymentRequest', array(), true),
                'productPrice'=> $productPrice,
            );

            Media::addJsDef($js_def);

            $this->context->controller->registerStylesheet($this->name.'-frontcss', 'modules/'.$this->name.'/views/css/front.css');
            $this->context->controller->registerJavascript($this->name.'-stipeV2', 'https://js.stripe.com/v2/', array('server'=>'remote'));
            $this->context->controller->registerJavascript($this->name.'-stipeV3', 'https://js.stripe.com/v3/', array('server'=>'remote'));
            $this->context->controller->registerJavascript($this->name.'-paymentjs', 'modules/'.$this->name.'/views/js/payment_stripe.js');

            $push_methods = false;
            if (Configuration::get('STRIPE_ENABLE_IDEAL') || Configuration::get('STRIPE_ENABLE_GIROPAY') || Configuration::get('STRIPE_ENABLE_BANCONTACT') || Configuration::get('STRIPE_ENABLE_SOFORT')) {
                $push_methods = true;
            }

            if (($secure_mode && $must_enable_3ds) || $push_methods) {
                $this->context->controller->registerJavascript($this->name.'-modaljs', 'modules/'.$this->name.'/views/js/jquery.the-modal.js');
                $this->context->controller->registerStylesheet($this->name.'-modalcss', 'modules/'.$this->name.'/views/css/the-modal.css');
            }

            if ($push_methods) {
                $this->context->controller->registerJavascript($this->name.'-stripemethods', 'modules/'.$this->name.'/views/js/stripe-push-methods.js');
            }

            if (Configuration::get('STRIPE_ENABLE_APPLEPAY') || Configuration::get('STRIPE_ENABLE_GOOGLEPAY')) {
                $this->context->controller->registerJavascript($this->name.'-stripepaymentrequest', 'modules/'.$this->name.'/views/js/payment_request.js');
            }

            $default_country = new Country(Configuration::get('PS_COUNTRY_DEFAULT'));
            $address_invoice = new Address($this->context->cart->id_address_invoice);

            $iso_country = Country::getIsoById($address_invoice->id_country);
            $iso_countries = array('AT', 'BE', 'DE', 'NL', 'ES', 'IT');

            $available_countries = array();
            foreach ($iso_countries as $iso) {
                $id_country = Country::getByIso($iso);
                $available_countries[$iso] = Country::getNameById($this->context->language->id, $id_country);
            }

            $smatry_vars = array(
                'publishableKey' => $publishable_key,
                'customer_name' => $this->context->customer->firstname.' '.$this->context->customer->lastname,
                'stripeLanguageIso' => $language_iso,
                'country_merchant' => Tools::strtolower($default_country->iso_code),
                'stripe_customer_email' => $this->context->customer->email,
                'stripe_order_url' => $this->context->link->getModuleLink($this->name, 'validation', array(), true),
                'stripe_cart_id' => $this->context->cart->id,
                'stripe_error' => Tools::getValue('stripe_error'),
                'payment_error_type' => Tools::getValue('type'),
                'stripe_failed' => Tools::getValue('stripe_failed'),
                'stripe_err_msg' => Tools::getValue('stripe_err_msg'),
                'verification_url' => Configuration::get('PS_SHOP_DOMAIN'),
                'stripe_country_iso_code' => $iso_country,
                'sofort_available_countries' => $available_countries,
                'SSL' => Configuration::get('PS_SSL_ENABLED'),
            );

            $this->context->smarty->assign(array_merge($js_def, $smatry_vars));
        }
    }

    public function hookPaymentOptions($params)
    {
        $payment_options = array();
        $default_country = new Country(Configuration::get('PS_COUNTRY_DEFAULT'));

        if (!Configuration::get('PS_SSL_ENABLED')) {
            return $this->context->smarty->fetch('module:stripe_official/views/templates/hook/payment.tpl');
        }

        if (Tools::strtolower($default_country->iso_code) == 'us') {
            $cc_img = 'cc_merged.png';
        } else {
            $cc_img = 'logo-payment.png';
        }

        $embeddedOption = new PaymentOption();
        $embeddedOption->setCallToActionText($this->l('Pay by card'))
           ->setAdditionalInformation($this->context->smarty->fetch('module:' . $this->name . '/views/templates/hook/payment.tpl'))
           ->setModuleName($this->name)
           ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_.$this->name.'/views/img/'.$cc_img));
        $payment_options[] = $embeddedOption;

        if (Configuration::get('STRIPE_ENABLE_APPLEPAY') || Configuration::get('STRIPE_ENABLE_GOOGLEPAY')) {
            $embeddedOption = new PaymentOption();
            $embeddedOption->setCallToActionText($this->l('Pay with Goole Pay or Apple Pay'))
               ->setAdditionalInformation($this->context->smarty->fetch('module:'.$this->name.'/views/templates/hook/payment_request_api.tpl'));
            $payment_options[] = $embeddedOption;
        }

        if ($this->context->currency->iso_code == "EUR") {
            foreach (array('ideal', 'sofort', 'bancontact', 'giropay') as $method) {
                if (Configuration::get('STRIPE_ENABLE_'.Tools::strtoupper($method))) {
                    $this->context->smarty->assign('payment_method', $method);

                    $payment_option = new PaymentOption();
                    $payment_option->setCallToActionText($this->l('Pay by ').Tools::strtoupper($method))
                        ->setAction($this->context->link->getModuleLink($this->name, 'validation', array(), true))
                        ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_.$this->name.'/views/img/cc-'.$method.'.png'))
                        ->setModuleName($method)
                        ->setAdditionalInformation($this->context->smarty->fetch('module:'.$this->name.'/views/templates/hook/modal_stripe.tpl'));
                    $payment_options[] = $payment_option;
                }
            }
        }
        return $payment_options;
    }

    public function hookDisplayProductAdditionalInfo($params)
    {
        if ((Configuration::get('STRIPE_ENABLE_APPLEPAY') == 'on' || Configuration::get('STRIPE_ENABLE_GOOGLEPAY') == 'on') && Configuration::get('STRIPE_PRODUCT_PAYMENT') == 'on') {
            return $this->display(__FILE__, 'views/templates/hook/product_payment.tpl');
        }
    }

    /*
     ** Hook Order Confirmation
     */
    public function hookOrderConfirmation($params)
    {
        $this->context->smarty->assign('stripe_order_reference', pSQL($params['order']->reference));
        if ($params['order']->module == $this->name) {
            return $this->display(__FILE__, 'views/templates/front/order-confirmation.tpl');
        }
    }
}
