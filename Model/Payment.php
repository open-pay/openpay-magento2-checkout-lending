<?php

/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Openpay\CheckoutLending\Model;

// Class Heritage
use Magento\Framework\Exception\AbstractAggregateException;
use Magento\Payment\Model\Method\AbstractMethod;

// @createWebhook
use Magento\Framework\UrlInterface;
// @order
use Magento\Payment\Model\InfoInterface;
// getCustomerData
use Magento\Framework\Validator\Exception;

use Magento\Store\Model\ScopeInterface;
use Magento\Customer\Model\Customer;
use Magento\Framework\Exception\CouldNotSaveException;

use Openpay\Data\Openpay;

// Construct Imports
use Magento\Store\Model\StoreManagerInterface;
use Openpay\CheckoutLending\Model\OpenpayCustomerFactory;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Model\Context;

/**
 * Class Payment
 *
 * @method \Magento\Quote\Api\Data\PaymentMethodExtensionInterface getExtensionAttributes()
 */
class Payment extends AbstractMethod
{
    const CODE = 'openpay_checkoutLending';
    protected $_code = self::CODE;

    protected $_isGateway = true;
    protected $_canAuthorize = true;
    protected $_canOrder = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canRefund = false;
    protected $_canRefundInvoicePartial = false;
    protected $_isOffline = true;
    protected $_scopeConfig;
    protected $openpay = false;
    protected $is_sandbox;
    protected $country = "MX";
    protected $merchant_id = null;
    protected $sk = null;
    protected $sandbox_merchant_id;
    protected $sandbox_sk;
    protected $live_merchant_id;
    protected $live_sk;
    protected $supported_currency_codes = array('MXN');
    protected $_transportBuilder;
    protected $logger;
    protected $_storeManager;
    protected $_inlineTranslation;
    protected $_directoryList;
    protected $_file;
    protected $_agreementCollectionFactory;
    protected $_messageManager;


    public function __construct(
        Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\Translate\Inline\StateInterface $inlineTranslation,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger_interface,
        \Magento\Framework\App\Filesystem\DirectoryList $directoryList,
        \Magento\Framework\Filesystem\Io\File $file,
        Customer $customerModel,
        CustomerSession $customerSession,
        OpenpayCustomerFactory $openpayCustomerFactory,
        \Magento\CheckoutAgreements\Model\ResourceModel\Agreement\CollectionFactory $agreementCollectionFactory,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        array $data = []
) {
    parent::__construct(
        $context,
        $registry,
        $extensionFactory,
        $customAttributeFactory,
        $paymentData,
        $scopeConfig,
        $logger,
        null,
        null,
        $data
    );

    $this->customerModel = $customerModel;
    $this->customerSession = $customerSession;
    $this->openpayCustomerFactory = $openpayCustomerFactory;

    $this->_file = $file;
    $this->_directoryList = $directoryList;
    $this->logger = $logger_interface;
    $this->_inlineTranslation = $inlineTranslation;
    $this->_storeManager = $storeManager;
    $this->_scopeConfig = $scopeConfig;
    $this->_agreementCollectionFactory = $agreementCollectionFactory;
    $this->_messageManager = $messageManager;

    // LOAD MERCHANT DATA CONECTION
    $this->is_active = $this->_scopeConfig->getValue('payment/openpay_checkoutLending/active');
    $this->is_sandbox = $this->_scopeConfig->getValue('payment/openpay_checkoutLending/is_sandbox');
    $this->sandbox_merchant_id = $this->_scopeConfig->getValue('payment/openpay_checkoutLending/sandbox_merchant_id');
    $this->sandbox_sk = $this->_scopeConfig->getValue('payment/openpay_checkoutLending/sandbox_sk');
    $this->live_merchant_id = $this->_scopeConfig->getValue('payment/openpay_checkoutLending/live_merchant_id');
    $this->live_sk = $this->_scopeConfig->getValue('payment/openpay_checkoutLending/live_sk');
    $this->merchant_id = $this->is_sandbox ? $this->sandbox_merchant_id : $this->live_merchant_id;
    $this->sk = $this->is_sandbox ? $this->sandbox_sk : $this->live_sk;

    $this->logger->debug('## 0.0 CONTRUCTOR METHOD LOADED');
}


