<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Webhook;

use Esparksinc\IvyPayment\Helper\Invoice as InvoiceHelper;
use Esparksinc\IvyPayment\Helper\Quote as QuoteHelper;
use Esparksinc\IvyPayment\Model\Config;
use Esparksinc\IvyPayment\Model\ErrorResolver;
use Esparksinc\IvyPayment\Model\Logger;
use Esparksinc\IvyPayment\Model\System\Config\Statuses;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\FlagManager;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\RefundInvoiceInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Quote\Api\CartManagementInterface;

class Index extends Action implements CsrfAwareActionInterface
{
    const FLAG_LOCKED_QUOTES = 'ivy_locked_quotes';

    protected $config;
    protected $json;
    protected $jsonFactory;
    protected $refund;
    protected $orderManagement;
    protected $logger;
    protected $invoiceHelper;
    protected $searchCriteriaBuilder;
    protected $orderRepository;
    protected $quoteManagement;
    protected $errorResolver;
    protected $quoteHelper;
    protected $flagManager;

    /**
     * @param Context $context
     * @param Config $config
     * @param Json $json
     * @param JsonFactory $jsonFactory
     * @param RefundInvoiceInterface $refund
     * @param Logger $logger
     * @param InvoiceHelper $invoiceHelper
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param OrderRepositoryInterface $orderRepository
     * @param CartManagementInterface $quoteManagement
     * @param ErrorResolver $errorResolver
     * @param QuoteHelper $quoteHelper
     * @param FlagManager $flagManager
     */
    public function __construct(
        Context                  $context,
        Config                   $config,
        Json                     $json,
        JsonFactory              $jsonFactory,
        RefundInvoiceInterface   $refund,
        Logger                   $logger,
        InvoiceHelper            $invoiceHelper,
        SearchCriteriaBuilder    $searchCriteriaBuilder,
        OrderRepositoryInterface $orderRepository,
        CartManagementInterface  $quoteManagement,
        ErrorResolver            $errorResolver,
        QuoteHelper              $quoteHelper,
        FlagManager              $flagManager
    ) {
        $this->config = $config;
        $this->json = $json;
        $this->jsonFactory = $jsonFactory;
        $this->refund = $refund;
        $this->logger = $logger;
        $this->invoiceHelper = $invoiceHelper;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->orderRepository = $orderRepository;
        $this->quoteManagement = $quoteManagement;
        $this->errorResolver = $errorResolver;
        $this->quoteHelper = $quoteHelper;
        $this->flagManager = $flagManager;
        parent::__construct($context);
    }

    public function execute()
    {
        $request = $this->getRequest();
        if(!$this->isValidRequest($request))
        {
            return false;
        }

        $jsonContent = $request->getContent();
        $arrData = $this->json->unserialize((string)$jsonContent);

        // request type can be "merchant_app_updated" without paypload > referenceId field
        $magentoOrderId = $arrData['payload']['referenceId'] ?? 'unknown';
        $this->logger->debugRequest($this, $magentoOrderId);

        $resultStatusCode = 200;

        if ($arrData['type'] === 'order_updated' || $arrData['type'] === 'order_created')
        {
            $quoteId = $arrData['payload']['metadata']['quote_id'] ?? null;
            $quote = $this->quoteHelper->getQuote($magentoOrderId, $quoteId);

            $newOrderStatus = $this->config->getMapWaitingForPaymentStatus();
            // if invoice should be created without confirmation from Ivy payment system
            $createInvoiceImmediately = $newOrderStatus == Statuses::PAID;

            try {
                $this->lockQuote($quote, $magentoOrderId);

                switch ($arrData['payload']['status']) {
                    case 'canceled':
                        // do nothing.
                        break;
                    case 'waiting_for_payment':
                        /** @var Order $newOrder */
                        $newOrder = $this->retrieveOrder($quote);

                        // some problem happened
                        if (!$newOrder) {
                            $resultStatusCode = 400;
                            break;
                        }

                        if ($createInvoiceImmediately && $newOrder->canInvoice()) {
                            $this->createInvoice($newOrder, $arrData);
                        }

                        $newOrder->setStatus($createInvoiceImmediately ? 'processing': $newOrderStatus);
                        $newOrder->save();

                        break;

                    case 'paid':
                        /** @var Order $newOrder */
                        $newOrder = $this->retrieveOrder($quote);

                        // some problem happened
                        if (!$newOrder) {
                            $resultStatusCode = 400;
                            break;
                        }

                        /*
                         * An invoice should be created at the moment.
                         * Return success status to Ivy but do nothing.
                         */
                        if ($createInvoiceImmediately) {
                            break;
                        }

                        if ($newOrder->canInvoice()) {
                            $this->createInvoice($newOrder, $arrData);
                        } else {
                            $newOrder->setStatus('processing');
                            $newOrder->save();
                        }

                        break;
                }
            } finally {
                $this->unlockQuote($quote);
            }
        }

        return $this->jsonFactory->create()->setHttpResponseCode($resultStatusCode)->setData([]);
    }

