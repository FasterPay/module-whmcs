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
include(ROOTDIR . "/includes/ccfunctions.php");
include(ROOTDIR . "/includes/gatewayfunctions.php");
include(ROOTDIR . "/includes/invoicefunctions.php");
require_once(ROOTDIR . '/modules/gateways/fasterpay/helpers/FasterpayHelper.php');
require_once(ROOTDIR . '/modules/gateways/fasterpay/FasterpayGateway.php');
require_once(ROOTDIR . '/includes/api/fasterpay_api/lib/autoload.php');

class FasterPay_Pingback {

    protected $request = array();
    protected $invoiceId = null;

    const PINGBACK_PAYMENT_EVENT = 'payment';
    const PINGBACK_FULL_REFUND_EVENT = 'refund';
    const PINGBACK_PARTIAL_REFUND_EVENT = 'partial_refund';
    
    const PINGBACK_REFUND_EVENTS = array(
        self::PINGBACK_PARTIAL_REFUND_EVENT,
        self::PINGBACK_FULL_REFUND_EVENT
    );
    
    const PINGBACK_STATUS_SUCCESS = 'successful';

    public function __construct($request) {
        $this->request = $request;
        $this->helper = new Fasterpay_Helper();

        return;
    }

    public function run() {
        $invoiceId = $this->getInvoiceIdPingback($this->isRefundEvent());

        $this->validateInvoiceId($invoiceId);

        $gateway = $this->getGatewayData($invoiceId);
        if (!$gateway["type"]) {
            exit($gateway['name'] . " is not activated");
        }

        if (!$this->validatePingback($gateway, array('secret' => $gateway['secretKey']))) {
            logTransaction($gateway["name"], $this->request, "Unsuccessful");
            exit('Invalid Pingback');
        }

        if ($this->isPaymentEvent() && $this->request['payment_order']['status'] == self::PINGBACK_STATUS_SUCCESS) {
            $this->processDeliverable($invoiceId, $gateway);
        } elseif ($this->isRefundEvent()) {
            $this->processRefundPingback($invoiceId, $gateway);
        }

        exit('OK');
    }

    public function getInvoiceData($invoiceId) {
        return mysql_fetch_assoc(select_query('tblinvoices', 'userid,total,paymentmethod', ["id" => $invoiceId]));
    }

    public function getGatewayData($invoiceId) {
        $invoiceData = $this->getInvoiceData($invoiceId);
        $gateway = getGatewayVariables($invoiceData['paymentmethod']);
        return $gateway;
    }

    public function validatePingback($gateway = array()) {

        $fpGateway = new FasterPay\Gateway([
            'publicKey' => $gateway['appKey'],
            'privateKey' => $gateway['secretKey'],
            'isTest' => $gateway['isTest'] == 'on' ? 1 : 0,
        ]);

        $signVersion = FasterPay\Services\Signature::SIGN_VERSION_1;
        if (!empty($_SERVER['HTTP_X_FASTERPAY_SIGNATURE_VERSION'])) {
            $signVersion = $_SERVER['HTTP_X_FASTERPAY_SIGNATURE_VERSION'];
        }

        switch ($signVersion) {
            case FasterPay\Services\Signature::SIGN_VERSION_1:
                $validationParams = array("apiKey" => $_SERVER["HTTP_X_APIKEY"]);
                $pingbackData = $_REQUEST;
                break;
            case FasterPay\Services\Signature::SIGN_VERSION_2:
                $validationParams = array(
                    'pingbackData' => file_get_contents('php://input'),
                    'signVersion' => $signVersion,
                    'signature' => $_SERVER["HTTP_X_FASTERPAY_SIGNATURE"],
                );
                $pingbackData = json_decode(file_get_contents('php://input'), 1);
                break;
            default:
                return false;
        }

        if (empty($pingbackData)) {
            return false;
        }

        if (!$fpGateway->pingback()->validate($validationParams)) {
            return false;
        }

        if ($this->isPaymentEvent() && !$this->validatePaymentData()) {
            return false;
        }

        if ($this->isRefundEvent() && !$this->validateRefundData()) {
            return false;
        }

        return true;
    }

