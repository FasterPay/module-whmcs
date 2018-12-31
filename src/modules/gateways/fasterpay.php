<?php
if (!defined("WHMCS")) {
    exit("This file cannot be accessed directly");
}


require_once(ROOTDIR . '/modules/gateways/fasterpay/helpers/FasterpayHelper.php');
require_once(ROOTDIR . '/modules/gateways/fasterpay/FasterpayGateway.php'); 

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
        "success_url" => array("FriendlyName" => "Success Url", "Type" => "text", "Size" => "200")
    );

    return $configs;
}

function fasterpay_link($params)
{

    $fasterpayGateway = new Fasterpay_Gateway();

    $parameters = $fasterpayGateway->prepareData($params);

    $code = $fasterpayGateway->buildForm($parameters);

    return $code;
}

