<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Esparksinc\IvyPayment\Controller\Quote;

use Esparksinc\IvyPayment\Helper\Discount as DiscountHelper;
use Esparksinc\IvyPayment\Helper\Quote as QuoteHelper;
use Esparksinc\IvyPayment\Model\Config;
use Esparksinc\IvyPayment\Model\Logger;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\QuoteRepository;
use Magento\Quote\Model\ShippingMethodManagement;

class Index extends Action implements CsrfAwareActionInterface
{
    protected $config;
    protected $json;
    protected $jsonFactory;
    protected $scopeConfig;
    protected $quoteRepository;
    protected $regionFactory;
    protected $logger;
    protected $shippingMethodManagement;
    protected $discountHelper;
    protected $quoteHelper;


    /**
     * @param Context $context
     * @param Config $config
     * @param Json $json
     * @param JsonFactory $jsonFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param QuoteRepository $quoteRepository
     * @param RegionFactory $regionFactory
     * @param Logger $logger
     * @param ShippingMethodManagement $shippingMethodManagement
     * @param DiscountHelper $discountHelper
     */
    public function __construct(
        Context                  $context,
        Config                   $config,
        Json                     $json,
        JsonFactory              $jsonFactory,
        ScopeConfigInterface     $scopeConfig,
        QuoteRepository          $quoteRepository,
        RegionFactory            $regionFactory,
        Logger                   $logger,
        ShippingMethodManagement $shippingMethodManagement,
        DiscountHelper           $discountHelper,
        QuoteHelper              $quoteHelper
    ) {
        $this->config = $config;
        $this->json = $json;
        $this->jsonFactory = $jsonFactory;
        $this->scopeConfig = $scopeConfig;
        $this->quoteRepository = $quoteRepository;
        $this->regionFactory = $regionFactory;
        $this->logger = $logger;
        $this->shippingMethodManagement = $shippingMethodManagement;
        $this->discountHelper = $discountHelper;
        $this->quoteHelper = $quoteHelper;

        parent::__construct($context);
    }

    public function execute()
    {
        $request = $this->getRequest();
        $magentoOrderId = $request->getParam('reference');
        $customerData = $this->json->unserialize((string)$request->getContent());

        $this->logger->debugRequest($this, $magentoOrderId);

        $quoteId = $customerData['metadata']['quote_id'] ?? null;
        $quote = $this->quoteHelper->getQuote($magentoOrderId, $quoteId);

        $initialTotalsData = $this->quoteHelper->getTotalsData($quote, true);

        if (!$quote->getCustomerId()) {
            $quote->setCustomerEmail($customerData['shopperEmail']);
            $quote->setCustomerIsGuest(true);
        }

        if (key_exists('discount', $customerData)) {
            $couponCode = $customerData['discount']['voucher'];
            $this->discountHelper->applyCouponCode($couponCode, $quote);
        }

        $data = [];

        if (key_exists('shipping', $customerData)) {
            $customerShippingData = $customerData['shipping']['shippingAddress'];

            $countryId = $customerShippingData['country'];
            $regionCode = $customerShippingData['region'];
            $region = $this->regionFactory->create()->loadByCode($regionCode, $countryId);

            $addressData = [
                'firstname'  => $customerShippingData['firstName'],
                'lastname'   => $customerShippingData['lastName'],
                'street'     => $customerShippingData['line1'],
                'city'       => $customerShippingData['city'],
                'country_id' => $customerShippingData['country'],
                'postcode'   => $customerShippingData['zipCode'],
                'telephone'  => $customerData['shopperPhone'],
                'region_id'  => $region->getId() ?: NULL,
                'region'     => $region->getName() ?: $regionCode
            ];

            $address = $quote->getShippingAddress();
            $address->addData($addressData);
            $address->setCollectShippingRates(true);
            $address->save();

            $shippingMethods = [];

            if ($quote->isVirtual()) {
                // if quote is virtual and shippingMethods is empty, add free shipping with the name per mail as the carrier
                $shippingMethods[] = [
                    'price'     => 0,
                    'name'      => 'E-mail',
                    'countries' => [$customerShippingData['country']],
                    'reference' => 'email'
                ];
            } else {
                /*
                 * This method will trigger correct recollecting. Do not call $address->collectShippingRates() yourself.
                 */
                $estimatedMethods = $this->shippingMethodManagement->estimateByExtendedAddress($quote->getId(), $address);
                /** @var \Magento\Quote\Model\Cart\ShippingMethod $method */
                foreach ($estimatedMethods as $method) {
                    $code = $method->getCarrierCode();
                    $shippingMethods[] = [
                        'price'     => $method->getPriceInclTax(),
                        'name'      => $this->getCarrierName($code),
                        'countries' => [$customerShippingData['country']],
                        'reference' => $method->getCarrierCode() . '_' . $method->getMethodCode()
                    ];
                }
            }

            $data['shippingMethods'] = $shippingMethods;
        }

        $quote->collectTotals();
        $this->quoteRepository->save($quote);

        //Get discount
        $discountAmount = $this->discountHelper->getDiscountAmount($quote);
        if ($discountAmount !== 0.0) {
            $data['discount'] = [
                'amount'    => abs($discountAmount)
            ];
        }

        /*
         * This callback receives shipping address that can lead to recalculate taxes.
         * We should check all price changes on Magento side and send it to Ivy
         */
        $totalsData = $this->quoteHelper->getTotalsData($quote, true);
        if ($totalsData['total'] != $initialTotalsData['total']) {
            $data['price'] = [
                'totalNet'  => $totalsData['totalNet'],
                'vat'       => $totalsData['vat'],
                'total'     => $totalsData['total']
            ];
        }

        $this->logger->debugApiAction($this, $magentoOrderId, 'Quote', $quote->getData());
        $this->logger->debugApiAction($this, $magentoOrderId, 'Response', $data);

        $hash = hash_hmac(
            'sha256',
            json_encode($data, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE),
            $this->config->getWebhookSecret());
        header('X-Ivy-Signature: ' . $hash);
        return $this->jsonFactory->create()->setData($data);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        if ($this->isValidRequest($request)) {
            return true;
        }
        return false;
    }

    private function isValidRequest(RequestInterface $request)
    {
        // return true;
        $hash = hash_hmac(
            'sha256',
            $request->getContent(),
            $this->config->getWebhookSecret());
        if ($request->getHeaders('x-ivy-signature')->getFieldValue() === $hash) {
            return true;
        }

        return false;
    }

    public function getCarrierName($carrierCode)
    {
        if ($name = $this->scopeConfig->getValue(
            "carriers/{$carrierCode}/title",
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        )
        ) {
            return $name;
        }
        return $carrierCode;
    }
}
