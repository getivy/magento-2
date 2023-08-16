<?php

namespace Esparksinc\IvyPayment\Model\Api;

use Esparksinc\IvyPayment\Helper\Api as ApiHelper;
use Esparksinc\IvyPayment\Helper\Discount as DiscountHelper;
use Esparksinc\IvyPayment\Helper\Quote as QuoteHelper;
use Esparksinc\IvyPayment\Model\Config;
use Esparksinc\IvyPayment\Model\ErrorResolver;
use Esparksinc\IvyPayment\Model\IvyFactory;
use Esparksinc\IvyPayment\Model\Logger;
use Exception;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Filesystem\Directory\ReadFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Theme\Block\Html\Header\Logo;

class CreateCheckoutSessionService
{
    protected $apiHelper;
    protected $componentRegistrar;
    protected $config;
    protected $discountHelper;
    protected $errorResolver;
    protected $ivy;
    protected $jsonSerializer;
    protected $logger;
    protected $logo;
    protected $quoteRepository;
    protected $quoteHelper;
    protected $readFactory;
    protected $scopeConfig;
    protected $url;

    /**
     * @param ApiHelper $apiHelper
     * @param CartRepositoryInterface $quoteRepository
     * @param QuoteHelper $quoteHelper
     * @param ComponentRegistrar $componentRegistrar
     * @param Config $config
     * @param DiscountHelper $discountHelper
     * @param ErrorResolver $errorResolver
     * @param IvyFactory $ivy
     * @param Json $jsonSerializer
     * @param Logger $logger
     * @param Logo $logo
     * @param ReadFactory $readFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param UrlInterface $url
     */
    public function __construct(
        ApiHelper                   $apiHelper,
        CartRepositoryInterface     $quoteRepository,
        QuoteHelper                 $quoteHelper,
        ComponentRegistrar          $componentRegistrar,
        Config                      $config,
        DiscountHelper              $discountHelper,
        ErrorResolver               $errorResolver,
        IvyFactory                  $ivy,
        Json                        $jsonSerializer,
        Logger                      $logger,
        Logo                        $logo,
        ReadFactory                 $readFactory,
        ScopeConfigInterface        $scopeConfig,
        UrlInterface                $url
    ) {
        $this->apiHelper = $apiHelper;
        $this->componentRegistrar = $componentRegistrar;
        $this->config = $config;
        $this->discountHelper = $discountHelper;
        $this->errorResolver = $errorResolver;
        $this->ivy = $ivy;
        $this->jsonSerializer = $jsonSerializer;
        $this->logger = $logger;
        $this->logo = $logo;
        $this->quoteRepository = $quoteRepository;
        $this->quoteHelper = $quoteHelper;
        $this->readFactory = $readFactory;
        $this->scopeConfig = $scopeConfig;
        $this->url = $url;
    }

    /**
     * @param Quote $quote
     * @param bool $express
     * @param mixed $initiatorName - controller object or custom name the object that called this service
     * @return array
     * @throws Exception
     */
    public function execute(Quote $quote, bool $express, $initiatorName)
    {
        $ivyModel = $this->ivy->create();

        if (!$quote->getReservedOrderId()) {
            $quote->reserveOrderId();
            $ivyModel->setMagentoOrderId($quote->getReservedOrderId());
        }

        $orderId = $quote->getReservedOrderId();

        $quote->collectTotals();

        $this->quoteRepository->save($quote);

        if($express) {
            $phone = ['phone' => true];
            $data = [
                'express' => true,
                'required' => $phone,
            ];
        } else {
            $prefill = ["email" => $quote->getBillingAddress()->getEmail()];
            $shippingMethods = $quote->isVirtual() ? [] : $this->getShippingMethod($quote);
            $billingAddress = $this->getBillingAddress($quote);

            $data = [
                'handshake' => true,
                'shippingMethods' => $shippingMethods,
                'billingAddress' => $billingAddress,
                'prefill' => $prefill,
            ];
        }

        $data = array_merge($data, [
            'referenceId'           => $orderId,
            'category'              => $this->config->getMcc(),
            'price'                 => $this->quoteHelper->getTotalsData($quote, $express),
            'lineItems'             => $this->getLineItems($quote),
            'plugin'                => $this->getPluginVersion(),

            "metadata"              => [
                'quote_id'          => $quote->getId()
            ],

            'successCallbackUrl'    => $this->url->getUrl('ivypayment/success'),
            'errorCallbackUrl'      => $this->url->getUrl('ivypayment/fail'),
            'quoteCallbackUrl'      => $this->url->getUrl('ivypayment/quote'),
            'webhookUrl'            => $this->url->getUrl('ivypayment/webhook'),
            'completeCallbackUrl'   => $this->url->getUrl('ivypayment/order/complete'),
            'shopLogo'              => $this->getLogoSrc(),
        ]);

        $responseData = $this->apiHelper->requestApi($initiatorName, 'checkout/session/create', $data, $orderId,
            function ($exception) use ($quote) {
                $this->errorResolver->tryResolveException($quote, $exception);
            }
        );

        if ($responseData) {
            $ivyModel->setIvyCheckoutSession($responseData['id']);
            $ivyModel->setIvyRedirectUrl($responseData['redirectUrl']);
            $ivyModel->save();
        }

        return $responseData;
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

    protected function getLogoSrc(): string
    {
        $path = $this->scopeConfig->getValue(
            'payment/ivy/frontend_settings/custom_logo',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        return ($path)
            ? $this->url->getBaseUrl(['_type' => UrlInterface::URL_TYPE_MEDIA]) .'ivy/logo/'. $path
            : $this->logo->getLogoSrc();
    }

    private function getPluginVersion(): string
    {
        $path = $this->componentRegistrar->getPath(
            ComponentRegistrar::MODULE,
            'Esparksinc_IvyPayment'
        );
        $directoryRead = $this->readFactory->create($path);
        $composerJsonData = $directoryRead->readFile('composer.json');
        $data = $this->jsonSerializer->unserialize($composerJsonData);

        return !empty($data['version']) ? 'm2-'. $data['version'] : 'unknown';
    }
}
