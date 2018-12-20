<?php

require_once __DIR__ . '/coinbase/autoload.php';
require_once __DIR__ . '/coinbase/const.php';

class coinbase extends base
{
    public $code;
    public $title;
    public $description;
    public $enabled;
    public $order_status;
    private $_check;

    function __construct()
    {
        $this->code = 'coinbase';
        $this->title = MODULE_PAYMENT_COINBASE_TEXT_TITLE;
        $this->description = MODULE_PAYMENT_COINBASE_TEXT_DESCRIPTION;
        $this->sort_order = MODULE_PAYMENT_COINBASE_SORT_ORDER;
        $this->enabled = ((MODULE_PAYMENT_COINBASE_STATUS == 'True') ? true : false);

        if ((int)MODULE_PAYMENT_COINBASE_PENDING_STATUS_ID > 0) {
            $this->order_status = MODULE_PAYMENT_COINBASE_PENDING_STATUS_ID;
        }
    }

    function javascript_validation()
    {
        return false;
    }

    function selection()
    {
        return array(
            'id' => $this->code,
            'module' => $this->title
        );
    }

    function pre_confirmation_check()
    {
        return false;
    }

    function confirmation()
    {
        return false;
    }

    function process_button()
    {
        return '';
    }

    /**
     * Store transaction info to the order and process any results that come back from the payment gateway
     */
    function before_process()
    {
        return true;
    }

    function get_error()
    {
        return false;
    }

    /**
     * Check to see whether module is installed
     *
     * @return boolean
     */
    function check()
    {
        global $db;
        if (!isset($this->_check)) {
            $check_query = $db->Execute("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_PAYMENT_COINBASE_STATUS'");
            $this->_check = $check_query->RecordCount();
        }
        return $this->_check;
    }

    /**
     * Checks referrer
     *
     * @param string $zf_domain
     * @return boolean
     */
    function check_referrer($zf_domain)
    {
        return true;
    }
    /**
     * Build admin-page components
     *
     * @param int $zf_order_id
     * @return string
     */
    function admin_notification($zf_order_id)
    {
        $output = '';

        return $output;
    }

    /**
     * Post-processing activities
     * When the order returns from the processor, this stores the results in order-status-history and logs data for subsequent reference
     *
     * @return boolean
     */
    public function after_order_create($insert_id)
    {
        global $db, $order;

        $sql_data_array = array(
            array('fieldName' => 'orders_id', 'value' => $insert_id, 'type' => 'integer'),
            array('fieldName' => 'orders_status_id', 'value' => $this->order_status, 'type' => 'integer'),
            array('fieldName' => 'date_added', 'value' => 'now()', 'type' => 'noquotestring'),
            array('fieldName' => 'customer_notified', 'value' => 0, 'type' => 'integer'),
        );

        $db->perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);

        $products = array_map(function ($item) {
            return $item['qty'] . ' x ' . $item['name'];
        }, $order->products);

        $chargeData = array(
            'local_price' => array(
                'amount' => $order->info['total'],
                'currency' => $order->info['currency'],
            ),
            'pricing_type' => 'fixed_price',
            'name' => STORE_NAME . ' order #' . $insert_id,
            'description' => mb_substr(join($products, ', '), 0, 200),
            'metadata' => [
                METADATA_SOURCE_PARAM => METADATA_SOURCE_VALUE,
                METADATA_INVOICE_PARAM => $insert_id,
                METADATA_CLIENT_PARAM => $_SESSION['customer_id'],
                'email' => $order->customer['email_address'],
                'first_name' => replace_accents($order->delivery['firstname'] != '' ? $order->delivery['firstname'] : $order->billing['firstname']),
                'last_name' => replace_accents($order->delivery['lastname'] != '' ? $order->delivery['lastname'] : $order->billing['lastname']),
            ],
            'redirect_url' => zen_href_link(FILENAME_CHECKOUT_SUCCESS, 'checkout_id=' . $insert_id, 'SSL'),
            'cancel_url' => zen_href_link(FILENAME_CHECKOUT_PAYMENT)
        );