    /**
     *
     * @param InfoInterface $payment
     * @param float $amount
     * @return Payment
     * @throws Exception
     */
    public function order(InfoInterface $payment, $amount) {
        try {
            unset($_SESSION['kueski_redirect_url']);

            $this->setAdminTimezone();

            /** @var \Magento\Sales\Model\Order $order */
            $order = $payment->getOrder();
            /** @var \Magento\Sales\Model\Order\Address $billing */
            $billing = $order->getBillingAddress();
            /** @var \Magento\Sales\Model\Order\Address $shipping */
            $shipping = $order->getShippingAddress();

            $this->logger->debug('## 2.1. ORDER --' . json_encode($order->getData()));
            $this->logger->debug('## 2.2. BILLING ADDRESS --' . json_encode($billing->getData()));
            $this->logger->debug('## 2.3. SHIPPING ADDRESS --' . json_encode($shipping->getData()));

            $customer_data = $this->createCustomerData($order, $billing);
            $this->logger->debug('## 3. CREATE CUSTOMER DATA --' . json_encode($customer_data));
            $charge_data = $this->createChargeData($order, $billing, $shipping, $customer_data, $amount);
            $this->logger->debug('## 4. CREATE CHARGE DATA --' . json_encode($charge_data));

            $charge_response = $this->sendOpenpayChargeRequest($customer_data, $charge_data);

            $this->updateOrderData($payment, $charge_response, $order);

            $this->logger->debug("## 5. KUESKI CALLBACK URL -- ". $charge_response->payment_method->callbackUrl);
            $_SESSION['kueski_redirect_url'] = $charge_response->payment_method->callbackUrl;

            $payment->setSkipOrderProcessing(true);
        }catch (Exception $e) {
            $this->_logger->error(__( $e->getMessage()));
            throw new Exception(__($this->error($e)));
        }
        return $this;
    }

      /**
     * Create webhook when seetings are saved.
     * @return mixed
     */
    public function createWebhook() {
        $this->logger->debug('## 0.1 INSTANCE DATA --' . json_encode($this->merchant_id) . " --- " . json_encode($this->sk) . " --- " . json_encode($this->country));
        $openpay = $this->getOpenpayInstance();
        $base_url = $this->_storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB);
        $uri = $base_url."openpay/lending/webhook";

        $webhooks = $openpay->webhooks->getList([]);
        $webhookCreated = $this->isWebhookCreated($webhooks, $uri);
        if($webhookCreated){
            return $webhookCreated;
        }

        $webhook_data = array(
            'url' => $uri,
            'event_types' => array(
                'verification',
                'charge.succeeded',
                'charge.created',
                'charge.cancelled',
                'charge.failed',
                'payout.created',
                'payout.succeeded',
                'payout.failed',
                'spei.received',
                'chargeback.created',
                'chargeback.rejected',
                'chargeback.accepted',
                'transaction.expired'
            )
        );

