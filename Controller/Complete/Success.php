<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Complete;

use Esparksinc\IvyPayment\Model\Config;
use Esparksinc\IvyPayment\Model\Logger;
use Esparksinc\IvyPayment\Model\IvyFactory;
use Magento\Checkout\Model\Session;
use Magento\Checkout\Model\Type\Onepage;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\QuoteRepository;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;

class Success extends Action
{
    protected $resultRedirect;
    protected $order;
    protected $ivy;
    protected $invoiceService;
    protected $transaction;
    protected $invoiceSender;
    protected $config;
    protected $json;
    protected $onePage;
    protected $checkoutSession;
    protected $quoteRepository;
    protected $logger;

    /**
     * @param Context $context
     * @param RedirectFactory $resultRedirectFactory
     * @param OrderFactory $order
     * @param IvyFactory $ivy
     * @param InvoiceService $invoiceService
     * @param InvoiceSender $invoiceSender
     * @param Transaction $transaction
     * @param Json $json
     * @param Config $config
     * @param Onepage $onePage
     * @param Session $checkoutSession
     * @param QuoteRepository $quoteRepository
     * @param Logger $logger
     */
    public function __construct(
        Context         $context,
        RedirectFactory $resultRedirectFactory,
        OrderFactory    $order,
        IvyFactory      $ivy,
        InvoiceService  $invoiceService,
        InvoiceSender   $invoiceSender,
        Transaction     $transaction,
        Json            $json,
        Config          $config,
        Onepage         $onePage,
        Session         $checkoutSession,
        QuoteRepository $quoteRepository,
        Logger          $logger
    ) {
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->order = $order;
        $this->ivy = $ivy;
        $this->invoiceService = $invoiceService;
        $this->transaction = $transaction;
        $this->invoiceSender = $invoiceSender;
        $this->json = $json;
        $this->config = $config;
        $this->onePage = $onePage;
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->logger = $logger;
        parent::__construct($context);
    }
    public function execute()
    {
        // Get success params from Ivy
        $magentoOrderId = $this->getRequest()->getParam('reference');
        $ivyOrderId = $this->getRequest()->getParam('order-id');

        $this->logger->debugRequest($this, $magentoOrderId);

        // Save info in db
        $ivyModel = $this->ivy->create();
        $ivyModel->load($magentoOrderId,'magento_order_id');
        $ivyModel->setIvyOrderId($ivyOrderId);
        $ivyModel->save();

        $orderdetails = $this->order->create()->loadByIncrementId($magentoOrderId);
        // it could be that the order has been created already. If it does not exist, we should wait and poll for it until it exists.
        $counter = 0;
        while (!$orderdetails->getId()) {
            if ($counter > 20) {
                $this->logger->debug('Order not found, giving up');
                return false;
            }
            $counter++;
            // wait 200 milliseconds
            usleep(200*1000);

            $this->logger->debug('Order not found, waiting for it to be created');
            $orderdetails = $this->order->create()->loadByIncrementId($magentoOrderId);
        }

        $quote = $this->checkoutSession->getQuote();
        $quote->setIsActive(0);
        $this->quoteRepository->save($quote);

        $this->checkoutSession->clearQuote();

        // TODO : this doesn't seem to work (especially for the minicart)
        // could be because we're ending up in a redirect
        // to be re-evaluated when we open up the configurable thank you page task.
        $this->checkoutSession->clearStorage();
        $this->checkoutSession->clearHelperData();

        if ($orderdetails) {
            $this->checkoutSession->setLastQuoteId($orderdetails->getQuoteId())
                ->setLastSuccessQuoteId($orderdetails->getQuoteId())
                ->setLastOrderId($orderdetails->getId())
                ->setLastRealOrderId($orderdetails->getIncrementId())
                ->setLastOrderStatus($orderdetails->getStatus());
        }

        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('checkout/onepage/success', ['_secure' => true]);
        return $resultRedirect;
    }
}
