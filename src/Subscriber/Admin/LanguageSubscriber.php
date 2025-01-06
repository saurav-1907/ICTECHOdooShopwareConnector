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
    private static $isProcessingCategoryWrittenEvent = false;

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
        $odooUrl = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooToken = $this->pluginConfig->getOdooAccessToken($context);
        if ($odooUrl !== "null" && $odooToken) {
            if (self::$isProcessingCategoryWrittenEvent) {
                return;
            }
            self::$isProcessingCategoryWrittenEvent = true;
            try {
                foreach ($event->getWriteResults() as $writeResult) {
                    $languageId = $writeResult->getPrimaryKey();
                    if ($languageId) {
                        $updateDataLanguageId = $event->getContext()->getLanguageId();
                        $language = $this->findLanguageData($languageId, $updateDataLanguageId, $event);
                        if ($language) {
                            $language['operation'] = $writeResult->getOperation();
                            $languageProcessed = [
                                'language_data' => $language
                            ];
                            $json = json_encode($languageProcessed, JSON_PRETTY_PRINT);
                        }
                    }
                }
                dd($json);
            } finally {
                self::$isProcessingCategoryWrittenEvent = false;
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


    public function onLanguageDelete(EntityWrittenEvent $event): void
    {
        $languageProcessed = [];
        foreach ($event->getWriteResults() as $writeResult) {
            $languageId = $writeResult->getPrimaryKey();
            if ($languageId) {
                $language = [
                    'shopwareLanguageId' => $languageId,
                    'operation' => $writeResult->getOperation(),
                ];
                $languageProcessed = [
                    'language_data' => $language
                ];
                $json = json_encode($languageProcessed, JSON_PRETTY_PRINT);
            }
            dd($json);
        }
    }

    public function checkApiAuthentication($apiUrl, $odooToken)
    {
        $apiResponse = $this->client->get(
            $apiUrl,
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Access-Token' => $odooToken
                ],
            ]
        );
        return json_decode($apiResponse->getBody()->getContents());
    }
}
