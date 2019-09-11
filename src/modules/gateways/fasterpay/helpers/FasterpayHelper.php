<?php
if (!defined("WHMCS")) {
    exit("This file cannot be accessed directly");
}

use Illuminate\Database\Capsule\Manager as Capsule;

class Fasterpay_Helper {

    const WHMCS_ITEM_TYPE_HOSTING   = 'Hosting';
    const WHMCS_ITEM_TYPE_CREDIT    = 'AddFunds';
    const WHMCS_ITEM_TYPE_DOMAIN    = 'DomainRegister';
    const WHMCS_ITEM_TYPE_INVOICE   = 'Invoice';
    const WHMCS_ITEM_TYPE_ADDON     = 'Addon';
    const PERIOD_TYPE_YEAR          = 'y';
    const PERIOD_TYPE_MONTH         = 'm'; 
    const PERIOD_TYPE_DAY           = 'd';

    function getHostIdFromInvoice($invoiceId) {
        $query = "
            SELECT * 
            FROM tblinvoiceitems
            WHERE invoiceid = {$invoiceId} 
            AND (((type = '".self::WHMCS_ITEM_TYPE_HOSTING."' OR type ='".self::WHMCS_ITEM_TYPE_DOMAIN."' OR type = '".self::WHMCS_ITEM_TYPE_INVOICE."' OR type = '".self::WHMCS_ITEM_TYPE_ADDON."') AND relid != 0) 
            OR ((type ='".self::WHMCS_ITEM_TYPE_CREDIT."' OR type = '') AND relid = 0)) 
        ";
        
        $result = full_query($query);
        $item = mysql_fetch_assoc($result);
        if(!empty($item)) {
            if ($item['type'] == self::WHMCS_ITEM_TYPE_CREDIT || $item['type'] == '')
                return array(
                    'id' => $invoiceId,
                    'type' => $item['type']
                );
            else
                return array(
                    'id' => $item['relid'],
                    'type' => $item['type']
                );
        } else {
            return null;
        }
    }

    function getPeriodType($recurringCycleUnits) {
        if (empty($recurringCycleUnits)) {
            return null;
        }
        $cycleUnits = strtoupper(substr($recurringCycleUnits, 0, 1));
        return ($cycleUnits == 'Y') ? self::PERIOD_TYPE_YEAR : (($cycleUnits == 'M') ? self::PERIOD_TYPE_MONTH : self::PERIOD_TYPE_DAY);
    }

