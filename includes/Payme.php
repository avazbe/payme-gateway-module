<?php
/**
 * Payme Callback File
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
use WHMCS\Database\Capsule;

require_once __DIR__ . '/gatewayfunctions.php';
require_once __DIR__ . '/invoicefunctions.php';

class Payme
{
    const NAME = 'Payme';
    const VER = '1.0';
    const ACCOUNT_FIELD = 'order_id';


    private $connection;
    protected $modname = 'PayMe Hostbill';
    protected $description = 'PayMe Payment Gateway Module.';
    protected $filename = 'class.payme.php';
    protected $supportedCurrencies = array('UZB');
    protected $configuration = [];

    public function __construct($configuration)
    {
        $this->configuration = $configuration;
        $this->connection = $this->connect_to_db();
    }

    public function __destruct()
    {
        mysqli_close($this->connection);
    }

    public function callback()
    {
        $payload = (array)json_decode(file_get_contents('php://input'), true);
        if (!function_exists('getallheaders')) {
            function getallheaders()
            {
                $headers = '';
                foreach ($_SERVER as $name => $value) {
                    if (substr($name, 0, 5) == 'HTTP_') {
                        $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
                    }
                }
                return $headers;
            }
        }
        $headers = getallheaders();
        $encoded_credentials = base64_encode("Paycom:{$this->configuration['secretKey']}");
        /*logActivity(json_encode([
            'headers' =>  $headers,
            'payload' => $payload,
            'encred2' => $encoded_credentials,
            'configuration' => $this->configuration
        ]), 0);*/
        if (!$headers || // there is no headers
            !isset($headers['Authorization']) || // there is no Authorization
            !preg_match('/^\s*Basic\s+(\S+)\s*$/i', $headers['Authorization'], $matches) || // invalid Authorization value
            $matches[1] != $encoded_credentials // invalid credentials
        ) {
            $this->respond($this->error_authorization($payload));
        }

        /*$this->respond($this->error_authorization([
            'method_exists' => method_exists($this, $payload['method']),
            'payload' => $payload,
            'matches' => $matches[1],
            'encred2' => $encoded_credentials,
            'configuration' => $this->configuration
        ])); //*/

        $response = method_exists($this, $payload['method'])
            ? $this->{$payload['method']}($payload)
            : $this->error_unknown_method($payload);

        $this->respond($response);
    }

    private function respond($response)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }
        echo json_encode($response);
        die();
    }

    // checked
    private function CheckPerformTransaction($payload)
    {
        $invoice_data = $this->get_order2($payload);
        //$order_data = $this->get_order3($payload);
        $amount = $this->amount_to_coin($invoice_data['total']);

        if ($amount != $payload['params']['amount']) {
            $response = $this->error_amount($payload);
        } else {
            $response = [
                'id' => $payload['id'],
                'result' => [
                    'allow' => true
                ],
                'error' => null
            ];
        }

        return $response;

    }

    // checked
    private function CreateTransaction($payload)
    {
        $invoice_data = $this->get_order2($payload);
        $amount = $this->amount_to_coin($invoice_data['total']);
        $payme_order_id = $payload['params']['account'][self::ACCOUNT_FIELD];
        $payme_transaction_id = $payload['params']['id'];
        $payme_create_time = $payload['params']['time'];
        $result = $this->connection->query("SELECT * FROM `payme_data` WHERE `order_id`=$payme_order_id");
        //$error = $this->connection->error_list;
        $num_rows = $result->num_rows;
        $row = $result->fetch_assoc();
        $result->close();
        /*$this->respond($this->error_authorization([
            'amount' => $amount,
            'invoice' => $invoice_data,
            'payme_order_id' => $payme_order_id,
            'payme_transaction_id' => $payme_transaction_id,
            'payme_create_time' => $payme_create_time,
            'row' => $row,
            'error' =>  $error,
            'num_rows' =>  $num_rows,
            'payload' => $payload
        ]));*/
        $state = $num_rows > 0 ? $row['state'] : 0;
        $transaction_id = $num_rows > 0 ? $row['payme_transaction_id'] : 0;
        if ($amount != $payload['params']['amount']) {
            $response = $this->error_amount($payload);
        } else {
            if ($state == 0) {
                $this->connection->query("INSERT INTO `payme_data`(`order_id`, `amount`, `payme_transaction_id`, `state`, `payme_create_time`, `payme_perform_time`, `payme_cancel_time`) VALUES ('$payme_order_id', '$amount', '$payme_transaction_id', '1', '$payme_create_time', '0', '0')");
                $response = [
                    "id" => $payme_order_id,
                    "result" => [
                        "create_time" => $payme_create_time,
                        "transaction" => $payme_transaction_id,
                        "state" => 1
                    ]
                ];
            } else if ($state == 1 && $transaction_id == $payme_transaction_id) {
                $response = [
                    "id" => $payme_order_id,
                    "result" => [
                        "create_time" => $payme_create_time,
                        "transaction" => $payme_transaction_id,
                        "state" => 1
                    ]
                ];
            } else if ($state == 1 && $transaction_id != $payme_transaction_id) {
                $response = $this->error_has_another_transaction($payload);
            } else {
                $response = $this->error_unknown($payload);
            }
        }
        return $response;
    }

    // checked
    private function PerformTransaction($payload)
    {
        $perform_time = $this->current_timestamp();
        $payme_transaction_id = $payload['params']['id'];
        $result = $this->connection->query("SELECT * FROM `payme_data` WHERE `payme_transaction_id`='$payme_transaction_id'");
        $row = $result->fetch_assoc();
        $result->close();
        if ($row['state'] == '1') { // handle new Perform request
            // Save perform time
            $this->connection->query("UPDATE `payme_data` SET `payme_perform_time`='$perform_time', `state`='2' WHERE `payme_transaction_id`='$payme_transaction_id'");
            $response = [
                "id" => $payload['id'],
                "result" => [
                    "transaction" => $row['payme_transaction_id'],
                    "perform_time" => (double)$perform_time,
                    "state" => 2
                ]
            ];
            $invoice_num = $payme_transaction_id;
            //$payment = new ApiWrapper();
            //$this->logActivity(['output' => $invoice_num, 'result' => PaymentModule::PAYMENT_SUCCESS]);
            logTransaction($this->configuration['name'], $_POST, 'Success');
            $amount = $row['amount'];

            /*$params = [
                'id' => $row['order_id'],
                'amount' => (number_format($amount, 0, '.', '')) / 100,
                'paymentmodule' => 37,
                'fee' => 0,
                'date' => date("Y-n-d H:i:s"),
                'transnumber' => $payme_transaction_id
            ];*/

            addInvoicePayment(
                $row['order_id'], //$invoiceId,
                $payme_transaction_id, //$transactionId,
                (number_format($amount, 0, '.', '')) / 100, //$paymentAmount,
                0, //$paymentFee,
                $this->configuration['paymentmethod'] //$gatewayModuleName
            );

            //$payment->addInvoicePayment($params);

        } elseif ($row['state'] == '2' && $payme_transaction_id == $row['payme_transaction_id']) {
            $response = [
                "id" => $payload['id'],
                "result" => [
                    "transaction" => $row['payme_transaction_id'],
                    "perform_time" => (double)$row['payme_perform_time'],
                    "state" => 2
                ]
            ];
        }
        return $response;
    }

    // checked
    private function CheckTransaction($payload)
    {
        $payme_transaction_id = $payload['params']['id'];
        $result = $this->connection->query("SELECT * FROM `payme_data` WHERE `payme_transaction_id`='$payme_transaction_id'");
        $row = $result->fetch_assoc();
        $result->close();
        $response = [
            "id" => $payload['id'],
            "result" => [
                "create_time" => (double)$row['payme_create_time'],
                "perform_time" => 0,
                "cancel_time" => 0,
                "transaction" => $payme_transaction_id,
                "state" => null,
                "reason" => null
            ],
            "error" => null
        ];
        if ($payme_transaction_id == $row['payme_transaction_id']) {
            switch ($row['state']) {
                case '1':
                    $response['result']['state'] = 1;
                    break;

                case '2':
                    $response['result']['state'] = 2;
                    $response['result']['perform_time'] = (double)$row['payme_perform_time'];
                    break;

                case '3':
                    $response['result']['state'] = -1;
                    $response['result']['reason'] = 3;
                    $response['result']['cancel_time'] = (double)$row['payme_cancel_time'];
                    break;

                case '4':
                    $response['result']['state'] = -2;
                    $response['result']['reason'] = 5;
                    $response['result']['perform_time'] = (double)$row['payme_perform_time'];
                    $response['result']['cancel_time'] = (double)$row['payme_cancel_time'];
                    break;

                default:
                    $response = $this->error_transaction($payload);
                    break;
            }
        } else {
            $response = $this->error_transaction($payload);
        }

        return $response;

    }

    private function CancelTransaction($payload)
    {
        $payme_transaction_id = $payload['params']['id'];
        $result = $this->connection->query("SELECT * FROM `payme_data` WHERE `payme_transaction_id`='$payme_transaction_id'");
        $row = $result->fetch_assoc();
        $result->close();
        if ($payme_transaction_id == $row['payme_transaction_id']) {
            $cancel_time = $this->current_timestamp();
            $response = [
                "id" => $payload['id'],
                "result" => [
                    "transaction" => $row['payme_transaction_id'],
                    "cancel_time" => $cancel_time,
                    "state" => null
                ]
            ];
            switch ($row['state']) {
                case '1':
                    $this->connection->query("UPDATE `payme_data` SET `payme_cancel_time`='$cancel_time', `state`='3' WHERE `payme_transaction_id`='$payme_transaction_id'"); // Save cancel time
                    $response['result']['state'] = -1;
                    break;

                case '2':
                    $this->connection->query("UPDATE `payme_data` SET `payme_cancel_time`='$cancel_time', `state`='4' WHERE `payme_transaction_id`='$payme_transaction_id'"); // Save cancel time
                    $response['result']['state'] = -2;
                    break;

                case '3':
                    $response['result']['cancel_time'] = (double)$row['payme_cancel_time'];
                    $response['result']['state'] = -1;
                    break;

                case '4':
                    $response['result']['cancel_time'] = (double)$row['payme_cancel_time'];
                    $response['result']['state'] = -2;
                    break;

                default:
                    $response = $this->error_cancel($payload);
                    break;
            }
        } else {
            $response = $this->error_transaction($payload);
        }
        //$response['result']['reason'] = $payload['params']['reason'];
        return $response;
    }

    private function ChangePassword($payload)
    {
        if ($payload['params']['password'] != $this->configuration['secret_key']['value']) {
            $pass = $this->configuration['secret_key']['value'];

            if (!$pass) { // No options found
                return $this->error_password($payload);
            }

            // Save new password
            $this->configuration['secret_key']['value'] = $payload['params']['password'];
            return [
                "id" => $payload['id'],
                "result" => ["success" => true],
                "error" => null
            ];
        }

        // Same password or something wrong
        return $this->error_password($payload);
    }

    private function amount_to_coin($amount)
    {
        return 100 * number_format($amount, 0, '.', '');
    }

    private function error_authorization($payload)
    {
        $response = [
            "error" =>
                [
                    "code" => -32504,
                    "message" => [
                        "ru" => 'Error during authorization',
                        "uz" => 'Error during authorization',
                        "en" => 'Error during authorization'
                    ],
                    "data" => null
                ],
            "result" => null,
            //"id" => $payload['id']
            "id" => $payload
        ];

        return $response;
    }

    private function error_unknown_method($payload)
    {
        $response = [
            "error" => [
                "code" => -32601,
                "message" => [
                    "ru" => 'Unknown method',
                    "uz" => 'Unknown method',
                    "en" => 'Unknown method'
                ],
                "data" => $payload['method']
            ],
            "result" => null,
            "id" => $payload['id']
        ];

        return $response;
    }

    private function error_amount($payload)
    {
        $response = [
            "error" => [
                "code" => -31001,
                "message" => [
                    "ru" => 'Order amount is incorrect',
                    "uz" => 'Order amount is incorrect',
                    "en" => 'Order amount is incorrect'
                ],
                "data" => "amount"
            ],
            "result" => null,
            "id" => $payload['id']
        ];

        return $response;
    }

    private function current_timestamp()
    {
        return round(microtime(true) * 1000);
    }

    //connect to independent db for saving paycom service data
    private function connect_to_db()
    {
        $mysqli = new mysqli($this->configuration['db_host'], $this->configuration['db_user'], $this->configuration['db_pass'], $this->configuration['db_name']);
        $mysqli->query("SET NAMES 'utf-8'");

        return $mysqli;
    }


    private function get_order($payload)
    {
        //$invoiceId = checkCbInvoiceID($payload['params']['account']['order_id'], $this->configuration['name']);
        $invoice_api = new ApiWrapper();
        $invoice_data = $invoice_api->getInvoiceDetails(['id' => $payload['params']['account']['order_id']]);
        if ($invoice_data['success'] == false || $invoice_data['invoice']['status'] == 'Paid') {
            $this->respond($this->error_order_id($payload));
        } else {
            return $invoice_data;
        }
    }

    private function get_order2($payload){
        $invoice_data = localAPI('GetInvoice', ['invoiceid' => $payload['params']['account'][self::ACCOUNT_FIELD]], $this->configuration['api_user']);
        if ($invoice_data['status'] == 'error' || $invoice_data['status'] == 'Paid') {
            $this->respond($this->error_order_id($payload));
        } else {
            return $invoice_data;
        }

    }

    private function get_order3($payload){
        $orders = Capsule::table('tblorders')->where('invoiceid', $payload['params']['account'][self::ACCOUNT_FIELD])->get();
        $order = null;
        if(!empty($orders)) {
            $order = localAPI('GetOrders', ['id' => $orders[0]->id], $this->configuration['api_user']);
        }
        return $order;
    }//*/

    private function error_order_id($payload)
    {
        $response = [
            "error" => [
                "code" => -31099,
                "message" => [
                    "ru" => 'Order number cannot be found or already paid',
                    "uz" => 'Order number cannot be found or already paid',
                    "en" => 'Order number cannot be found or already paid'
                ],
                "data" => "order"
            ],
            "result" => null,
            "id" => $payload['id']
        ];

        return $response;
    }

    private function error_unknown($payload)
    {
        $response = [
            "error" => [
                "code" => -31008,
                "message" => [
                    "ru" => 'Unknown error',
                    "uz" => 'Unknown error',
                    "en" => 'Unknown error'
                ],
                "data" => null
            ],
            "result" => null,
            "id" => $payload['id']
        ];

        return $response;
    }

    private function error_has_another_transaction($payload)
    {
        $response = [
            "error" => [
                "code" => -31099,
                "message" => [
                    "ru" => 'Other transaction for this order is in progress',
                    "uz" => 'Other transaction for this order is in progress',
                    "en" => 'Other transaction for this order is in progress'
                ],
                "data" => "order"
            ],
            "result" => null,
            "id" => $payload['id']
        ];

        return $response;
    }

    private function error_transaction($payload)
    {
        $response = [
            "error" => [
                "code" => -31003,
                "message" => [
                    "ru" => 'Transaction number is wrong',
                    "uz" => 'Transaction number is wrong',
                    "en" => 'Transaction number is wrong'
                ],
                "data" => "id"
            ],
            "result" => null,
            "id" => $payload['id']
        ];

        return $response;
    }

    private function error_cancel($payload)
    {
        $response = [
            "error" => [
                "code" => -31007,
                "message" => [
                    "ru" => 'It is impossible to cancel. The order is completed',
                    "uz" => 'It is impossible to cancel. The order is completed',
                    "en" => 'It is impossible to cancel. The order is completed'
                ],
                "data" => "order"
            ],
            "result" => null,
            "id" => $payload['id']
        ];

        return $response;
    }

    private function error_order_already_paid($payload)
    {
        $response = [
            "error" => [
                "code" => -31099,
                "message" => [
                    "ru" => 'Order already in progress',
                    "uz" => 'Order already in progress',
                    "en" => 'Order already in progress'
                ],
                "data" => "order"
            ],
            "result" => null,
            "id" => $payload['id']
        ];

        return $response;
    }

    private function error_password($payload)
    {
        $response = [
            "error" => [
                "code" => -32400,
                "message" => [
                    "ru" => 'Cannot change the password',
                    "uz" => 'Cannot change the password',
                    "en" => 'Cannot change the password'
                ],
                "data" => "password"
            ],
            "result" => null,
            "id" => $payload['id']
        ];

        return $response;
    }
}
