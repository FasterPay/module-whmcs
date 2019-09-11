<?php
if (!defined("WHMCS")) {
    exit("This file cannot be accessed directly");
}

require_once(ROOTDIR . '/modules/gateways/fasterpay/helpers/FasterpayHelper.php');
require_once(ROOTDIR . '/modules/gateways/fasterpay/FasterpayGateway.php');
require_once(ROOTDIR . '/modules/gateways/fasterpay/FasterpayPingback.php');
require_once(ROOTDIR . '/includes/api/fasterpay_api/lib/autoload.php');

function fasterpay_MetaData()
{
    return array(
        'DisplayName' => 'Fasterpay',
        'APIVersion' => '1.1', // Use API Version 1.1
        'DisableLocalCreditCardInput' => false,
        'TokenisedStorage' => false,
    );
}

function fasterpay_config()
{
    $configs = array(
        "FriendlyName" => array("Type" => "System", "Value" => "Fasterpay"),
        "UsageNotes" => array("Type" => "System", "Value" => "Please read the documentation to get more informations"),
        "appKey" => array("FriendlyName" => "Public Key", "Type" => "text", "Size" => "40"),
        "secretKey" => array("FriendlyName" => "Private Key", "Type" => "text", "Size" => "40"),
        "success_url" => array("FriendlyName" => "Success Url", "Type" => "text", "Size" => "200"),
        "isTest" => array("FriendlyName" => "Is Test", "Type" => "yesno", "Description" => "Tick this box to enable Test mode"),
        'signVersion' => array(
            'FriendlyName' => 'Signature Version',
            'Type' => 'dropdown',
            'Options' => array(
                'v1' => 'Version 1',
                'v2' => 'Version 2',
            ),
            'Description' => '',
        ),

    );

    return $configs;
}

function fasterpay_link($params)
{

    $gateway = new FasterPay\Gateway([
        'publicKey' => $params['appKey'],
        'privateKey' => $params['secretKey'],
        'isTest' => $params['isTest'] == 'on' ? 1 : 0,
    ]);
    $fasterPayModel = new Fasterpay_Gateway();

    $form = $gateway->paymentForm()->buildForm(
        $fasterPayModel->prepareData($params),
        [
            'autoSubmit' => true,
            'hidePayButton' => true
        ]
    );
    return $form;
}

function fasterpay_refund($params)
{
    if (strtolower($params['paymentmethod']) != 'fasterpay') {
        return array(
            'status' => 'error',
            'rawdata' => 'Wrong payment gateway',
        );
    }

    $orderId = $params['transid'];
    $amount = $params['amount'];

    // if no admin session found -> run in refund pingback
    if (!isAdminLoggedIn()) {
        return array(
            'status' => 'success',
            'rawdata' => 'success',
            'transid' => $orderId,
        );
    }

    $gateway = new FasterPay\Gateway([
        'publicKey' => $params['appKey'],
        'privateKey' => $params['secretKey'],
        'isTest' => $params['isTest'] == 'on' ? 1 : 0,
    ]);

    try {
        $refundResponse = $gateway->paymentService()->refund($orderId, $amount);
    } catch (FasterPay\Exception $e) {
        return array(
            'status' => 'error',
            'rawdata' => $e->getMessage(),
        );
    }

    if ($refundResponse->isSuccessful()) {
        $customStatus = array(
            'message_type' => 'custom',
            'title' => 'Pending Refund Transaction',
            'content' => 'Your transaction is being processed!'
        );

        $reponseData = $refundResponse->getResponse('data');
        $referenceId = $reponseData['reference_id'];
        $fpTxnId = $reponseData['id'];
        $status = $reponseData['status'];

        $helper = new Fasterpay_Helper();
        if (!$helper->referenceIdExisted($referenceId)) {
            $helper->logReferenceId($referenceId, $fpTxnId);
            
            if (in_array($status, FasterPay_Pingback::ALL_REFUNDED_STATUS)) {
                return array(
                    'status' => 'success',
                    'rawdata' => 'success',
                    'transid' => $orderId,
                );
            }
        }

        if (in_array($status, FasterPay_Pingback::ALL_REFUNDED_STATUS)) {
            $customStatus['title'] = 'Refund Successful';
            $customStatus['content'] = 'The requested amount has now been refunded by the payment gateway';
        }

        return array(
            'status' => $customStatus['message_type'] . ':' . $customStatus['title'] . ':' . $customStatus['content'],
            'rawdata' => $customStatus['content']
        );
    } else {
        return array(
            'status' => 'error',
            'rawdata' => $refundResponse->getErrors()->getMessage(),
        );
    }

}

function fasterpay_cancelSubscription($params)
{
    if (empty($params['subscriptionID'])) {
        return array(
            'status' => 'error',
            'rawdata' => 'missing subscription id'
        );
    }

    if (strtolower($params['paymentmethod']) != 'fasterpay') {
        return array(
            'status' => 'error',
            'rawdata' => 'Wrong payment gateway',
        );
    }

    $gateway = new FasterPay\Gateway([
        'publicKey' => $params['appKey'],
        'privateKey' => $params['secretKey'],
        'isTest' => $params['isTest'] == 'on' ? 1 : 0,
    ]);

    $subscriptionId = $params['subscriptionID'];
    try {
        $cancellationResponse = $gateway->subscriptionService()->cancel($subscriptionId);
    } catch (FasterPay\Exception $e) {
        return array(
            'status' => 'error',
            'rawdata' => $e->getMessage(),
        );

    }
    if ($cancellationResponse->isSuccessful()) {
        return array(
            'status' => 'success',
            'rawdata' => 'success',
        );
    } else {
        return array(
            'status' => 'error',
            'rawdata' => $cancellationResponse->getErrors()->getMessage(),
        );
    }
}

function isAdminLoggedIn()
{
    return !empty($_SESSION['adminid']);
}
