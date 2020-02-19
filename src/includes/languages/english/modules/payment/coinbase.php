<?php

include_once __DIR__ . '/../../../../configure.php';

if (ENABLE_SSL == true) {
    $link = HTTPS_SERVER . DIR_WS_HTTPS_CATALOG;
} else {
    $link = HTTP_SERVER . DIR_WS_CATALOG;
}

define('MODULE_PAYMENT_COINBASE_TEXT_TITLE', 'Coinbase Commerce <a href="https://commerce.coinbase.com/" target="_blank" rel=“noopener”>(Learn more)</a>');
define('MODULE_PAYMENT_COINBASE_TEXT_DESCRIPTION', 'Coinbase Commerce is a service that enables merchants to accept multiple cryptocurrencies directly into a user-controlled wallet. </br>'
    . 'For instant coinbase\'s payment notificatins please copy/paste <b>' . $link . zen_output_string('ipn_coinbase.php') . '</b> url to Settings/Webhook subscription <a href="https://commerce.coinbase.com/dashboard/settings" target="_blank">https://commerce.coinbase.com/dashboard/settings</a>');
define('MODULE_PAYMENT_COINBASE_TEXT_CATALOG_TITLE', 'Coinbase Commerce  - Bitcoin/Bitcoin cash/DAI/Litecoin/Etherium/USD Coin Payments');
