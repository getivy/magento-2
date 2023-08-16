<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Success;

use Esparksinc\IvyPayment\Helper\Api as ApiHelper;
use Esparksinc\IvyPayment\Helper\Invoice as InvoiceHelper;
use Esparksinc\IvyPayment\Model\IvyFactory;
use Esparksinc\IvyPayment\Model\ErrorResolver;
use Esparksinc\IvyPayment\Model\Logger;
use Magento\Checkout\Model\Session;
use Magento\Checkout\Model\Type\Onepage;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Sales\Model\OrderFactory;

class Index extends Action
{
    protected $resultRedirect;
    protected $order;
    protected $ivy;
    protected $config;
    protected $onePage;
    protected $checkoutSession;
    protected $logger;
    protected $errorResolver;
    protected $apiHelper;
    protected $invoiceHelper;

    /**
     * @param Context $context
     * @param RedirectFactory $resultRedirectFactory
     * @param OrderFactory $order
     * @param IvyFactory $ivy
     * @param Onepage $onePage
     * @param Session $checkoutSession
     * @param Logger $logger
     * @param ErrorResolver $resolver
     * @param ApiHelper $apiHelper
     * @param InvoiceHelper $invoiceHelper
     */
    public function __construct(
        Context         $context,
        RedirectFactory $resultRedirectFactory,
        OrderFactory    $order,
        IvyFactory      $ivy,
        Onepage         $onePage,
        Session         $checkoutSession,
        Logger          $logger,
        ErrorResolver   $resolver,
        ApiHelper       $apiHelper,
        InvoiceHelper   $invoiceHelper
    ) {
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->order = $order;
        $this->ivy = $ivy;
        $this->onePage = $onePage;
        $this->checkoutSession = $checkoutSession;
        $this->logger = $logger;
        $this->errorResolver = $resolver;
        $this->apiHelper = $apiHelper;
        $this->invoiceHelper = $invoiceHelper;
        parent::__construct($context);
    }
    public function execute()
    {
        // Get success params from Ivy
        $magentoOrderId = $this->getRequest()->getParam('reference');
        $ivyOrderId = $this->getRequest()->getParam('order-id');

        $this->logger->debugRequest($this, $magentoOrderId);

        $quote = $this->checkoutSession->getQuote();

        $data = [
            'id' => $magentoOrderId
        ];
        $this->apiHelper->requestApi($this, 'order/details', $data, $magentoOrderId, function ($exception) use ($quote) {
            $this->errorResolver->forceReserveOrderId($quote);
        });

        // Save info in db
        $ivyModel = $this->ivy->create();
        $ivyModel->load($magentoOrderId,'magento_order_id');
        $ivyModel->setIvyOrderId($ivyOrderId);
        $ivyModel->save();

        if ($quote->getBillingAddress()->getFirstname()) {
            $this->onePage->saveOrder();
        }

        $orderdetails = $this->order->create()->loadByIncrementId($magentoOrderId);
        if ($orderdetails->canInvoice()) {
            $this->invoiceHelper->createInvoice($orderdetails, $ivyOrderId);
        }

        $this->logger->debugApiAction($this, $magentoOrderId, 'Order', $orderdetails->getData());

        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('checkout/onepage/success');
        return $resultRedirect;
    }
}
