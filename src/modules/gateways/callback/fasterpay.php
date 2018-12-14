<?php
# Required File Includes
if (!file_exists("../../../init.php")) {
    // For v5.x
    include("../../../dbconnect.php");
} else {
    // For v6.x, v7.x
    include("../../../init.php");
}

include(ROOTDIR . "/includes/functions.php");
include(ROOTDIR . "/includes/gatewayfunctions.php");
include(ROOTDIR . "/includes/invoicefunctions.php");
require_once(ROOTDIR . '/modules/gateways/fasterpay/helpers/FasterpayHelper.php');
require_once(ROOTDIR . '/modules/gateways/fasterpay/FasterpayGateway.php');


class FasterPay_Pingback {

	protected $request = array();
	protected $invoiceId = null;

	const PINGBACK_PAYMENT_TYPE = 'payment';
	const PINGBACK_STATUS_SUCCESS = 'successful';	

	public function __construct($request) {
		$this->request = $request;
		$this->helper = new Fasterpay_Helper();

		return;
	}

	public function run() {

		$invoiceId = $this->getInvoiceIdPingback();

		$this->validateInvoiceId();
		
		$gateway = $this->getGatewayData($invoiceId);

		if (!$gateway["type"]) {
		    exit($gateway['name'] . " is not activated");
		}

		if (!$this->validatePingback(array('secret' => $gateway['secretKey']))) {
			logTransaction($gateway["name"], $this->request, "Unsuccessful");
			exit('Invalid Pingback');
		}

		if ($this->request['event'] == self::PINGBACK_PAYMENT_TYPE && $this->request['payment_order']['status'] == self::PINGBACK_STATUS_SUCCESS) {
			$this->processDeliverable($invoiceId, $gateway);
		}

		echo 'OK';
		exit();
	}

	public function getInvoiceData($invoiceId) {
		return mysql_fetch_assoc(select_query('tblinvoices', 'userid,total,paymentmethod', ["id" => $invoiceId]));
	}

	public function getGatewayData($invoiceId) {
		$invoiceData = $this->getInvoiceData($invoiceId);
		$gateway = getGatewayVariables($invoiceData['paymentmethod']);
		return $gateway;
	}

	public function validatePingback($params) {
		
		if (empty($_SERVER['HTTP_X_APIKEY'])) {
			return false;
		}

		if ($_SERVER['HTTP_X_APIKEY'] != $params['secret']) {
			return false;
		}

		return true;
	}

	public function processDeliverable($invoiceId, $gateway)
	{
		$paymentOrder = $this->request['payment_order'];

	    $invoice = mysql_fetch_assoc(select_query('tblinvoices', '*', ['id' => $invoiceId]));
	    $hosting = [];

    	$hostIdArray = $this->helper->getHostIdFromInvoice($params['invoiceid']);
	    if ($hostIdArray) {
	        $hosting = mysql_fetch_assoc(select_query(
	            'tblhosting', // table name
	            'tblhosting.id,tblhosting.username,tblhosting.packageid,tblhosting.userid', // fields name
	            ["tblhosting.id" => $hostIdArray['id']], // where conditions
	            false, // order by
	            false, // order by order
	            1 // limit
	        ));
	    }

	    if ($invoice['status'] == 'Unpaid') {
	        addInvoicePayment($invoiceId, $paymentOrder['id'], null, null, $gateway['paymentmethod']);

	        if (!empty($this->request['subscription'])) {
	            updateSubscriptionId($this->request['subscription']['id'], ['id' => $hosting['id']]);
	        }

	    } else {

	        // If Overpaid order, add credit to client
	        if ($hosting && !empty($this->request['subscription'])) {

	            $recurring = $this->helper->getRecurringBillingValuesFromInvoice($invoiceId);
	            $amount = (float)$recurring['firstpaymentamount'] ? $recurring['firstpaymentamount'] : $recurring['recurringamount'];

	            // Add credit
	            insert_query("tblaccounts", [
	                "userid" => $hosting['userid'],
	                "currency" => 0,
	                "gateway" => $gateway['paymentmethod'],
	                "date" => "now()",
	                "description" => ucfirst($gateway['paymentmethod']) . " Credit Payment for Invoice #" . $invoiceId,
	                "amountin" => $amount,
	                "fees" => 0,
	                "rate" => 1,
	                "transid" => $paymentOrder['id']
	            ]);

	            insert_query("tblcredit", [
	                "clientid" => $hosting['userid'],
	                "date" => "now()",
	                "description" => "Subscription Transaction ID " . $paymentOrder['id'],
	                "amount" => $amount
	            ]);

	            update_query("tblclients", ["credit" => "+=" . $amount], ["id" => $hosting['userid']]);
	            logTransaction($gateway['paymentmethod'], "Credit Subscription ID " . $paymentOrder['id'] . " with Amount is " . $amount, "Credit Added");
	        }
	    }
	    logTransaction($gateway['name'], $this->request, "Successful");
	}

