<?php
require_once(ROOTDIR . '/modules/gateways/fasterpay/helpers/FasterpayHelper.php');

class Fasterpay_Gateway {

    var $helper = null;

    const GATEWAY_NAME = 'fasterpay';
    const MODULE_SOURCE = 'whmcs';
	const FP_API_PAYMENT_FORM_URL = 'https://pay.fasterpay.com/payment/form';

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

        $parameters['hash'] = $this->generateHash($parameters, $params['secretKey']);

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
            'module_source' => self::MODULE_SOURCE
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
            'module_source' => self::MODULE_SOURCE
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

    public function generateHash($parameters, $key) {
        ksort($parameters);
        return hash('sha256', http_build_query($parameters) . $key);
    }

    public function getMerchantOrderId($params)
    {
        $fasterpayHelper = new FasterPay_Helper();
        $hostIdArray = $fasterpayHelper->getHostIdFromInvoice($params['invoiceid']);

        return $hostIdArray['id'].":".$params['invoiceid'].":".$hostIdArray['type'].":".$params['clientdetails']['userid'];
    }


    public function buildForm($parameters) {
        $form = '<form align="center" method="post" action="'.self::FP_API_PAYMENT_FORM_URL.'">';

        foreach ($parameters as $key =>$val) {
            $form .= '<input type="hidden" name="'.$key.'" value="'.$val.'" />';
        }

        $form .= '<input type="Submit" value="Pay Now"/></form>';

        return $form;

    }
}