    public function createCsrfValidationException(RequestInterface $request): ? InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        if($this->isValidRequest($request))
        {
            return true;
        }
        return false;
    }

    private function isValidRequest(RequestInterface $request)
    {
        $hash = hash_hmac(
            'sha256',
            $request->getContent(),
            $this->config->getWebhookSecret());

        if ($request->getHeaders('x-ivy-signature')->getFieldValue() === $hash) {
            return true;
        }

        return false;
    }

    private function orderRefund(Order $orderdetails)
    {
        /*
         * If the order/complete callback was unsuccessful then the order with the reserved id may not have been created.
         * Ivy sends request to refund the order in this case. So we should check if we have anything to refund.
         */
        if (!$orderdetails->getId()) {
            return;
        }

        // STATUS_REFUNDED = 8
        if ($orderdetails->getStatusId() === 8) {
            return;
        }

        if (!$this->isIvyPayment($orderdetails->getPayment())) {
            return;
        }

        $invoices = $orderdetails->getInvoiceCollection();
        foreach ($invoices as $invoice) {
            $invoiceId = $invoice->getId();
            if ($invoiceId) {
                $this->refund->execute($invoiceId,[],true);
            }
        }
    }

    private function createInvoice(Order $orderdetails, $arrData)
    {
        $ivyOrderId = $arrData['payload']['id'];

        // don't invoice if invoice with ivy as payment already exists
        $invoices = $orderdetails->getInvoiceCollection();
        foreach ($invoices as $invoice) {
            if ($invoice->getTransactionId() === $ivyOrderId) {
                return;
            }
        }

        $this->invoiceHelper->createInvoice($orderdetails, $ivyOrderId);
    }

    /**
     * This method should prevent race conditions between webhooks
     *
     * @param $quote
     * @param $magentoOrderId
     * @return void
     */
    private function lockQuote($quote, $magentoOrderId)
    {
        $quoteId = $quote->getId();
        $lockedQuotesIds = (array)$this->flagManager->getFlagData(self::FLAG_LOCKED_QUOTES);

        /*
         * If locker exists then wait 5 seconds and process the webhook anyway.
         */
        $counter = 0;
        while (array_key_exists($quoteId, $lockedQuotesIds)) {
            if ($counter > 10) {
                $this->logger->debugApiAction($this, $magentoOrderId, 'Timeout of quote lock',
                    ['quote_id' => $quoteId]
                );
                break;
            }

            $counter++;
            // wait 500 milliseconds
            usleep(500*1000);

            $lockedQuotesIds = (array)$this->flagManager->getFlagData(self::FLAG_LOCKED_QUOTES);
        }

        if (!array_key_exists($quoteId, $lockedQuotesIds)) {
            $lockedQuotesIds[$quoteId] = 1;
            $this->flagManager->saveFlag(self::FLAG_LOCKED_QUOTES, $lockedQuotesIds);
        }
    }

    private function unlockQuote($quote)
    {
        $lockedQuotesIds = (array)$this->flagManager->getFlagData(self::FLAG_LOCKED_QUOTES);
        unset($lockedQuotesIds[$quote->getId()]);
        $this->flagManager->saveFlag(self::FLAG_LOCKED_QUOTES, $lockedQuotesIds);
    }

    /**
     * @param Payment $payment
     * @return bool
     * @throws LocalizedException
     */
    private function isIvyPayment($payment)
    {
        $code = $payment->getMethodInstance()->getCode();
        return $code === 'ivy';
    }

    private function loadOrderByQuoteId($quoteId)
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('quote_id', $quoteId)
            ->create();

        $orders = $this->orderRepository->getList($searchCriteria);
        if ($orders->getTotalCount() > 0) {
            return array_values($orders->getItems())[0];
        } else {
            return false;
        }
    }

    /**
     * Create or load already exists order
     *
     * @param $quote
     * @return false|\Magento\Sales\Api\Data\OrderInterface|null
     */
    private function retrieveOrder($quote)
    {
        // check if order already exists for this quote
        $order = $this->loadOrderByQuoteId($quote->getId());
        if ($order && $order->getId()) {
            return $order;
        }

        try {
            return $this->quoteManagement->submit($quote);
        } catch (\Exception $exception) {
            $this->logger->debugApiAction($this, $quote->getId(), 'Quote submit error',
                [$exception->getMessage()]
            );

            // return 400 status in this response will trigger the webhook to be sent again
            $this->errorResolver->forceReserveOrderId($quote);
            return false;
        }
    }
}
