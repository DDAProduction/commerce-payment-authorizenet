<?php


namespace Commerce\Payments;

use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use Commerce\Commerce;

class AuthorizePayment extends Payment
{

    private $codes = [
        'USD', 'CAD', 'GBP', 'DKK', 'NOK', 'PLN', 'SEK', 'EUR', 'AUD', 'NZD'
    ];


    /**
     * @var $commerce Commerce
     */
    private $commerce;
    /**
     * @var \Commerce\Processors\OrdersProcessor
     */
    private $orderProcessor;

    public function __construct($modx, array $params = [])
    {
        parent::__construct($modx, $params);

        $this->commerce = ci()->commerce;
        $this->orderProcessor = $this->commerce->loadProcessor();

        $this->lang = $this->commerce->getUserLanguage('authorize');


    }

    public function getPaymentLink()
    {


        $processor = $this->modx->commerce->loadProcessor();
        $order     = $processor->getOrder();
        $currency  = ci()->currency->getCurrency($order['currency']);

        if(!in_array($order['currency'],$this->codes) && empty($this->getSetting('convert_to'))){
            return  false;
        }

        $payment  = $this->createPayment($order['id'], $order['amount']);


        if(in_array($order['currency'],$this->codes)){
            $payment['meta']['pay_amount'] = $order['amount'];
            $payment['meta']['pay_currency'] = $order['currency'];
        }
        else{
            $payment['meta']['pay_amount'] = $this->commerce->currency->convert($order['amount'],$order['currency'],$this->getSetting('convert_to'));
            $payment['meta']['pay_currency'] = $this->getSetting('convert_to');
        }

        $this->orderProcessor->savePayment($payment);


        return $this->modx->makeUrl($this->getSetting('paymentPageId')) . '?' . http_build_query([
                'payment_hash' => $payment['hash'],
            ]);
    }


    public function charge($paymentHash, $request)
    {
        $payment = $this->getPayment();
        $this->validatePayment($payment);
        $this->validateRequest($request);

        $this->chargeMoney($payment,$request);

        try {
            $this->orderProcessor->processPayment($payment['id'],floatval($payment['amount']));
        }
        catch (\Exception $e){
            $this->log(3,'processPaymentError: '.print_r($e->getMessage(),true));
            throw new \Exception($this->lang['authorize.transaction_ok_other_problem']);
        }
    }

    private function getPayment()
    {
        /** @var \Commerce\Processors\OrdersProcessor $orderProcessor */
        $payment = $this->orderProcessor->loadPaymentByHash($_GET['payment_hash']);


        if (empty($payment)) {
            throw new \Exception($this->lang['authorize.payment_not_found']);
        }
        return $payment;
    }

    private function validateRequest($request)
    {
        if (empty($request['number'])) {
            throw new \Exception($this->lang['authorize.error_enter_credit_cart']);
        }
        if (empty($request['expiration'])) {
            throw new \Exception($this->lang['authorize.error_enter_expiration']);
        }
        if (empty($request['cvv'])) {
            throw new \Exception($this->lang['authorize.error_enter_cvv']);
        }


    }


    private function chargeMoney($payment, $request)
    {



        /* Create a merchantAuthenticationType object with authentication details
           retrieved from the constants file */
        $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $merchantAuthentication->setName($this->getSetting('api_login_id'));
        $merchantAuthentication->setTransactionKey($this->getSetting('transaction_key'));



        // Create the payment data for a credit card
        $creditCard = new AnetAPI\CreditCardType();
        $creditCard->setCardNumber(preg_replace('~[^0-9]~','',$request['number']));
        $creditCard->setExpirationDate(preg_replace('~[^0-9-]~','',$request['expiration']));
        $creditCard->setCardCode(intval($request['cvv']));


        // Add the payment data to a paymentType object
        $paymentOne = new AnetAPI\PaymentType();
        $paymentOne->setCreditCard($creditCard);

        // Create order information
        $order = new AnetAPI\OrderType();
        $order->setInvoiceNumber($payment['id']);


        // Create a TransactionRequestType object and add the previous objects to it
        $transactionRequestType = new AnetAPI\TransactionRequestType();
        $transactionRequestType->setTransactionType("authCaptureTransaction");
        $transactionRequestType->setAmount(floatval($payment['meta']['pay_amount']));
        $transactionRequestType->setCurrencyCode($payment['meta']['pay_currency']);
        $transactionRequestType->setOrder($order);
        $transactionRequestType->setPayment($paymentOne);


        // Assemble the complete transaction request
        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setRefId('ref' . $payment['id']);
        $request->setTransactionRequest($transactionRequestType);

        // Create the controller and get the response
        $controller = new AnetController\CreateTransactionController($request);
        $response = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::SANDBOX);


        if ($response === null) {
            $this->log(3, "No response returned");
            throw new \Exception($this->lang['authorize.transaction_error']);
        }


        if($response->getMessages()->getResultCode() !=="Ok"){

            $tresponse = $response->getTransactionResponse();

            if ($tresponse != null && $tresponse->getErrors() != null) {
                $errors = $tresponse->getErrors();
            } else {
                $errors = $response->getMessages()->getMessage()[0];
            }

            $this->log(3,'Errors: '.print_r($errors,true));
            throw new \Exception($this->lang['authorize.transaction_error']);
        }

        // Check to see if the API request was successfully received and acted upon
        // Since the API request was successful, look for a transaction response
        // and parse it to display the results of authorizing the card
        $tresponse = $response->getTransactionResponse();


        if($tresponse == null || $tresponse->getMessages() == null){

            $errors = [];
            if($tresponse->getErrors() != null){
                $errors = $tresponse->getErrors()[0];
            }
            $this->log(3,'Errors: '.print_r($errors,true));
            throw new \Exception($this->lang['authorize.transaction_error']);

        }

        $this->log(1,'Response success: '.print_r($tresponse,true));



    }

    private function log($code, $message)
    {
        if($this->getSetting('log_info_messages') == 0 && $code < 3){
            return true;
        }
        $this->modx->logEvent(738,$code,$message,'AuthorizeNet');
    }

    private function validatePayment($payment)
    {
        $tableStatuses = $this->modx->getFullTableName('commerce_order_statuses');

        $order_id = $payment['order_id'];
        $order = $this->orderProcessor->loadOrder($order_id);

        if (!empty($payment['paid'])) {
            throw new \Exception($this->lang['authorize.exception_payment_already_paid']);
        }

        $statusCanBePaid = $this->modx->db->getValue($this->modx->db->select('canbepaid', $tableStatuses, "`id` = '" . intval($order['status_id']) . "'"));

        if (empty($statusCanBePaid)) {
            throw new \Exception($this->lang['authorize.exception_payment_cannot_by_paid']);
        }


    }

    public function getRequestPaymentHash()
    {
        return isset($_GET['payment_hash'])?$_GET['payment_hash']:'';
    }

}