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
class Success extends \Magento\Framework\App\Action\Action
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
    /**
     * Load the page defined in view/frontend/layout/openpay_index_webhook.xml
     * URL /openpay/payment/success
     *
     * @url https://magento.stackexchange.com/questions/197310/magento-2-redirect-to-final-checkout-page-checkout-success-failed?rq=1
     * @return \Magento\Framework\View\Result\Page
     */
    public function execute() {
         $customer=null;
        try {
            $order_id = $this->checkoutSession->getLastOrderId();
            $quote_id = $this->checkoutSession->getLastQuoteId();
            $this->checkoutSession->setLastSuccessQuoteId($quote_id);


            $this->logger->debug('## CL.controller.order.success.execute --',
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

            $this->logger->debug('## CL.controller.order.success.execute --',
                array(
                    'openpay'=>$openpay ,
                    'order'=>$order,
                    'customer_id'=>$customer_id,
                    'transaction_id'=>$transaction_id
                )
            );

            // Seleccionar el tipo de consulta que se hará a la API
            if ($customer_id) {
                $this->logger->debug('## CL.controller.order.success.execute -- USER TRANSACTION WITH ACCOUNT ##');
                $customer = $this->payment->getOpenpayCustomer($customer_id);
                $charge = $customer->charges->get($transaction_id);
            } else {
                $this->logger->debug('## CL.controller.order.success.execute -- USER TRANSACTION WITHOUT ACCOUNT ##');
                $charge = $openpay->charges->get($transaction_id);
            }

            $this->logger->debug('## CL.controller.order.success.execute -- ',
                array(
                    'customer'=>$customer,
                    'charge'=>$charge,
                    'external_charge_status'=>$charge->status
                ));

            // Si el cargo no tiene el estatus completado se cancela la orden
            if ($order && $charge->status != 'completed') {
                $this->logger->debug('## CL.controller.order.success.execute -- CREDIT NOT APPROVED ##');
                $order->cancel();
                $order->addStatusToHistory(\Magento\Sales\Model\Order::STATE_CANCELED, __('El crédito no ha sido aprobado'));
                $order->save();
                $this->logger->debug('## CL.controller.order.success.execute -- ORDER CANCELLED ##');
                return $this->resultPageFactory->create();
            }

            $this->logger->debug('## CL.controller.order.success.execute -- CREDIT APPROVED ##');
            $status = \Magento\Sales\Model\Order::STATE_PROCESSING;
            $order->setState($status)->setStatus($status);
            $order->setTotalPaid($charge->amount);
            $order->addStatusHistoryComment("Pago recibido exitosamente")->setIsCustomerNotified(true);
            $order->save();

            $this->logger->debug('## CL.controller.order.success.execute -- CREATING INVOICE ##');
            $requiresInvoice = true;
            /** @var InvoiceCollection $invoiceCollection */
            $invoiceCollection = $order->getInvoiceCollection();
            if ( $invoiceCollection->count() > 0 ) {
                /** @var Invoice $invoice */
                foreach ($invoiceCollection as $invoice ) {
                    if ( $invoice->getState() == Invoice::STATE_OPEN) {
                        $invoice->setState(Invoice::STATE_PAID);
                        $invoice->setTransactionId($charge->id);
                        $invoice->pay()->save();
                        $requiresInvoice = false;
                        break;
                    }
                }
            }

            if ( $requiresInvoice ) {
                $invoice = $this->_invoiceService->prepareInvoice($order);
                $invoice->setTransactionId($charge->id);
//            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
//            $invoice->register();
                $invoice->pay()->save();
            }

            $payment = $order->getPayment();
            $payment->setAmountPaid($charge->amount);
            $payment->setIsTransactionPending(false);
            $payment->save();

            $this->logger->debug('## CL.controller.order.success.execute -- SUCCESS REDIRECT:checkout/onepage/success');
            return $this->resultRedirectFactory->create()->setPath('checkout/onepage/success');

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
        return $this->resultRedirectFactory->create()->setPath('checkout/cart');
    }
}