    public function processDeliverable($invoiceId, $gateway)
    {
        $paymentOrder = $this->request['payment_order'];

        $invoice = mysql_fetch_assoc(select_query('tblinvoices', '*', ['id' => $invoiceId]));
        $hosting = [];

        $hostIdArray = $this->helper->getHostIdFromInvoice($invoiceId);
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
                $this->updateSubscriptionId($this->request['subscription']['id'], ['id' => $hosting['id']]);
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

    public function processRefundPingback($invoiceId, $gateway)
    {
        $paymentOrder = $this->request['payment_order'];
        $fpTransId = $paymentOrder['id'];
        $amount = $paymentOrder['refund_amount'];
        $sendToGateway = true;
        $addAsCredit = "";
        $sendEmail = true;
        $refundTransId = "";
        $reverse = false;

        try {
            $transaction = WHMCS\Billing\Payment\Transaction::where(array(
                'transid' => $fpTransId,
                'invoiceid' => $invoiceId
            ))->first();

            if (empty($transaction)) {
                throw new \Exception('Invalid transaction');
            }

            $refundedAmount = $this->getInvoiceRefundedAmount($invoiceId);
            $pingbackRefundedAmount = $paymentOrder['refunded_amount'];

            // if pingback refunded amount <= invoice refunded amount -> transaction processing/processed -> return ok
            if (!($pingbackRefundedAmount > $refundedAmount
                || ($pingbackRefundedAmount == $refundedAmount && $pingbackRefundedAmount == 0))) {
                return;
            }

            $transId = $transaction->id;
            $result = refundInvoicePayment($transId, $amount, $sendToGateway, $addAsCredit, $sendEmail, $refundTransId, $reverse);

            if ($result != 'success') {
                throw new \Exception($result);
            }
        } catch (\Exception $e) {
            exit($e->getMessage());
        }
    }

    public function getInvoiceRefundedAmount($invoiceId) {
        $result = select_query("tblaccounts", "SUM(amountout)", array( "invoiceid" => $invoiceId ));
        $data = mysql_fetch_array($result);
        $refundedAmount = $data[0];
        return round($refundedAmount, 2);
    }

    public function validateInvoiceId($invoiceId) {
        if(empty($invoiceId)) {
            exit("Invoice is not found");
        } elseif (!is_numeric($invoiceId)) {
            exit($invoiceId);
        }
    }

    public function getInvoiceIdPingback($isRefundEvent = false)  {
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

            if ($data['status'] == 'Paid' && !$isRefundEvent) {
                return 'Invoice is already paid';
            } elseif ($data['status'] != 'Paid' && $isRefundEvent) {
                return 'Invoice is unpaid';
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

    /**
     * @param $subscriptionId
     * @param $conditions
     */
    function updateSubscriptionId($subscriptionId, $conditions)
    {
        update_query('tblhosting', ['subscriptionid' => $subscriptionId], $conditions);
    }

    private function isPaymentEvent()
    {
        return $this->request['event'] == self::PINGBACK_PAYMENT_EVENT;
    }

    private function isRefundEvent()
    {
        return in_aray($this->request['event'], self::PINGBACK_REFUND_EVENTS);
    }

    private function validateRefundData()
    {
        if (!isset($this->request['payment_order']['id'])) {
            return false;
        }

        if (!isset($this->request['payment_order']['merchant_order_id'])) {
            return false;
        }

        if (!isset($this->request['payment_order']['refunded_amount'])) {
            return false;
        }

        if (!isset($this->request['payment_order']['refund_amount'])) {
            return false;
        }

        return true;
    }

    private function validatePaymentData()
    {
        if (!isset($this->request['payment_order']['id'])) {
            return false;
        }

        if (isset($this->request['subscription']) && !isset($this->request['subscription']['id'])) {
            return false;
        }

        return true;
    }
}

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


