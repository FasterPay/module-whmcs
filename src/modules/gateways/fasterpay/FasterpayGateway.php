<?php
require_once(ROOTDIR . '/modules/gateways/fasterpay/helpers/FasterpayHelper.php');

class Fasterpay_Gateway {

    var $helper = null;

    const GATEWAY_NAME = 'fasterpay';
    const MODULE_SOURCE = 'whmcs';

    public function __construct() {
        $this->helper = new Fasterpay_Helper();
    }

    public function prepareData($params) {

        $recurring = $this->helper->getRecurringBillingValuesFromInvoice($params['invoiceid']);

        if ($recurring) {
            $parameters = $this->prepareSubscriptionData($params, $recurring);
        } else {
            $parameters = $this->prepareOnetimeData($params);
        }

        return $parameters;
    }

    public function prepareOnetimeData($params) {

        $parameters = array(
            'amount' => $params['amount'],
            'currency' => $params['currency'],
            'api_key' => $params['appKey'],
            'merchant_order_id' => $this->getMerchantOrderId($params),
            'description' => $params['description'],
            'success_url' => !empty($params['success_url']) ? $params['success_url'] : $params['systemurl'] . '/cart.php?a=complete',
            'module_source' => self::MODULE_SOURCE,
            'sign_version' => $params['signVersion']
        );

        return $parameters;
    }

    public function prepareSubscriptionData($params, $recurring) {
        $merchant_order_id = $this->getMerchantOrderId($params);

        $parameters = array(
            'amount' => $recurring['recurringamount'],
            'currency' => $params['currency'],
            'api_key' => $params['appKey'],
            'merchant_order_id' => $merchant_order_id,
            'description' => $params['description'],
            'success_url' => !empty($params['success_url']) ? $params['success_url'] : $params['systemurl'] . '/cart.php?a=complete',
            'module_source' => self::MODULE_SOURCE,
            'sign_version' => $params['signVersion']
        );

        $parameters['recurring_name'] = $params['description'];
        $parameters['recurring_sku_id'] = $merchant_order_id;
        if ($this->helper->getPeriodType($recurring['recurringcycleunits']) == Fasterpay_Helper::PERIOD_TYPE_YEAR) {
            $parameters['recurring_period'] = (12 * $recurring['recurringcycleperiod']) . Fasterpay_Helper::PERIOD_TYPE_MONTH;
        } else {
            $parameters['recurring_period'] = $recurring['recurringcycleperiod'] . $this->helper->getPeriodType($recurring['recurringcycleunits']);
        }

        if (isset($recurring['firstpaymentamount'])) {
            $parameters['recurring_trial_amount'] = $recurring['firstpaymentamount'];
            if ($this->helper->getPeriodType($recurring['firstcycleunits']) == Fasterpay_Helper::PERIOD_TYPE_YEAR) {
                $parameters['recurring_trial_period'] = (12 * $recurring['firstcycleperiod']) . Fasterpay_Helper::PERIOD_TYPE_MONTH;
            } else {
                $parameters['recurring_trial_period'] = $recurring['firstcycleperiod'] . $this->helper->getPeriodType($recurring['firstcycleunits']);
            }
        }

        return $parameters;
    }

    public function getMerchantOrderId($params)
    {
        $fasterpayHelper = new FasterPay_Helper();
        $hostIdArray = $fasterpayHelper->getHostIdFromInvoice($params['invoiceid']);

        return $hostIdArray['id'].":".$params['invoiceid'].":".$hostIdArray['type'].":".$params['clientdetails']['userid'];
    }
}