    function getRecurringBillingValuesFromInvoice($invoiceid) {
        global $CONFIG;
        $firstcycleperiod = $firstcycleunits = "";
        $invoiceid = (int)$invoiceid;
        $result = select_query("tblinvoiceitems", "count(tblinvoiceitems.relid) as count_recurring", array("invoiceid" => $invoiceid, "type" => "Hosting", "billingcycle" => array("sqltype" => "NEQ", "value" => "One Time")), "tblinvoiceitems`.`id", "ASC", "", "tblhosting ON tblhosting.id=tblinvoiceitems.relid");
        $count = mysql_fetch_array($result);
        if($count['count_recurring'] > 1)
            return false;
        $result = select_query("tblinvoiceitems", "tblinvoiceitems.relid,tblinvoiceitems.type,tblinvoiceitems.taxed,tblhosting.userid,tblhosting.amount,tblhosting.billingcycle,tblhosting.packageid,tblhosting.regdate,tblhosting.nextduedate", array("invoiceid" => $invoiceid, "type" => "Hosting"), "tblinvoiceitems`.`id", "ASC", "", "tblhosting ON tblhosting.id=tblinvoiceitems.relid");
        while($dat = mysql_fetch_array($result)) {
            if ($dat['billingcycle'] != 'One Time')
                $data = $dat;
        }
        $relid = $data['relid'];
        $relType = $data['type'];
        $taxed = $data['taxed'];
        $userid = $data['userid'];
        $recurringamount = $data['amount'];
        $billingcycle = $data['billingcycle'];
        $packageid = $data['packageid'];
        $regdate = $data['regdate'];
        $nextduedate = $data['nextduedate'];
        if ((!$relid || $billingcycle == "One Time") || $billingcycle == "Free Account") {
            return false;
        }
        $result = select_query("tblinvoices", "total,taxrate,taxrate2,paymentmethod,(SELECT SUM(amountin)-SUM(amountout) FROM tblaccounts WHERE invoiceid=tblinvoices.id) AS amountpaid", array("id" => $invoiceid));
        $data = mysql_fetch_array($result);
        $total = $data['total'];
        $taxrate = $data['taxrate'];
        $taxrate2 = $data['taxrate2'];
        $paymentmethod = $data['paymentmethod'];
        $amountpaid = $data['amountpaid'];
        $firstpaymentamount = $total - $amountpaid;
        $recurringcycleperiod = getBillingCycleMonths($billingcycle);
        $recurringcycleunits = "Months";
        if (12 <= $recurringcycleperiod) {
            $recurringcycleperiod = $recurringcycleperiod / 12;
            $recurringcycleunits = "Years";
        }
        $recurringamount = 0;
        $query = "SELECT tblhosting.amount,tblinvoiceitems.taxed FROM tblinvoiceitems INNER JOIN tblhosting ON tblhosting.id=tblinvoiceitems.relid WHERE tblinvoiceitems.invoiceid='" . (int)$invoiceid . "' AND tblinvoiceitems.type='Hosting' AND tblhosting.billingcycle='" . db_escape_string($billingcycle) . "'";
        $result = full_query($query);
        while ($data = mysql_fetch_array($result)) {
            $prodamount = $data[0];
            $taxed = $data[1];
            if ($CONFIG['TaxType'] == "Exclusive" && $taxed) {
                if ($CONFIG['TaxL2Compound']) {
                    $prodamount = $prodamount + $prodamount * ($taxrate / 100);
                    $prodamount = $prodamount + $prodamount * ($taxrate2 / 100);
                }
                else {
                    $prodamount = $prodamount + $prodamount * ($taxrate / 100) + $prodamount * ($taxrate2 / 100);
                }
            }
            $recurringamount += $prodamount;
        }
        $query = "SELECT tblhostingaddons.recurring,tblhostingaddons.tax FROM tblinvoiceitems INNER JOIN tblhostingaddons ON tblhostingaddons.id=tblinvoiceitems.relid WHERE tblinvoiceitems.invoiceid='" . (int)$invoiceid . "' AND tblinvoiceitems.type='Addon' AND tblhostingaddons.billingcycle='" . db_escape_string($billingcycle) . "'";
        $result = full_query($query);
        while ($data = mysql_fetch_array($result)) {
            $addonamount = $data[0];
            $addontax = $data[1];
            if ($CONFIG['TaxType'] == "Exclusive" && $addontax) {
                if ($CONFIG['TaxL2Compound']) {
                    $addonamount = $addonamount + $addonamount * ($taxrate / 100);
                    $addonamount = $addonamount + $addonamount * ($taxrate2 / 100);
                }
                else {
                    $addonamount = $addonamount + $addonamount * ($taxrate / 100) + $addonamount * ($taxrate2 / 100);
                }
            }
            $recurringamount += $addonamount;
        }
        if (in_array($billingcycle, array("Annually", "Biennially", "Triennially"))) {
            $cycleregperiods = array("Annually" => "1", "Biennially" => "2", "Triennially" => "3");
            $query = "SELECT SUM(tbldomains.recurringamount) FROM tblinvoiceitems INNER JOIN tbldomains ON tbldomains.id=tblinvoiceitems.relid WHERE tblinvoiceitems.invoiceid='" . (int)$invoiceid . "' AND tblinvoiceitems.type IN ('DomainRegister','DomainTransfer','Domain') AND tbldomains.registrationperiod='" . db_escape_string($cycleregperiods[$billingcycle]) . "'";
            $result = full_query($query);
            $data = mysql_fetch_array($result);
            $domainamount = $data[0];
            if ($CONFIG['TaxType'] == "Exclusive" && $CONFIG['TaxDomains']) {
                if ($CONFIG['TaxL2Compound']) {
                    $domainamount = $domainamount + $domainamount * ($taxrate / 100);
                    $domainamount = $domainamount + $domainamount * ($taxrate2 / 100);
                }
                else {
                    $domainamount = $domainamount + $domainamount * ($taxrate / 100) + $domainamount * ($taxrate2 / 100);
                }
            }
            $recurringamount += $domainamount;
        }
        $result = select_query("tblinvoices", "duedate", array("id" => $invoiceid));
        $data = mysql_fetch_array($result);
        $invoiceduedate = $data['duedate'];
        $invoiceduedate = str_replace("-", "", $invoiceduedate);
        $overdue = ($invoiceduedate < date("Ymd") ? true : false);
        $result = select_query("tblproducts", "proratabilling,proratadate,proratachargenextmonth", array("id" => $packageid));
        $data = mysql_fetch_array($result);
        $proratabilling = $data['proratabilling'];
        $proratadate = $data['proratadate'];
        $proratachargenextmonth = $data['proratachargenextmonth'];
        if ($regdate == $nextduedate && $proratabilling) {
            $orderyear = substr($regdate, 0, 4);
            $ordermonth = substr($regdate, 5, 2);
            $orderday = substr($regdate, 8, 2);
            if (!function_exists("getProrataValues")) {
                require ROOTDIR . "/includes/invoicefunctions.php";
            }
            $proratavals = getProrataValues($billingcycle, 0, $proratadate, $proratachargenextmonth, $orderday, $ordermonth, $orderyear);
            $firstcycleperiod = $proratavals['days'];
            $firstcycleunits = "Days";
        }
        if (!$firstcycleperiod) {
            $firstcycleperiod = $recurringcycleperiod;
        }
        if (!$firstcycleunits) {
            $firstcycleunits = $recurringcycleunits;
        }
        $result = select_query("tblpaymentgateways", "value", array("gateway" => $paymentmethod, "setting" => "convertto"));
        $data = mysql_fetch_array($result);
        $convertto = $data[0];
        if ($convertto) {
            $currency = getCurrency($userid);
            $firstpaymentamount = convertCurrency($firstpaymentamount, $currency['id'], $convertto);
            $recurringamount = convertCurrency($recurringamount, $currency['id'], $convertto);
        }
        $firstpaymentamount = format_as_currency($firstpaymentamount);
        $recurringamount = format_as_currency($recurringamount);
        $returndata = array();
        $returndata['primaryserviceid'] = $relid;
        if ($firstpaymentamount != $recurringamount) {
            $returndata['firstpaymentamount'] = $firstpaymentamount;
            $returndata['firstcycleperiod'] = $firstcycleperiod;
            $returndata['firstcycleunits'] = $firstcycleunits;
        }
        $returndata['recurringamount'] = $recurringamount;
        $returndata['recurringcycleperiod'] = $recurringcycleperiod;
        $returndata['recurringcycleunits'] = $recurringcycleunits;
        $returndata['overdue'] = $overdue;
        $returndata['primaryservicetype'] = $relType;
        return $returndata;
    }

    public function referenceIdExisted($referenceId, $dump = false)
    {
        return (bool)(mysql_fetch_array(select_query('fp_refund_transactions', 'count(*)', ["refund_reference_id" => $referenceId]))[0]);
    }

    public function logReferenceId($referenceId, $txnId)
    {
        insert_query("fp_refund_transactions", [
            "refund_reference_id" => $referenceId,
            "transaction_id" => $txnId
        ]);
    }
}