        try {
            $this->logger->debug('## 0.2 WEBHOOK DATA --' . json_encode($webhook_data));
            $webhook = $openpay->webhooks->add($webhook_data);
            $this->logger->debug('## 1. WEBHOOK REGISTER --' . json_encode($webhook));
            return $webhook;
        } catch (Exception $e) {
            $this->logger->error('## 1. ERROR WEBHOOK REGISTER --' . $this->error($e));
            return $this->error($e);
        }
    }

    private function isWebhookCreated($webhooks, $uri) {
        foreach ($webhooks as $webhook) {
            if ($webhook->url === $uri) {
                return $webhook;
            }
        }
        return null;
    }

    /**
     * @param Exception $e
     * @return string
     */
    public function error($e) {

        /* 6001 el webhook ya existe */
        switch ($e->getCode()) {
            case '1000':
            case '1004':
            case '1005':
                $msg = 'Servicio no disponible.';
                break;
            case '6001':
                $msg = 'El webhook ya existe, has caso omiso de este mensaje.';
                break;
            case '6002':
                $msg = 'El webhook no pudo ser verificado, revisa la URL.';
                break;
            default: /* Demás errores 400 */
                $msg = 'La petición no pudo ser procesada.';
                break;
        }
        return 'ERROR '.$e->getCode().'. '.$msg;
    }

    public function getOpenpayInstance() {
        try{
            $this->logger->debug('## CL.model.payment.getOpenpayInstance', array('merchant_id' => $this->merchant_id, 'sk' => $this->sk, 'country' => $this->country ));
            $ipClient = $this->getIpClient();
            $openpay = Openpay::getInstance($this->merchant_id, $this->sk, $this->country, $ipClient);
            Openpay::setSandboxMode($this->is_sandbox);

            $userAgent = "Openpay-MTO2".$this->country."/v2";
            Openpay::setUserAgent($userAgent);

            return $openpay;
        }catch (\Exception $e) {
            throw new Exception(__($e->getMessage()));
        }
    }


    /**
     * @param Address $billing
     * @return boolean
     */
    public function validateAddress($billing) {
        if ($billing->getCountryId() === 'MX' && $billing->getStreetLine(1) && $billing->getCity() && $billing->getPostcode() && $billing->getRegion()) {
            return true;
        }
        return false;
    }

    private function formatAddress($customer_data, $billing) {
        if ($this->country === 'MX') {
            $customer_data['address'] = array(
                'line1' => $billing->getStreetLine(1),
                'line2' => $billing->getStreetLine(2),
                'postal_code' => $billing->getPostcode(),
                'city' => $billing->getCity(),
                'state' => $billing->getRegion(),
                'country_code' => $billing->getCountryId()
            );
        }
        return $customer_data;
    }



    /*######################################################################*/
    /*                              NEW METHODS                             */
    /*######################################################################*/

    private function setAdminTimezone(){
        /**
         * Magento utiliza el timezone UTC, por lo tanto sobreescribimos este
         * por la configuración que se define en el administrador
         */
        $store_tz = $this->_scopeConfig->getValue('general/locale/timezone');
        $this->logger->debug('## 2. SET ADMIN TIMEZONE --' . json_encode($store_tz));
        date_default_timezone_set($store_tz);
    }

    private function createCustomerData($order,$billing){
        try {
            $customer_data = array(
                'requires_account' => false,
                'name' => $billing->getFirstname(),
                'last_name' => $billing->getLastname(),
                'phone_number' => $billing->getTelephone(),
                'email' => $order->getCustomerEmail()
            );

            if ($this->validateAddress($billing)) {
                $customer_data = $this->formatAddress($customer_data, $billing);
            }
        }catch (Exception $e) {
            $this->debugData(['exception' => $e->getMessage()]);
            //$this->_logger->error(__( $e->getMessage()));
            throw new Exception(__($this->error($e)));
        }
        return $customer_data;
    }

    private function createChargeData($order,$billing,$shipping,$customer_data,$amount){
        try {
            $terms_flag = $this->isPrivacyTermsActivated();
            $base_url = $this->_storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB);
            $origin_channel = "PLUGIN_MAGENTO";
            $charge_data = array(
                'method' => 'lending',
                'amount' => $amount,
                'currency' => strtolower($order->getBaseCurrencyCode()),
                'description' => sprintf('ORDER #%s, %s', $order->getIncrementId(), $order->getCustomerEmail()),
                'order_id' => $order->getIncrementId(),
                "lending_data" => Array(
                    "is_privacy_terms_accepted" => $terms_flag, // Pending
                    "callbacks" => Array(
                        "on_success" => $base_url."openpay/lending/success",  // Pending
                        "on_reject" => $base_url."openpay/lending/cancelled", //?id=".$order->getIncrementId(),
                        "on_canceled" => $base_url."openpay/lending/cancelled", //?id=".$order->getIncrementId(),
                        "on_failed" => $base_url."openpay/lending/cancelled", //?id=".$order->getIncrementId()
                    ),
                    "shipping" => Array(
                        "name" => $shipping->getFirstname(),
                        "last_name" => $shipping->getLastname(),
                        "address" => Array(
                            "address" => $shipping->getData('street'),
                            "state" => $shipping->getData('region'),
                            "city" => $shipping->getData('city'),
                            "zipcode" => $shipping->getData('postcode'),
                            "country" => $shipping->getData('country_id'),
                        ),
                        "email" => $order->getCustomerEmail()
                    ),
                    "billing" => Array(
                        "name" => $billing->getFirstname(),
                        "last_name" => $billing->getLastname(),
                        "address" => Array(
                            "address" => $billing->getData('street'),
                            "state" => $billing->getData('region'),
                            "city" => $billing->getData('city'),
                            "zipcode" => $billing->getData('postcode'),
                            "country" => $billing->getData('country_id'),
                        ),
                        "phone_number" => $billing->getTelephone(),
                        "email" => $order->getCustomerEmail()
                    )
                ),
                'customer' => $customer_data,
                'origin_channel' => $origin_channel
            );
            return $charge_data;
        }catch (CouldNotSaveException $e) {
            $this->logger->error($e->getMessage());
            throw new CouldNotSaveException(__($e->getMessage()),$e);
        }
    }

    private function updateOrderData($payment, $charge_response, $order){
        $payment->setTransactionId($charge_response->id);

        $openpayCustomerFactory = $this->customerSession->isLoggedIn() ? $this->hasOpenpayAccount($this->customerSession->getCustomer()->getId()) : null;
        $openpay_customer_id = $openpayCustomerFactory ? $openpayCustomerFactory->openpay_id : null;

        // Actualiza el estado de la orden
        $state = \Magento\Sales\Model\Order::STATE_NEW;
        $order->setState($state)->setStatus($state);

        // Registra el ID de la transacción de Openpay
        $order->setExtOrderId($charge_response->id);
        // Registra (si existe), el ID de Customer de Openpay
        $order->setExtCustomerId($openpay_customer_id);
        $order->save();
    }

    public function getOpenpayCharge($charge_id, $customer_id = null) {
        try {
            if ($customer_id === null) {
                $openpay = $this->getOpenpayInstance();
                return $openpay->charges->get($charge_id);
            }

            $openpay_customer = $this->getOpenpayCustomer($customer_id);
            if($openpay_customer === false){
                $openpay = $this->getOpenpayInstance();
                return $openpay->charges->get($charge_id);
            }

            return $openpay_customer->charges->get($charge_id);
        } catch (\Exception $e) {
            throw new \Magento\Framework\Validator\Exception(__($e->getMessage()));
        }
    }

    public function isPrivacyTermsActivated()
    {
        $agreements = [];
        if ($this->_scopeConfig->isSetFlag('checkout/options/enable_agreements', ScopeInterface::SCOPE_STORE)) {
            /*OBTENER LISTA DE TERMINOS Y CONDICIONES*/
            ///** @var \Magento\CheckoutAgreements\Model\ResourceModel\Agreement\Collection $agreements */
            //$agreements = $this->_agreementCollectionFactory->create();
            //$agreements->addStoreFilter($this->_storeManager->getStore()->getId());
            //$agreements->addFieldToFilter('is_active', 1);
            //$this->setData('agreements',$agreements->getData());
            //$this->getData('agreements');
            return true;
        }else{
            throw new CouldNotSaveException(__('Los términos y condiciones no han sido aceptados'),null);
        }
    }


    /*######################################################################*/
    /*                     INHERITED METHODS FROM STORES                    */
    /*######################################################################*/

    private function retrieveOpenpayCustomerAccount($customer_data) {
        try {
            $customerId = $this->customerSession->getCustomer()->getId();

            $has_openpay_account = $this->hasOpenpayAccount($customerId);
            if ($has_openpay_account === false) {
                $openpay_customer = $this->createOpenpayCustomer($customer_data);
                //$this->logger->debug('$openpay_customer => '.$openpay_customer->id);

                $data = [
                    'customer_id' => $customerId,
                    'openpay_id' => $openpay_customer->id
                ];

                // Se guarda en BD la relación
                $openpay_customer_local = $this->openpayCustomerFactory->create();
                $openpay_customer_local->addData($data)->save();
            } else {
                $openpay_customer = $this->getOpenpayCustomer($has_openpay_account->openpay_id);
                if($openpay_customer === false){
                    $openpay_customer = $this->createOpenpayCustomer($customer_data);
                    //$this->logger->debug('#update openpay_customer', array('$openpay_customer_old' => $has_openpay_account->openpay_id, '$openpay_customer_old_new' => $openpay_customer->id));

                    // Se actualiza en BD la relación
                    $openpay_customer_local = $this->openpayCustomerFactory->create();
                    $openpay_customer_local_update = $openpay_customer_local->load($has_openpay_account->openpay_customer_id);
                    $openpay_customer_local_update->setOpenpayId($openpay_customer->id);
                    $openpay_customer_local_update->save();
                }
            }
            return $openpay_customer;
        } catch (\Exception $e) {
            throw new Exception(__($e->getMessage()));
        }
    }

    public function getOpenpayCustomer($openpay_customer_id) {
        try {
            $openpay = $this->getOpenpayInstance();
            $customer = $openpay->customers->get($openpay_customer_id);
            if(isset($customer->balance)){
                return false;
            }
            return $customer;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function hasOpenpayAccount($customer_id) {
        try {
            $openpay_customer_local = $this->openpayCustomerFactory->create();
            $response = $openpay_customer_local->fetchOneBy('customer_id', $customer_id);
            return $response;
        } catch (\Exception $e) {
            throw new Exception(__($e->getMessage()));
        }
    }

    private function createOpenpayCustomer($data) {
        try {
            $openpay = $this->getOpenpayInstance();
            return $openpay->customers->add($data);
        } catch (\Exception $e) {
            throw new Exception(__($e->getMessage()));
        }
    }

    public function getIpClient(){
        // Recogemos la IP de la cabecera de la conexión
        if (!empty($_SERVER['HTTP_CLIENT_IP']))
        {
            $ipAdress = $_SERVER['HTTP_CLIENT_IP'];
            $this->logger->debug('#HTTP_CLIENT_IP', array('$IP' => $ipAdress));
        }
        // Caso en que la IP llega a través de un Proxy
        elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))
        {
            $ipAdress = $_SERVER['HTTP_X_FORWARDED_FOR'];
            $this->logger->debug('#HTTP_X_FORWARDED_FOR', array('$IP' => $ipAdress));
        }
        // Caso en que la IP lleva a través de la cabecera de conexión remota
        else
        {
            $ipAdress = $_SERVER['REMOTE_ADDR'];
            $this->logger->debug('#REMOTE_ADDR', array('$IP' => $ipAdress));
        }
        return $ipAdress;
    }



    /*######################################################################*/
    /*                             OVERRIDE METHODS                         */
    /*######################################################################*/

    /**
     * Availability for currency
     * @override canUseForCurrency MethodInterface.php
     * @param string $currencyCode
     * @return bool
     */
    public function canUseForCurrency($currencyCode) {
        if ($this->country === 'MX') {
            return in_array($currencyCode, $this->supported_currency_codes);
        }
        return false;
    }



    /*######################################################################*/
    /*                     MODIFIED METHODS FROM STORES                     */
    /*######################################################################*/

    /**
     * @modified makeOpenpayCharge() Stores
     */
    private function sendOpenpayChargeRequest($customer_data, $charge_request) {
        try {
            $openpay = $this->getOpenpayInstance();

            if (!$this->customerSession->isLoggedIn()) {
                // Cargo para usuarios "invitados"
                $charge = $openpay->charges->create($charge_request);
                $this->logger->debug(json_encode($charge->error_message));
                if($charge->error_message){
                    throw new CouldNotSaveException(__($charge->error_message),null);
                }
                return $charge;
            }

            // Se remueve el atributo de "customer" porque ya esta relacionado con una cuenta en Openpay
            unset($charge_request['customer']);

            $openpay_customer = $this->retrieveOpenpayCustomerAccount($customer_data);

            // Cargo para usuarios con cuenta
            $charge = $openpay_customer->charges->create($charge_request);
            if($charge->error_message){
                throw new CouldNotSaveException(__($charge->error_message),null);
            }
            return $charge;
        }catch (CouldNotSaveException $e) {
            $this->logger->error($e->getMessage());
            throw new CouldNotSaveException(__($e->getMessage()),$e);
        }
    }
}
