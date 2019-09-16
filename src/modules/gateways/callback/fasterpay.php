<?php
# Required File Includes
if (!file_exists("../../../init.php")) {
    // For v5.x
    include_once("../../../dbconnect.php");
} else {
    // For v6.x, v7.x
    include_once("../../../init.php");
}

require_once(ROOTDIR . '/modules/gateways/fasterpay/FasterpayPingback.php');
require_once(ROOTDIR . '/includes/api/fasterpay_api/lib/autoload.php');

$signVersion = FasterPay\Services\Signature::SIGN_VERSION_1;
if (!empty($_SERVER['HTTP_X_FASTERPAY_SIGNATURE_VERSION'])) {
    $signVersion = $_SERVER['HTTP_X_FASTERPAY_SIGNATURE_VERSION'];
}

$pingbackData = array();
switch ($signVersion) {
    case FasterPay\Services\Signature::SIGN_VERSION_1:
        $pingbackData = $_REQUEST;
        break;
    case FasterPay\Services\Signature::SIGN_VERSION_2:
        $pingbackData = json_decode(file_get_contents('php://input'), 1);
        break;
}

$fasterpayPingback = new FasterPay_Pingback($pingbackData);
$fasterpayPingback->run();