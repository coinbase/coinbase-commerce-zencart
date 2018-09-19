<?php
require_once 'includes/application_top.php';
require_once __DIR__ . '/includes/modules/payment/coinbase/init.php';
require_once __DIR__ . '/includes/modules/payment/coinbase/const.php';

function updateOrderStatus($orderId, $newOrderStatus, $comments)
{
    global $db;
    $sql_data_array = array(array('fieldName' => 'orders_id', 'value' => $orderId, 'type' => 'integer'),
        array('fieldName' => 'orders_status_id', 'value' => $newOrderStatus, 'type' => 'integer'),
        array('fieldName' => 'date_added', 'value' => 'now()', 'type' => 'noquotestring'),
        array('fieldName' => 'comments', 'value' => $comments, 'type' => 'string'),
        array('fieldName' => 'customer_notified', 'value' => 0, 'type' => 'integer'));
    $db->perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
    $db->Execute("UPDATE " . TABLE_ORDERS . "
                  SET `orders_status` = '" . (int)$newOrderStatus . "'
                  WHERE `orders_id` = '" . (int)$orderId . "'");
}

$debug_email = '';
function sendDebugEmail($message = '', $http_error = false)
{
    global $debug_email;
    if (!empty($debug_email)) {
        $str = "Coinbase IPN Debug Report\n\n";
        if (!empty($message)) {
            $str .= "Debug/Error Message: " . $message . "\n\n";
        }

        $str .= "POST Vars\n\n";
        foreach ($_POST as $k => $v) {
            $str .= "$k => $v \n";
        }
        $str .= "\nGET Vars\n\n";
        foreach ($_GET as $k => $v) {
            $str .= "$k => $v \n";
        }
        $html_msg['EMAIL_MESSAGE_HTML'] = nl2br($str);

        @mail($debug_email, 'Coinbase IPN', $str);
    }
    if ($http_error) {
        header("500 Internal Server Error");
    }
    die("[IPN Error]: " . $message . "\n");
}

$query = $db->Execute("SELECT configuration_key,configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key LIKE 'MODULE\_PAYMENT\_COINBASE\_%'");

while (!$query->EOF) {
    if ($query->fields['configuration_key'] == "MODULE_PAYMENT_COINBASE_SHARED_SECRET") {
        $sharedSecret = $query->fields['configuration_value'];
    } else if ($query->fields['configuration_key'] == "MODULE_PAYMENT_COINBASE_PENDING_STATUS_ID") {
        $pendingStatusId = $query->fields['configuration_value'];
    } else if ($query->fields['configuration_key'] == "MODULE_PAYMENT_COINBASE_PROCESSING_STATUS_ID") {
        $processingStatusId = $query->fields['configuration_value'];
    }

    $query->MoveNext();
}

if (empty($sharedSecret)) {
    die('[ERROR] Shared secret secret not set in admin panel.');
}

$headers = array_change_key_case(getallheaders());
$signatureHeader = isset($headers[SIGNATURE_HEADER]) ? $headers[SIGNATURE_HEADER] : null;
$payload = trim(file_get_contents('php://input'));

try {
    $event = \Coinbase\Webhook::buildEvent($payload, $signatureHeader, $sharedSecret);
} catch (\Exception $exception) {
    sendDebugEmail($exception->getMessage());
}

$charge = $event->data;

if ($charge->getMetadataParam(METADATA_SOURCE_PARAM) != METADATA_SOURCE_VALUE) {
    sendDebugEmail('Not whmcs charge');
}

if (($orderId = $charge->getMetadataParam('invoiceid')) === null
    || ($customerId = $charge->getMetadataParam('clientid')) === null) {
    sendDebugEmail('Invoice id is not found in charge');
}

$query = "SELECT * FROM " . TABLE_ORDERS . " WHERE `orders_id`='" . zen_db_input($orderId) . "' AND `customers_id`='" . zen_db_input($customerId) . "'  ORDER BY `orders_id` DESC";
$query = $db->Execute($query);

if ($query->RecordCount() < 1) {
    sendDebugEmail('Order is not exists');
}

$total = $query->fields['order_total'];
$currency = $query->fields['currency'];

switch ($event->type) {
    case 'charge:created':
        updateOrderStatus($orderId, $pendingStatusId, sprintf('Charge was created. Charge Id: %s', $charge->id));
        break;
    case 'charge:failed':
        updateOrderStatus($orderId, $pendingStatusId, sprintf('Charge was failed. Charge Id: %s', $charge->id));
        break;
    case 'charge:delayed':
        updateOrderStatus($orderId, $pendingStatusId, sprintf('Charge was delayed. Charge Id: %s', $charge->id));
        break;
    case 'charge:confirmed':
        $transactionId = '';
        $total = '';
        $currency = '';

        foreach ($charge->payments as $payment) {
            if (strtolower($payment['status']) === 'confirmed') {
                $transactionId = $payment['transaction_id'];
                $total = isset($payment['value']['local']['amount']) ? $payment['value']['local']['amount'] : $total;
                $currency = isset($payment['value']['local']['currency']) ? $payment['value']['local']['currency'] : $currency;
            }
        }

        updateOrderStatus($orderId, $processingStatusId, sprintf('Received %s %S. Transaction id %s', $total, $currency, $transactionId));
        break;
}