        try {
            \CoinbaseCommerce\ApiClient::init(MODULE_PAYMENT_COINBASE_API_KEY);
            $chargeObj = \Coinbase\Resources\Charge::create($chargeData);
        } catch (\Exception $exception) {
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, 'error_message=' . $exception->getMessage(), 'SSL', true, false));
        }
        $checkoutUrl = $chargeObj->hosted_url;
        $_SESSION['cart']->reset(true);


        echo '<form name="coinbase" id="coinbase" action="' . $checkoutUrl . '" method="GET">';
        echo '<center>If you are not automatically redirected please click the button below:<br />';
        echo '<a  href="' . $checkoutUrl . '"><input type="button" value="Go to checkout"></a>';
        echo '</center></form>';
        echo '<script type="text/javascript">document.coinbase.submit();</script>';
    }

    /**
     * Used to display error message details
     *
     * @return boolean
     */
    function output_error()
    {
        return false;
    }

    function install()
    {
        global $db, $messageStack;

        if (defined('MODULE_PAYMENT_COINBASE_STATUS')) {
            $messageStack->add_session('Coinbase Commerce module already installed.', 'error');
            zen_redirect(zen_href_link(FILENAME_MODULES, 'set=payment&module=coinbase', 'NONSSL'));
            return 'failed';
        }

        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Enable Coinbase Commerce Module', 'MODULE_PAYMENT_COINBASE_STATUS', 'True', 'Do you want to accept CoinBase Commerce payments?', '6', '1', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('API Key', 'MODULE_PAYMENT_COINBASE_API_KEY','', 'Get API Key from Coinbase Commerce Dashboard <a href=\"https://commerce.coinbase.com/dashboard/settings\" target=\"_blank\">Settings &gt; API keys &gt; Create an API key</a>', '6', '2', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Shared Secret', 'MODULE_PAYMENT_COINBASE_SHARED_SECRET','', 'Get Shared Secret Key from Coinbase Commerce Dashboard <a href=\"https://commerce.coinbase.com/dashboard/settings\" target=\"_blank\">Settings &gt; Show Shared Secrets</a>', '6', '3', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) VALUES ('Sort order of display.', 'MODULE_PAYMENT_COINBASE_SORT_ORDER', '0', 'Sort order of display. Lowest is displayed first.', '6', '5', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Pending Order Status', 'MODULE_PAYMENT_COINBASE_PENDING_STATUS_ID', '" . DEFAULT_ORDERS_STATUS_ID .  "', 'Set the status of orders made with this payment module that are not yet completed to this value<br />(\'Pending\' recommended)', '6', '6', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Expired Order Status', 'MODULE_PAYMENT_COINBASE_EXPIRED_STATUS_ID', '" . DEFAULT_ORDERS_STATUS_ID .  "', 'Set the status of orders made with this payment module that have expired<br />(\'Expired\' recommended)', '6', '6', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Canceled Order Status', 'MODULE_PAYMENT_COINBASE_CANCELED_STATUS_ID', '" . DEFAULT_ORDERS_STATUS_ID .  "', 'Set the status of orders made with this payment module that have been canceled<br />(\'Canceled\' recommended)', '6', '6', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Unresolved Order Status', 'MODULE_PAYMENT_COINBASE_UNRESOLVED_STATUS_ID', '" . DEFAULT_ORDERS_STATUS_ID .  "', 'Set the status of orders made with this payment module that have been unresolved', '6', '6', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Complete Order Status', 'MODULE_PAYMENT_COINBASE_PROCESSING_STATUS_ID', '2', 'Set the status of orders made with this payment module that have completed payment to this value<br />(\'Processing\' recommended)', '6', '7', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
    }

    /**
     * Remove the module and all its settings
     *
     */
    function remove()
    {
        global $db;
        $db->Execute("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key IN ('" . implode("', '", $this->keys()) . "')");
    }

    /**
     * Internal list of configuration keys used for configuration of the module
     *
     * @return array
     */
    function keys()
    {
        return array(
            'MODULE_PAYMENT_COINBASE_STATUS',
            'MODULE_PAYMENT_COINBASE_API_KEY',
            'MODULE_PAYMENT_COINBASE_SHARED_SECRET',
            'MODULE_PAYMENT_COINBASE_PENDING_STATUS_ID',
            'MODULE_PAYMENT_COINBASE_PROCESSING_STATUS_ID',
            'MODULE_PAYMENT_COINBASE_EXPIRED_STATUS_ID',
            'MODULE_PAYMENT_COINBASE_CANCELED_STATUS_ID',
            'MODULE_PAYMENT_COINBASE_UNRESOLVED_STATUS_ID',
            'MODULE_PAYMENT_COINBASE_SORT_ORDER'
        );
    }
}
