<?php
/**
 * @category    Payments
 * @package     Openpay_CheckoutLending
 * @author      Openpay
 * @copyright   Openpay (http://openpay.mx)
 * @license     http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0
 */
namespace Openpay\CheckoutLending\Controller\Payment;

use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Model\ResourceModel\Order\Invoice\Collection as InvoiceCollection;
use Openpay\CheckoutLending\Model\Payment as OpenpayPayment;
use Magento\Sales\Model\Order\Invoice;
/**
 * Webhook class
 */
class Cancelled extends \Magento\Framework\App\Action\Action
{
    protected $resultPageFactory;
    protected $request;
    protected $payment;
    protected $checkoutSession;
    protected $orderRepository;
    protected $logger;
    protected $_invoiceService;
    protected $transactionBuilder;

    /**
     *
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param \Magento\Framework\App\Request\Http $request
     * @param OpenpayPayment $payment
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Psr\Log\LoggerInterface $logger_interface
     * @param \Magento\Sales\Model\Service\InvoiceService $invoiceService
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        \Magento\Framework\App\Request\Http $request,
        OpenpayPayment $payment,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Psr\Log\LoggerInterface $logger_interface,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $transactionBuilder
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->request = $request;
        $this->payment = $payment;
        $this->checkoutSession = $checkoutSession;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger_interface;
        $this->_invoiceService = $invoiceService;
        $this->transactionBuilder = $transactionBuilder;
    }

    public function execute() {
        $customer=null;
        $error_msg=null;
        try {
            $order_id = $this->checkoutSession->getLastOrderId();
            $quote_id = $this->checkoutSession->getLastQuoteId();
            $this->checkoutSession->setLastSuccessQuoteId($quote_id);


            $this->logger->debug('## CL.controller.payment.cancelled.execute --',
                array(
                    'order_id'=>$order_id ,
                    'quote_id'=>$quote_id,
                    'getLastSuccessQuoteId'=>$this->checkoutSession->getLastSuccessQuoteId(),
                    'getLastRealOrderId'=>$this->checkoutSession->getLastRealOrderId()
                )
            );

            $openpay = $this->payment->getOpenpayInstance();
            $order = $this->orderRepository->get($order_id);
            $customer_id = $order->getExtCustomerId();
            $transaction_id = $order->getExtOrderId();

            $this->logger->debug('## CL.controller.payment.cancelled.execute --',
                array(
                    'openpay'=>$openpay ,
                    'order'=>$order,
                    'customer_id'=>$customer_id,
                    'transaction_id'=>$transaction_id
                )
            );

            // Seleccionar el tipo de consulta que se hará a la API
            if ($customer_id) {
                $this->logger->debug('## CL.controller.payment.cancelled.execute -- USER TRANSACTION WITH ACCOUNT ##');
                $customer = $this->payment->getOpenpayCustomer($customer_id);
                $charge = $customer->charges->get($transaction_id);
            } else {
                $this->logger->debug('## CL.controller.order.cancelled.execute -- USER TRANSACTION WITHOUT ACCOUNT ##');
                $charge = $openpay->charges->get($transaction_id);
            }

            $this->logger->debug('## CL.controller.payment.cancelled.execute -- ',
                array(
                    'customer'=>$customer,
                    'charge'=>$charge,
                    'external_charge_status'=>$charge->status
                ));

            // Si el cargo no tiene el estatus completado se cancela la orden
            if ($order && $charge->status != 'completed' && $order->getStatus() == "pending") {
                $this->logger->debug('## CL.controller.payment.cancelled.execute -- CREDIT NOT APPROVED ##');
                $order->cancel();
                $order->addStatusToHistory(\Magento\Sales\Model\Order::STATE_CANCELED, __('La orden ha sido cancelada'));
                $order->save();
                $this->logger->debug('## CL.controller.payment.cancelled.execute -- ORDER CANCELLED ##');
            }elseif($order && $charge->status != 'completed' && $order->getStatus() == "canceled"){
                $error_msg = "La orden ha sido cancelada debido a un error en el pago";
            }else{
                $error_msg = "La orden no puede ser cancelada";
            }

            $resultPage = $this->resultPageFactory->create();

            /** @var Template $block */
            $block = $resultPage->getLayout()->getBlock('cancelled');
            $block->setData('error_msg', $error_msg);

            return $resultPage;

        } catch (\Exception $e) {
            $this->logger->error('## CL.controller.order.success.execute -- ERROR',
                array(
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                )
            );
            //throw new \Magento\Framework\Validator\Exception(__($e->getMessage()));
        }
    }
}
