<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Checkout;

use Esparksinc\IvyPayment\Helper\Api as ApiHelper;
use Esparksinc\IvyPayment\Helper\Discount as DiscountHelper;
use Esparksinc\IvyPayment\Model\Config;
use Esparksinc\IvyPayment\Model\Logger;
use Esparksinc\IvyPayment\Model\ErrorResolver;
use Esparksinc\IvyPayment\Model\IvyFactory;
use Magento\Checkout\Model\Session;
use Magento\Checkout\Model\Type\Onepage;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Cart\CartTotalRepository;

class Index extends Action
{
    protected $resultRedirectFactory;
    protected $jsonFactory;
    protected $checkoutSession;
    protected $quoteRepository;
    protected $config;
    protected $onePage;
    protected $ivy;
    protected $cartTotalRepository;
    protected $logger;
    protected $errorResolver;
    protected $apiHelper;
    protected $discountHelper;

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param RedirectFactory $resultRedirectFactory
     * @param Session $checkoutSession
     * @param CartRepositoryInterface $quoteRepository
     * @param Config $config
     * @param Onepage $onePage
     * @param IvyFactory $ivy
     * @param CartTotalRepository $cartTotalRepository
     * @param Logger $logger
     * @param ErrorResolver $errorResolver
     * @param ApiHelper $apiHelper
     * @param DiscountHelper $discountHelper
     */
    public function __construct(
        Context                 $context,
        JsonFactory             $jsonFactory,
        RedirectFactory         $resultRedirectFactory,
        Session                 $checkoutSession,
        CartRepositoryInterface $quoteRepository,
        Config                  $config,
        Onepage                 $onePage,
        IvyFactory              $ivy,
        CartTotalRepository     $cartTotalRepository,
        Logger                  $logger,
        ErrorResolver           $errorResolver,
        ApiHelper               $apiHelper,
        DiscountHelper           $discountHelper
    ) {
        $this->jsonFactory = $jsonFactory;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->config = $config;
        $this->onePage = $onePage;
        $this->ivy = $ivy;
        $this->cartTotalRepository = $cartTotalRepository;
        $this->logger = $logger;
        $this->errorResolver = $errorResolver;
        $this->apiHelper = $apiHelper;
        $this->discountHelper = $discountHelper;
        parent::__construct($context);
    }
    public function execute()
    {
        $express = $this->getRequest()->getParam('express');
        $ivyModel = $this->ivy->create();

        $quote = $this->checkoutSession->getQuote();

        if (!$quote->getReservedOrderId()) {
            $quote->reserveOrderId();
            $ivyModel->setMagentoOrderId($quote->getReservedOrderId());
        }

        $orderId = $quote->getReservedOrderId();

        $this->logger->debugRequest($this, $orderId);

        $quote->collectTotals();

        $this->quoteRepository->save($quote);

        $price = $this->getPrice($quote, $express);

        $ivyLineItems = $this->getLineItems($quote);

        $shippingMethods = $quote->isVirtual() ? [] : $this->getShippingMethod($quote);

        $billingAddress = $this->getBillingAddress($quote);

        $mcc = $this->config->getMcc();

        $plugin = $this->getPluginVersion();

        if($express) {
            $phone = ['phone' => true];
            $data = [
                'express' => true,
                'referenceId' => $orderId,
                'category' => $mcc,
                'price' => $price,
                'lineItems' => $ivyLineItems,
                'required' => $phone,
                'plugin' => $plugin,
            ];
        } else {
            $prefill = ["email" => $quote->getBillingAddress()->getEmail()];
            $data = [
                'handshake' => true,
                'referenceId' => $orderId,
                'category' => $mcc,
                'price' => $price,
                'lineItems' => $ivyLineItems,
                'shippingMethods' =>  $shippingMethods,
                'billingAddress' => $billingAddress,
                'prefill' => $prefill,
                'plugin' => $plugin,
            ];
        }

        $path = $this->scopeConfig->getValue(
            'design/header/logo_src',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        if($path)
        {
            $shopLogo = $this->urlBuilder
                ->getBaseUrl(['_type' => \Magento\Framework\UrlInterface::URL_TYPE_MEDIA]) .'logo/'. $path;
        }
        else{
            $shopLogo = $this->logo->getLogoSrc();
        }

        $data = array_merge($data, [
            'successCallbackUrl'    => $this->_url->getUrl('ivypayment/success'),
            'errorCallbackUrl'      => $this->_url->getUrl('ivypayment/fail'),
            'quoteCallbackUrl'      => $this->_url->getUrl('ivypayment/quote'),
            'webhookUrl'            => $this->_url->getUrl('ivypayment/webhook'),
            'completeCallbackUrl'   => $this->_url->getUrl('ivypayment/order/complete'),
            'shopLogo'              => $shopLogo,
        ]);

        $responseData = $this->apiHelper->requestApi($this, 'checkout/session/create', $data, $orderId,
            function ($exception) use ($quote) {
                $this->errorResolver->tryResolveException($quote, $exception);
            }
        );

        if ($responseData) {

            $ivyModel->setIvyCheckoutSession($responseData['id']);
            $ivyModel->setIvyRedirectUrl($responseData['redirectUrl']);
            $ivyModel->save();

            return $this->jsonFactory->create()->setData(['redirectUrl'=> $responseData['redirectUrl']]);
        }
    }

    private function getLineItems($quote)
    {
        $ivyLineItems = array();
        foreach ($quote->getAllVisibleItems() as $lineItem) {
            $ivyLineItems[] = [
                'name'          => $lineItem->getName(),
                'referenceId'   => $lineItem->getSku(),
                'singleNet'     => $lineItem->getBasePrice(),
                'singleVat'     => $lineItem->getBaseTaxAmount() ?: 0,
                'amount'        => $lineItem->getBaseRowTotalInclTax() ?: 0,
                'quantity'      => $lineItem->getQty(),
                'image'         => '',
            ];
        }

        $discountAmount = $this->discountHelper->getDiscountAmount($quote);
        if ($discountAmount !== 0.0) {
            $discountAmount = -1 * abs($discountAmount);
            $ivyLineItems[] = [
                'name'      => 'Discount',
                'singleNet' => $discountAmount,
                'singleVat' => 0,
                'amount'    => $discountAmount
            ];
        }

        return $ivyLineItems;
    }

    private function getPrice($quote, $express)
    {
        $totals = $this->cartTotalRepository->get($quote->getId());

        $shippingNet = $totals->getBaseShippingAmount();
        $shippingVat = $totals->getBaseShippingTaxAmount();
        $shippingTotal = $shippingNet + $shippingVat;

        $total = $totals->getBaseGrandTotal();
        $vat = $totals->getBaseTaxAmount();

        $totalNet = $total - $vat;

        $currency = $quote->getBaseCurrencyCode();

        if ($express) {
            $total -= $shippingTotal;
            $vat -= $shippingVat;
            $totalNet -= $shippingNet;
            $shippingTotal = 0;
            $shippingVat = 0;
            $shippingNet = 0;
        }

        return [
            'totalNet' => $totalNet,
            'vat' => $vat,
            'shipping' => $shippingTotal,
            'total' => $total,
            'currency' => $currency,
        ];
    }

    private function getShippingMethod($quote): array
    {
        $countryId = $quote->getShippingAddress()->getCountryId();
        $shippingMethod = array();
        $shippingLine = [
            'price'     => $quote->getBaseShippingAmount() ?: 0,
            'name'      => $quote->getShippingAddress()->getShippingMethod(),
            'countries' => [$countryId]
        ];

        $shippingMethod[] = $shippingLine;

        return $shippingMethod;
    }

    private function getBillingAddress($quote): array
    {
        return [
            'firstName' => $quote->getBillingAddress()->getFirstname(),
            'LastName'  => $quote->getBillingAddress()->getLastname(),
            'line1'     => $quote->getBillingAddress()->getStreet()[0],
            'city'      => $quote->getBillingAddress()->getCity(),
            'zipCode'   => $quote->getBillingAddress()->getPostcode(),
            'country'   => $quote->getBillingAddress()->getCountryId(),
        ];
    }

    private function getPluginVersion(): string {
        $composerJson = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        return 'm2-'.$composerJson['version'];
    }
}
