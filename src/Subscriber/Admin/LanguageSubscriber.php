<?php

namespace ICTECHOdooShopwareConnector\Subscriber\Admin;

use GuzzleHttp\Client;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
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
                        if ($language) {
                            $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $language);
                            dd($apiResponseData, $odooUrl);
                            if ($apiResponseData['result']) {
                                $apiData = $apiResponseData['result'];
                                $languagesToUpsert = [];
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
                                    $this->languageRepository->upsert($languagesToUpsert, $context);
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
        if (isset($apiItem['id'], $apiItem['odoo_language_error'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_language_error' => $apiItem['odoo_language_error'],
                ],
            ];
        }
        return null;
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
                                        $this->languageRepository->upsert([$languageData], $context);
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

    public function checkApiAuthentication($odooUrl, $odooToken, $language)
    {
        $apiResponse = $this->client->get(
            $odooUrl,
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Access-Token' => $odooToken
                ],
                'json' => $language,
            ]
        );
        return json_decode($apiResponse->getBody()->getContents());
    }
}
