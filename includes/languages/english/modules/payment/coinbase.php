<?php

include_once __DIR__ . '/../../../../configure.php';

if (ENABLE_SSL == true) {
    $link = HTTPS_SERVER . DIR_WS_HTTPS_CATALOG;
} else {
    $link = HTTP_SERVER . DIR_WS_CATALOG;
}

define('MODULE_PAYMENT_COINBASE_TEXT_TITLE', 'Coinbase Commerce  - Bitcoin/Bitcoin Cash/Litecoin/Etherium Payments');
define('MODULE_PAYMENT_COINBASE_TEXT_DESCRIPTION', 'Coinbase Commerce is a service that enables merchants to accept multiple cryptocurrencies directly into a user-controlled wallet. </br>'
    . 'For instant coinbase\'s payment notificatins please copy/paste <b>' . $link . zen_output_string('ipn_coinbase.php') . '</b> url  to Settings/Webhook subscription https://commerce.coinbase.com/dashboard/settings');
define('MODULE_PAYMENT_COINBASE_TEXT_CATALOG_TITLE', 'Coinbase Commerce  - Bitcoin/Bitcoin cash/Litecoin/Etherium Payments');
