<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Subscriber\Admin;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductSubscriber implements EventSubscriberInterface
{

    private const MODULE = '/modify/shopware.product';

    private const DELETEMODULE = '/delete/shopware.product';

    private static $isProcessingProductEvent = false;

    public function __construct(
        private readonly PluginConfig     $pluginConfig,
        private readonly EntityRepository $productRepository,
        private readonly LoggerInterface  $logger,
    )
    {
        $this->client = new Client();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductEvents::PRODUCT_WRITTEN_EVENT => 'onWrittenProductEvent',
            ProductEvents::PRODUCT_DELETED_EVENT => 'onDeleteProductEvent',
        ];
    }

    public function onWrittenProductEvent(EntityWrittenEvent $event)
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingProductEvent) {
                return;
            }
            self::$isProcessingProductEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $productId = $writeResult->getPrimaryKey();
                    if ($productId) {
                        $product = $this->findProductData($productId, $event);
                        if ($product) {
                            $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $product);
                            if ($apiResponseData['result']) {
                                $apiData = $apiResponseData['result'];
                                $productToUpsert = [];
                                if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                    foreach ($apiData['data'] as $apiItem) {
                                        $productData = $this->buildProductData($apiItem);
                                        if ($productData) {
                                            $productToUpsert[] = $productData;
                                        }
                                    }
                                } else {
                                    foreach ($apiData['data'] ?? [] as $apiItem) {
                                        $productData = $this->buildProductErrorData($apiItem);
                                        if ($productData) {
                                            $productToUpsert[] = $productData;
                                        }
                                    }
                                }
                                if (!empty($productToUpsert)) {
                                    $this->productRepository->upsert($productToUpsert, $context);
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingProductEvent = false;
            }
        }
    }

    public function findProductData($productId, $event): ?Entity
    {
        $criteria = new Criteria();
        $event->getContext()->setConsiderInheritance(true);
        $criteria->addAssociation('translations');
        $criteria->addAssociation('cover');
        $criteria->addAssociation('media');
        $criteria->addAssociation('visibilities');
        $criteria->addAssociation('visibilities.salesChannel');
        $criteria->addAssociation('configuratorSettings.option');
        $criteria->addAssociation('manufacturer');
        $criteria->addAssociation('manufacturer.translations');
        $criteria->addAssociation('prices');
        $criteria->addAssociation('prices.rule');
        $criteria->addAssociation('tax');
        $criteria->addAssociation('tax.rules');
        $criteria->addAssociation('searchKeywords');
        $criteria->addAssociation('customFields');
        $criteria->addAssociation('unit');
        $criteria->addAssociation('deliveryTime');
        $criteria->addAssociation('deliveryTime.translations');
        $criteria->addAssociation('tags');
        $criteria->addAssociation('properties');
        $criteria->addAssociation('properties.translations');
        $criteria->addAssociation('properties.group');
        $criteria->addAssociation('properties.group.translations');
        $criteria->addAssociation('options');
        $criteria->addAssociation('options.translations');
        $criteria->addAssociation('options.group');
        $criteria->addAssociation('options.group.translations');
        $criteria->addAssociation('categories');
        $criteria->addAssociation('categories.translations');
        $criteria->addAssociation('categoriesRo');
        $criteria->addAssociation('categoriesRo.translations');
        $criteria->addAssociation('mainCategories');
        $criteria->addAssociation('streams');
        $criteria->addAssociation('streams.categories');
        $criteria->addAssociation('streams.categories.translations');
        $criteria->addAssociation('configuratorSettings');
        $criteria->addAssociation('children');
        $criteria->addAssociation('children.media');
        $criteria->addAssociation('children.cover');
        $criteria->addAssociation('children.translations');
        $criteria->addAssociation('children.configuratorSettings');
        $criteria->addAssociation('price');
        $criteria->addAssociation('crossSellings');
        $criteria->addAssociation('crossSellingAssignedProducts');
        $criteria->addAssociation('productReviews');
        $criteria->addAssociation('seoUrls');
        $criteria->addAssociation('wishlists');
        $criteria->addAssociation('customFieldSets');
        $criteria->addFilter(new EqualsFilter('id', $productId));
        return $this->productRepository->search($criteria, $event->getContext())->first();
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $product): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $product,
                ]
            );
            return json_decode($apiResponseData->getBody()->getContents(), true);
        } catch (RequestException $e) {
            $this->logger->error('API request failed', [
                'exception' => $e,
                'apiUrl' => $apiUrl,
                'odooToken' => $odooToken,
            ]);
            return [
                'result' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function buildProductData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_product_id'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_product_id' => $apiItem['odoo_product_id'],
                    'odoo_product_update_time' => date("Y-m-d H:i"),
                ],
            ];
        }
        return null;
    }

    private function buildProductErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_product_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }

    public function onDeleteProductEvent(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::DELETEMODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingProductEvent) {
                return;
            }
            self::$isProcessingProductEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $productId = $writeResult->getPrimaryKey();
                    if ($productId) {
                        $deleteProductData = [
                            'shopwareId' => $productId,
                            'operation' => $writeResult->getOperation(),
                        ];
                        $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $deleteProductData);
                        if ($apiResponseData['result']) {
                            $apiData = $apiResponseData['result'];
                            if (!$apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                foreach ($apiData['data'] as $apiItem) {
                                    $productData = $this->buildProductErrorData($apiItem);
                                    if ($productData) {
                                        $this->productRepository->upsert([$productData], $context);
                                    }
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingProductEvent = false;
            }
        }
    }
}