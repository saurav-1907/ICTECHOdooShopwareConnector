<?php

namespace ICTECHOdooShopwareConnector\Subscriber\Admin;

use Exception;
use GuzzleHttp\Client;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Tax\TaxEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class TaxSubscriber implements EventSubscriberInterface
{
    private const MODULE = '/modify/shopware.tax';
    private const DELETEMODULE = '/delete/shopware.tax';
    private static $isProcessingTaxEvent = false;

    public function __construct(
        private readonly PluginConfig     $pluginConfig,
        private readonly EntityRepository $taxRepository,
    )
    {
        $this->client = new Client();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TaxEvents::TAX_WRITTEN_EVENT => 'onTaxWritten',
            TaxEvents::TAX_DELETED_EVENT => 'onTaxDelete',
        ];
    }

    public function onTaxWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingTaxEvent) {
                return;
            }
            self::$isProcessingTaxEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $taxId = $writeResult->getPrimaryKey();
                    if ($taxId) {
                        $taxDataArray = $this->findTaxData($taxId, $event);
                        if ($taxDataArray) {
                            $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $taxDataArray);
                            if ($apiResponseData['result']) {
                                $apiData = $apiResponseData['result'];
                                $taxToUpsert = [];
                                if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                    foreach ($apiData['data'] as $apiItem) {
                                        $taxData = $this->buildTaxMethodData($apiItem);
                                        if ($taxData) {
                                            $taxToUpsert[] = $taxData;
                                        }
                                    }
                                } else {
                                    foreach ($apiData['data'] ?? [] as $apiItem) {
                                        $taxData = $this->buildTaxErrorData($apiItem);
                                        if ($taxData) {
                                            $taxToUpsert[] = $taxData;
                                        }
                                    }
                                }
                                if (!empty($taxToUpsert)) {
                                    $this->taxRepository->upsert($taxToUpsert, $context);
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingTaxEvent = false;
            }
        }
    }

    public function findTaxData($taxId, $event): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('products');
        $criteria->addAssociation('rules');
        $criteria->addAssociation('rules.country');
        $criteria->addAssociation('shippingMethods');
        $criteria->addFilter(new EqualsFilter('id', $taxId));
        return $this->taxRepository->search($criteria, $event->getContext())->first();
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $taxDataArray)
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $taxDataArray,
                ]
            );
            return json_decode($apiResponseData->getBody()->getContents(), true);
        } catch (Exception $e) {
            return [
                'result' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function buildTaxMethodData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_taxId'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_tax_id' => $apiItem['odoo_shopware_taxId'],
                    'odoo_tax_update_time' => date("Y-m-d H:i"),
                ],
            ];
        }
        return null;
    }

    private function buildTaxErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_tax_error'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_tax_error' => $apiItem['odoo_tax_error'],
                ],
            ];
        }
        return null;
    }

    public function onTaxDelete(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::DELETEMODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingTaxEvent) {
                return;
            }
            self::$isProcessingTaxEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $taxId = $writeResult->getPrimaryKey();
                    if ($taxId) {
                        $deleteTaxData = [
                            'shopwareId' => $taxId,
                            'operation' => $writeResult->getOperation(),
                        ];
                        $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $deleteTaxData);
                        if ($apiResponseData['result']) {
                            $apiData = $apiResponseData['result'];
                            if (!$apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                foreach ($apiData['data'] as $apiItem) {
                                    $taxData = $this->buildTaxErrorData($apiItem);
                                    if ($taxData) {
                                        $this->taxRepository->upsert([$taxData], $context);
                                    }
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingTaxEvent = false;
            }
        }
    }
}
