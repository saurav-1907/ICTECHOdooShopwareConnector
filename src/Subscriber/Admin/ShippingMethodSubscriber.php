<?php

namespace ICTECHOdooShopwareConnector\Subscriber\Admin;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Shipping\ShippingEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ShippingMethodSubscriber implements EventSubscriberInterface
{
    private const MODULE = '/modify/shipping.method';

    private const DELETEMODULE = '/delete/shipping.method';

    private static $isProcessingShippingMethodEvent = false;

    public function __construct(
        private readonly PluginConfig     $pluginConfig,
        private readonly EntityRepository $shippingMethodRepository,
        private readonly LoggerInterface  $logger,
    )
    {
        $this->client = new Client();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ShippingEvents::SHIPPING_METHOD_WRITTEN_EVENT => 'onShippingMethodWritten',
            ShippingEvents::SHIPPING_METHOD_DELETED_EVENT => 'onShippingMethodDelete',
        ];
    }

    public function onShippingMethodWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingShippingMethodEvent) {
                return;
            }
            self::$isProcessingShippingMethodEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $shippingMethodId = $writeResult->getPrimaryKey();
                    if ($shippingMethodId) {
                        $shippingMethod = $this->findShippingMethodData($shippingMethodId, $event);
                        dd($shippingMethod);
                        if ($shippingMethod) {
                            $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $shippingMethod);
                            if ($apiResponseData['result']) {
                                $apiData = $apiResponseData['result'];
                                $shippingMethodToUpsert = [];
                                if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                    foreach ($apiData['data'] as $apiItem) {
                                        $shippingMethodData = $this->buildShippingMethodData($apiItem);
                                        if ($shippingMethodData) {
                                            $shippingMethodToUpsert[] = $shippingMethodData;
                                        }
                                    }
                                } else {
                                    foreach ($apiData['data'] ?? [] as $apiItem) {
                                        $shippingMethodData = $this->buildShippingMethodErrorData($apiItem);
                                        if ($shippingMethodData) {
                                            $shippingMethodToUpsert[] = $shippingMethodData;
                                        }
                                    }
                                }
                                if (!empty($shippingMethodToUpsert)) {
                                    $this->shippingMethodRepository->upsert($shippingMethodToUpsert, $context);
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingShippingMethodEvent = false;
            }
        }
    }

    public function findShippingMethodData($shippingMethodId, $event): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('languages');
        $criteria->addAssociation('prices');
        $criteria->addAssociation('media');
        $criteria->addAssociation('salesChannels');
        $criteria->addFilter(new EqualsFilter('id', $shippingMethodId));
        return $this->shippingMethodRepository->search($criteria, $event->getContext())->first();
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $shippingMethod): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $shippingMethod,
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

    private function buildShippingMethodData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_shippingMethodId'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_shipping_method_id' => $apiItem['odoo_shopware_shippingMethodId'],
                    'odoo_shipping_method_update_time' => date("Y-m-d H:i"),
                ],
            ];
        }
        return null;
    }

    private function buildShippingMethodErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shippingMethod_error'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_shipping_method_error' => $apiItem['odoo_shippingMethod_error'],
                ],
            ];
        }
        return null;
    }

    public function onShippingMethodDelete(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::DELETEMODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingShippingMethodEvent) {
                return;
            }
            self::$isProcessingShippingMethodEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $shippingMethodId = $writeResult->getPrimaryKey();
                    if ($shippingMethodId) {
                        $deleteShippingMethodData = [
                            'shopwareId' => $shippingMethodId,
                            'operation' => $writeResult->getOperation(),
                        ];
                        $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $deleteShippingMethodData);
                        if ($apiResponseData['result']) {
                            $apiData = $apiResponseData['result'];
                            if (!$apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                foreach ($apiData['data'] as $apiItem) {
                                    $shippingMethodData = $this->buildShippingMethodErrorData($apiItem);
                                    if ($shippingMethodData) {
                                        $this->shippingMethodRepository->upsert([$shippingMethodData], $context);
                                    }
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingShippingMethodEvent = false;
            }
        }
    }
}
