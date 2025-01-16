<?php

namespace ICTECHOdooShopwareConnector\Subscriber\Admin;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Language\LanguageEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class LanguageSubscriber implements EventSubscriberInterface
{
    private const MODULE = '/modify/shopware.language';

    private const DELETEMODULE = '/delete/shopware.language';

    private static $isProcessingLanguage = false;

    public function __construct(
        private readonly PluginConfig     $pluginConfig,
        private readonly EntityRepository $languageRepository,
        private readonly LoggerInterface  $logger,
    )
    {
        $this->client = new Client();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LanguageEvents::LANGUAGE_WRITTEN_EVENT => 'onLanguageWritten',
            LanguageEvents::LANGUAGE_DELETED_EVENT => 'onLanguageDelete',
        ];
    }

    public function onLanguageWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingLanguage) {
                return;
            }
            self::$isProcessingLanguage = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $languageId = $writeResult->getPrimaryKey();
                    if ($languageId) {
                        $updateDataLanguageId = $event->getContext()->getLanguageId();
                        $language = $this->findLanguageData($languageId, $updateDataLanguageId, $event);
                        $languagesToUpsert = [];
                        if ($language) {
                            $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $language);
                            if ($apiResponseData['result']) {
                                $apiData = $apiResponseData['result'];
                                if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                    foreach ($apiData['data'] as $apiItem) {
                                        $languageData = $this->buildLanguageData($apiItem);
                                        if ($languageData) {
                                            $languagesToUpsert[] = $languageData;
                                        }
                                    }
                                } else {
                                    foreach ($apiData['data'] ?? [] as $apiItem) {
                                        $languageData = $this->buildLanguageErrorData($apiItem);
                                        if ($languageData) {
                                            $languagesToUpsert[] = $languageData;
                                        }
                                    }
                                }
                                if (!empty($languagesToUpsert)) {
                                    try {
                                      $sk =   $this->languageRepository->upsert($languagesToUpsert, $context);
                                      dd($sk);
                                    } catch (\Exception $e) {
                                        $this->logger->error('Error in language sync real-time', [
                                            'exception' => $e,
                                            'data' => $languagesToUpsert,
                                            'apiResponse' => $apiData,
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingLanguage = false;
            }
        }
    }

    public function findLanguageData($languageId, $updateDataLanguageId, $event): ?array
    {
        $criteria = new Criteria();
        $criteria->addAssociation('locale');
        $criteria->addAssociation('translationCode');
        $criteria->addFilter(new EqualsFilter('id', $languageId));
        $language = $this->languageRepository->search($criteria, $event->getContext())->first();

        if ($language) {
            $customFields = $language->getCustomFields() ?? [];
            if ($language->getLocale()->getName()) {
                $localeName = $language->getLocale()->getName() . ' ' . ($language->getLocale()->getTerritory() ?: '');
            } else {
                $localeName = $language->getLocale()->getTerritory() ?: '';
            }
            return [
                'name' => $language->getTranslated()['name'] ?? $language->getName(),
                'locale' => $localeName,
                'updateDataLanguageId' => $updateDataLanguageId,
                'languageCode' => $language->getTranslationCode()->getCode(),
                'shopwareLanguageId' => $language->getId(),
                'odooId' => $customFields['odoo_language_id'] ?? '',
            ];
        }
        return null;
    }

    public function checkApiAuthentication($odooUrl, $odooToken, $language)
    {
        try {
            $apiResponseData = $this->client->post(
                $odooUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken
                    ],
                    'json' => $language,
                ]
            );
            return json_decode($apiResponseData->getBody()->getContents(), true);
        } catch (RequestException $e) {
            $this->logger->error('API request failed', [
                'exception' => $e,
                'apiUrl' => $odooUrl,
                'odooToken' => $odooToken,
            ]);
            return [
                'result' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function buildLanguageData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_languageId'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_language_id' => $apiItem['odoo_shopware_languageId'],
                    'odoo_language_update_time' => date("Y-m-d H:i"),
                ],
            ];
        }
        return null;
    }

    private function buildLanguageErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_language_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }

    public function onLanguageDelete(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::DELETEMODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingLanguage) {
                return;
            }
            self::$isProcessingLanguage = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $languageId = $writeResult->getPrimaryKey();
                    if ($languageId) {
                        $deleteLanguageData = [
                            'shopwareId' => $languageId,
                            'operation' => $writeResult->getOperation(),
                        ];
                        $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $deleteLanguageData);
                        if ($apiResponseData['result']) {
                            $apiData = $apiResponseData['result'];
                            if (!$apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                                foreach ($apiData['data'] as $apiItem) {
                                    $languageData = $this->buildLanguageErrorData($apiItem);
                                    if ($languageData) {
                                        try {
                                            $this->languageRepository->upsert([$languageData], $context);
                                        } catch (\Exception $e) {
                                            $this->logger->error('Error in language delete', [
                                                'exception' => $e,
                                                'data' => $deleteLanguageData,
                                                'apiResponse' => $apiData,
                                            ]);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            } finally {
                self::$isProcessingLanguage = false;
            }
        }
    }
}
