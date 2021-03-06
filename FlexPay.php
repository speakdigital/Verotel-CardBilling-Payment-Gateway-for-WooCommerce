<?php

require_once __DIR__.'/src/Verotel/FlexPay/Client.php';

final class FlexPay {

    /**
     * Generates SHA1 signature
     * @param string $secret
     * @param array $params
     * @return string SHA1 encoded signature
     */
    public static function get_signature($secret, $params) {
        $client = static::_get_flexpay_object($secret, $params);
        return $client->get_signature($params);
    }

    /**
     * Validates signature
     * @param string $secret
     * @param array $params just params allowed with signature
     * @return bool
     */
    public static function validate_signature($secret, $params) {
        $client = static::_get_flexpay_object($secret, $params);
        return $client->validate_signature($params);
    }

    /**
     * @param string $secret
     * @param array $params
     * @return string Purchase URL
     */
    public static function get_purchase_URL($secret, $params) {
        $client = static::_get_flexpay_object($secret, $params);
        return $client->get_purchase_URL($params);
    }

    /**
     * @param string $secret
     * @param array $params
     * @return string subscription URL
     */
    public static function get_subscription_URL($secret, $params) {
        $client = static::_get_flexpay_object($secret, $params);
        return $client->get_subscription_URL($params);
    }

    /**
     * @param string $secret
     * @param array $params
     * @return string status URL
     */
    public static function get_status_URL($secret, $params) {
        $client = static::_get_flexpay_object($secret, $params);
        return $client->get_status_URL($params);
    }

    /**
     *
     * @param string $secret
     * @param array $params
     * @return string Upgrade Subscription URL
     */
    public static function get_upgrade_subscription_URL($secret, $params) {
        $client = static::_get_flexpay_object($secret, $params);
        return $client->get_upgrade_subscription_URL($params);
    }

    /**
     *
     * @param string $secret
     * @param array $params
     * @return string Cancel Subscription URL
     */
    public static function get_cancel_subscription_URL($secret, $params) {
        $client = static::_get_flexpay_object($secret, $params);
        return $client->get_cancel_subscription_URL($params);
    }


    private static function _get_flexpay_object($secret, $params) {
        $shopID = NULL;
        if (is_array($params) && isset($params['shopID'])) {
            $shopID = $params['shopID'];
        }
		
		$gateway = new WC_Gateway_CardBilling();
		$customer = $gateway->customer_id;
		if ($customer) {
			$brand = Verotel\FlexPay\Brand::create_from_merchant_id($customer);
		} else {
			$name = $gateway->which;
			if (!$name) $name= "Verotel";
			$brand = Verotel\FlexPay\Brand::create_from_name($name);
		}
		
        return new Verotel\FlexPay\Client($shopID, $secret, $brand);
    }
}
