<?php
require_once 'includes/application_top.php';
require_once __DIR__ . '/includes/modules/payment/coinbase/autoload.php';
require_once __DIR__ . '/includes/modules/payment/coinbase/const.php';

class Webhook
{
    private $params;

    public function __construct()
    {
        $this->loadModuleParams();
    }

    private function loadModuleParams()
    {
        global $db;
        $query = $db->Execute("SELECT configuration_key,configuration_value FROM " . TABLE_CONFIGURATION
            . " WHERE configuration_key LIKE 'MODULE\_PAYMENT\_COINBASE\_%'");

        if ($query->RecordCount() === 0) {
            $this->failProcess('Settings not found.');
        }

        while (!$query->EOF) {
            $this->params[$query->fields['configuration_key']] = $query->fields['configuration_value'];
            $query->MoveNext();
        }
    }

    private function getModuleParam($paramName)
    {
        return array_key_exists($paramName, $this->params) ? $this->params[$paramName] : null;
    }

    private function failProcess($errorMessage)
    {
        http_response_code(500);
        die();
    }

    public function process()
    {
        $event = $this->getEvent();
        $charge = $this->getCharge($event->data['id']);

        if (($orderId = $charge->metadata[METADATA_INVOICE_PARAM]) === null
            || ($userId = $charge->metadata[METADATA_CLIENT_PARAM]) === null) {
            $this->failProcess('Invoice ID or client ID was not found in charge');
        }
        $this->checkOrder($orderId, $userId);
        $lastTimeLine = end($charge->timeline);

        switch ($lastTimeLine['status']) {
            case 'RESOLVED':
            case 'COMPLETED':
                $this->handlePaid($orderId, $charge);
                return;
            case 'PENDING':
                $this->updateOrderStatus(
                    $orderId,
                    $this->getModuleParam('MODULE_PAYMENT_COINBASE_PENDING_STATUS_ID'),
                    sprintf(
                        'Charge %s was pending. Charge has been detected but has not been confirmed yet.',
                        $charge['id']
                    )
                );
                return;
            case 'NEW':
                $this->updateOrderStatus(
                    $orderId,
                    $this->getModuleParam('MODULE_PAYMENT_COINBASE_PENDING_STATUS_ID'),
                    sprintf('Charge %s was created. Awaiting payment.', $charge['id'])
                );
                return;
            case 'UNRESOLVED':
                // mark order as paid on overpaid or delayed
                if ($lastTimeLine['context'] === 'OVERPAID') {
                    $this->handlePaid($orderId, $charge);
                } else {
                    $this->updateOrderStatus(
                        $orderId,
                        $this->getModuleParam('MODULE_PAYMENT_COINBASE_UNRESOLVED_STATUS_ID'),
                        sprintf('Charge %s was unresolved.', $charge['id'])
                    );
                }
                return;
            case 'CANCELED':
                $this->updateOrderStatus(
                    $orderId,
                    $this->getModuleParam('MODULE_PAYMENT_COINBASE_CANCELED_STATUS_ID'),
                    sprintf('Charge %s was canceled.', $charge['id'])
                );
                return;
            case 'EXPIRED':
                $this->updateOrderStatus(
                    $orderId,
                    $this->getModuleParam('MODULE_PAYMENT_COINBASE_EXPIRED_STATUS_ID'),
                    sprintf('Charge %s was expired.', $charge['id'])
                );
                return;
        }
    }

    private function handlePaid($orderId, $charge)
    {
        $transactionId = null;

        foreach ($charge->payments as $payment) {
            if (strtolower($payment['status']) === 'confirmed') {
                $transactionId = $payment['transaction_id'];
                $amount = $payment['value']['local']['amount'];
                $currency = $payment['value']['local']['currency'];
            }
        }

        if ($transactionId) {
            $this->updateOrderStatus(
                $orderId,
                $this->getModuleParam('MODULE_PAYMENT_COINBASE_PROCESSING_STATUS_ID'),
                sprintf('Charge %s was paid. Received amount %s %s', $charge['id'], $amount, $currency)
            );
        } else {
            $this->failProcess(sprintf('Invalid charge %s. No transaction found.', $charge['id']));
        }
    }

    function updateOrderStatus($orderId, $newOrderStatus, $comments)
    {
        global $db;
        $sql_data_array = array(array('fieldName' => 'orders_id', 'value' => $orderId, 'type' => 'integer'),
            array('fieldName' => 'orders_status_id', 'value' => $newOrderStatus, 'type' => 'integer'),
            array('fieldName' => 'date_added', 'value' => 'now()', 'type' => 'noquotestring'),
            array('fieldName' => 'comments', 'value' => $comments, 'type' => 'string'),
            array('fieldName' => 'customer_notified', 'value' => 0, 'type' => 'integer'));
        $db->perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
        $db->Execute("UPDATE " . TABLE_ORDERS . "SET `orders_status` = '" . (int)$newOrderStatus
            . "'WHERE `orders_id` = '" . (int)$orderId . "'");
    }

    private function getEvent()
    {
        $secretKey = $this->getModuleParam('MODULE_PAYMENT_COINBASE_SHARED_SECRET');
        $headers = array_change_key_case(getallheaders());
        $signatureHeader = isset($headers[SIGNATURE_HEADER]) ? $headers[SIGNATURE_HEADER] : null;
        $payload = trim(file_get_contents('php://input'));

        try {
            $event = \CoinbaseCommerce\Webhook::buildEvent($payload, $signatureHeader, $secretKey);
        } catch (\Exception $exception) {
            $this->failProcess($exception->getMessage());
        }

        return $event;
    }

    private function getCharge($chargeId)
    {
        $apiKey = $this->getModuleParam('MODULE_PAYMENT_COINBASE_API_KEY');
        \CoinbaseCommerce\ApiClient::init($apiKey);

        try {
            $charge = \CoinbaseCommerce\Resources\Charge::retrieve($chargeId);
        } catch (\Exception $exception) {
            $this->failProcess($exception->getMessage());
        }

        if (!$charge) {
            $this->failProcess('Charge was not found in Coinbase Commerce.');
        }

        if ($charge->metadata[METADATA_SOURCE_PARAM] != METADATA_SOURCE_VALUE) {
            $this->failProcess( 'Not ' . METADATA_SOURCE_VALUE . ' charge');
        }

        return $charge;
    }

    private function checkOrder($orderId, $customerId)
    {
        global $db;
        $query = "SELECT * FROM " . TABLE_ORDERS . " WHERE `orders_id`='" . zen_db_input($orderId)
            . "' AND `customers_id`='" . zen_db_input($customerId) . "' ORDER BY `orders_id` DESC";
        $query = $db->Execute($query);

        if ($query->RecordCount() === 0) {
            $this->failProcess('Order is not exists.');
        }

        return true;
    }
}

$webhook = new Webhook();
$webhook->process();
