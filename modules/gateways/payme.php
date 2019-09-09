<?php
/**
 * Payme Gateway Module
 *
 * This sample file demonstrates how a merchant gateway module supporting
 * 3D Secure Authentication, Captures and Refunds can be structured.
 *
 * If your merchant gateway does not support 3D Secure Authentication, you can
 * simply omit that function and the callback file from your own module.
 *
 * Within the module itself, all functions must be prefixed with the module
 * filename, followed by an underscore, and then the function name. For this
 * example file, the filename is "merchantgateway" and therefore all functions
 * begin "payme_".
 *
 * For more information, please refer to the online documentation.
 *
 * @see https://developers.whmcs.com/payment-gateways/
 * @see https://help.paycom.uz/
 *
 * @copyright Virtual Clouds LlC (c) Reserved 2019
 * @author Avazbek Niyazov
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Define module related meta data.
 *
 * Values returned here are used to determine module related capabilities and
 * settings.
 *
 * @see https://developers.whmcs.com/payment-gateways/meta-data-params/
 *
 * @return array
 */
function payme_MetaData()
{
    return array(
        'DisplayName' => 'Payme.uz Merchant Gateway Module',
        'APIVersion' => '1.1', // Use API Version 1.1
    );
}

/**
 * Define gateway configuration options.
 *
 * The fields you define here determine the configuration options that are
 * presented to administrator users when activating and configuring your
 * payment gateway module for use.
 *
 * Supported field types include:
 * * text
 * * password
 * * yesno
 * * dropdown
 * * radio
 * * textarea
 *
 * Examples of each field type and their possible configuration parameters are
 * provided in the sample function below.
 *
 * @see https://developers.whmcs.com/payment-gateways/configuration/
 *
 * @return array
 */
function payme_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Payme Merchant Gateway Module',
        ),
        'merchant_id' => array(
            'FriendlyName' => 'Merchant ID',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Enter your merchant ID here (40 characters)',
        ),
        'secretKey' => array(
            'FriendlyName' => 'Secret Key',
            'Type' => 'password',
            'Size' => '130',
            'Description' => 'Enter secret key here',
        ),
        'mode' => array(
            'FriendlyName' => 'Test Mode',
            'Type' => 'yesno',
            'Description' => 'Tick to enable test mode',
        ),
        'success_message' => array(
            'FriendlyName' => 'Success Message',
            'Type' => 'text',
            'Size' => '100',
            'Default' => 'Thank you! Transaction was successful! We have received your payment.',
            'Description' => 'Enter success message',
        ),
        'failure_message' => array(
            'FriendlyName' => 'Failure Message',
            'Type' => 'text',
            'Size' => '100',
            'Default' => 'Transaction Failed!',
            'Description' => 'Enter failure message',
        ),
        'db_host' => array(
            'FriendlyName' => 'DataBase Hostname',
            'Type' => 'text',
            'Size' => '25',
            'Default' => 'localhost',
            'Description' => 'Enter DataBase Hostname',
        ),
        'db_name' => array(
            'FriendlyName' => 'DataBase Name',
            'Type' => 'text',
            'Size' => '25',
            'Default' => 'payme',
            'Description' => 'Enter DataBase Name',
        ),
        'db_user' => array(
            'FriendlyName' => 'DataBase Username',
            'Type' => 'text',
            'Size' => '25',
            'Default' => 'payme_root',
            'Description' => 'Enter DataBase Username',
        ),
        'db_pass' => array(
            'FriendlyName' => 'DataBase Password',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Enter DataBase Password',
        ),
        'api_user' => array(
            'FriendlyName' => 'Username for local API',
            'Type' => 'text',
            'Size' => '25',
            'Default' => 'avazbek',
            'Description' => 'Enter username for using the local API',
        ),
    );
}

/**
 * Payment link.
 *
 * Required by third party payment gateway modules only.
 *
 * Defines the HTML output displayed on an invoice. Typically consists of an
 * HTML form that will take the user to the payment gateway endpoint.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see https://developers.whmcs.com/payment-gateways/third-party-gateway/
 *
 * @return string
 */
function payme_link($params)
{
    $testMode = $params['mode'];
    $invoiceId = $params['invoiceid'];
    $amount = $params['amount'] * 100;
    $systemUrl = $params['systemurl'];
    $gatewayAccountId = $params['merchant_id']; // Your Merchant ID
    $lang = $params['langpaynow'];
    if ($testMode == 'on') {
        $action = "https://test.paycom.uz";
    } else {
        $action = "https://checkout.paycom.uz";
    }
    $description = 'Payment for Order ' . $invoiceId . ' ' . $params["description"];
    $callback = $systemUrl . 'modules/gateways/callback/payme.php';

    $code = '<form method="post" action="' . $action . '" name="payme" id="payme">
    <input type="hidden" name="account[order_id]" value="' . $invoiceId . '" />
    <input type="hidden" name="amount" value="' . $amount . '" />
    <input type="hidden" name="merchant" value="' . $gatewayAccountId . '" />    
    <input type="hidden" name="description" value="' . $description . '"> 
    <input type="hidden" name="currency" value="860"/>
    <input type="submit" value="Pay Now" class="btn btn-success" /> </form>';
    //   <input type="hidden" name="callback" value="' . $callback . '">
    //<input type="hidden" name="lang" value="' . $lang . '"/>

    $code .= "<script language=\"javascript\">
            setTimeout ( \"autoForward()\" , 500000 );
            function autoForward() {
                document.forms.payform.submit()
            }
            </script>
            ";

    return $code;
}
