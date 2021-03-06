<?php

/**
 * @category    Payments
 * @package     Openpay_CheckoutLending
 * @author      Openpay
 * @copyright   Openpay (http://openpay.mx)
 * @license     http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0
 */

namespace Openpay\CheckoutLending\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Openpay\CheckoutLending\Model\Payment as Config;
use Magento\Framework\DataObject;

class AfterPlaceOrder implements ObserverInterface {

    protected $config;
    protected $order;
    protected $logger;
    protected $_actionFlag;
    protected $_response;
    protected $_redirect;
    protected $openpayCustomerFactory;

    public function __construct(
        Config $config, \Magento\Sales\Model\Order $order,
        \Magento\Framework\App\Response\RedirectInterface $redirect,
        \Magento\Framework\App\ActionFlag $actionFlag,
        \Psr\Log\LoggerInterface $logger_interface,
        \Magento\Framework\App\ResponseInterface $response
    ) {
        $this->config = $config;
        $this->order = $order;
        $this->logger = $logger_interface;
        $this->_redirect = $redirect;
        $this->_response = $response;
        $this->_actionFlag = $actionFlag;
    }

    public function execute(Observer $observer) {
        $orderId = $observer->getEvent()->getOrderIds();
        $order = $this->order->load($orderId[0]);

        $this->logger->debug('#AfterPlaceOrder openpay_checkoutLending');

        if ($order->getPayment()->getMethod() == 'openpay_checkoutLending') {
            $this->logger->debug('## ORDER ID FROM ORDER -- '. $order->getExtOrderId());
            $this->logger->debug('## CUSTOMER ID FROM ORDER -- '. $order->getExtCustomerId());
            $charge = $this->config->getOpenpayCharge($order->getExtOrderId(), $order->getExtCustomerId());
            $this->logger->debug('## CHARGE STATUS -- '. $charge->status);

            $this->logger->debug('#AfterPlaceOrder openpay_checkoutLending', array('order_id' => $orderId[0], 'order_status' => $order->getStatus(), 'charge_id' => $charge->id, 'ext_order_id' => $order->getExtOrderId(), 'openpay_status' => $charge->status));

            if ($charge->status == 'in_progress' && $order->getStatus() == 'pending' && isset($_SESSION['kueski_redirect_url'])) {
                $this->logger->debug('#AfterPlaceOrder', array('ext_order_id' => $order->getExtOrderId(), 'redirect_url' => $_SESSION['kueski_redirect_url']));
                $this->_actionFlag->set('', \Magento\Framework\App\Action\Action::FLAG_NO_DISPATCH, true);
                $this->_redirect->redirect($this->_response, $_SESSION['kueski_redirect_url']);
            }
        }
        return $this;
    }
}