	public function validateInvoiceId() {
		if(empty($this->invoiceId)) {
		    exit("Invoice is not found");
		} elseif ($this->invoiceId == 'Invoice is already paid') {
			exit($this->invoiceId);
		}
	}

	public function getInvoiceIdPingback()	{
		$requestData = $this->request;

	    $goodsId = $requestData['payment_order']['merchant_order_id'];

	    if (strpos($goodsId,":") === false) {
	        return null;
	    }

        $goodsArray = explode(":",$goodsId);

        if (!isset($requestData['subscription'])) {
            $query = "
                SELECT *
                FROM tblinvoices i 
                WHERE i.id = ".$goodsArray[1]."
                AND i.paymentmethod = '" . Fasterpay_Gateway::GATEWAY_NAME . "' 
                LIMIT 0,1
            ";
            
            $result = full_query($query);
            $data = mysql_fetch_assoc($result);

            if (empty($data)) {
            	return null;
            }

            if ($data['status'] == 'Paid') {
            	$this->invoiceId = 'Invoice is already paid';
                return 'Invoice is already paid';
            } else {
                $invoiceId = $goodsArray[1];
            }
        } else {
            $query = "
                SELECT it.*, i.status
                FROM tblinvoiceitems it
                INNER JOIN tblinvoices i ON i.id=it.invoiceid
                WHERE it.relid = " . $goodsArray[0] . " 
                AND it.type = '" . $goodsArray[2] . "'
                AND it.userid = '" . $goodsArray[3] . "' 
                AND i.paymentmethod = '" . Fasterpay_Gateway::GATEWAY_NAME . "'
                ORDER BY i.id ASC
            ";
            $result = full_query($query);
            $invoiceList = array();
            while ($data = mysql_fetch_assoc($result)) {
                $invoiceList[] = $data;
            }

            $invoiceId = $this->getInvoiceFromInvoiceList($invoiceList);
        }
	    
		$this->invoiceId = $invoiceId;

	    return $invoiceId;
	}

	function getInvoiceFromInvoiceList($invoiceList) {
	    $invoiceid = null;
	    if (count($invoiceList) == 1) {
	        if ($this->request['payment_order']['status'] == self::PINGBACK_STATUS_SUCCESS && $invoiceList[0]['status'] == 'Paid') {
	            $invoiceid = 'Invoice is already paid';
	        } else {
	            $invoiceid = $invoiceList[0]['invoiceid'];
	        }
	    } else {
	        foreach ($invoiceList as $inv) {
	            if ($this->request['payment_order']['status'] == self::PINGBACK_STATUS_SUCCESS  && $inv['status'] == 'Unpaid') {
	                $invoiceid = $inv['invoiceid'];
	                break;
	            }
	        }
	    }
	    return $invoiceid;
	}
}

$fasterpayPingback = new FasterPay_Pingback($_REQUEST);
$fasterpayPingback->run();


