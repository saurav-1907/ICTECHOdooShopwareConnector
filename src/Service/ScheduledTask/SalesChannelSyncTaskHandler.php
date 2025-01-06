<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Service\ScheduledTask;

use AllowDynamicProperties;
use Exception;
use GuzzleHttp\Client;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AllowDynamicProperties] #[AsMessageHandler(handles: SalesChannelSyncTask::class)]
class SalesChannelSyncTaskHandler extends ScheduledTaskHandler
{
    private const MODULE = '/modify/shopware.category';

    public function __construct(
        EntityRepository                  $scheduledTaskRepository,
        private readonly PluginConfig     $pluginConfig,
        private readonly EntityRepository $categoryRepository,
    )
    {
        parent::__construct($scheduledTaskRepository);
        $this->client = new Client();
    }

    public function run(): void
    {
        $context = Context::createDefaultContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        if ($odooUrl !== "null" && $odooToken) {
            $categoryDataArray = $this->fetchCategoryData($context);
//            dd($categoryDataArray);
            if ($categoryDataArray) {
                foreach ($categoryDataArray as $category) {
                    $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $category);
                    if ($apiResponseData['result']) {
                        $apiData = $apiResponseData['result'];
                        $categoriesToUpsert = [];
                        if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                            foreach ($apiData['data'] as $apiItem) {
                                $categoryData = $this->buildCategoryData($apiItem);
                                if ($categoryData) {
                                    $categoriesToUpsert[] = $categoryData;
                                }
                            }
                        } else {
                            foreach ($apiData['data'] ?? [] as $apiItem) {
                                $categoryData = $this->buildCategoryErrorData($apiItem);
                                if ($categoryData) {
                                    $categoriesToUpsert[] = $categoryData;
                                }
                            }
                        }
                        if (!empty($categoriesToUpsert)) {
                            $this->categoryRepository->upsert($categoriesToUpsert, $context);
                        }
                    }
                }
            }
        }
    }

    public function fetchCategoryData($context)
    {
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('languages');
        $criteria->addAssociation('navigationSalesChannels');
        $criteria->addAssociation('footerSalesChannels');
        $criteria->addAssociation('serviceSalesChannels');
//        $criteria->addFilter(new EqualsFilter('customFields.odoo_category_id', null));
//        $criteria->addFilter(new NotFilter(
//            MultiFilter::CONNECTION_AND,
//            [new EqualsFilter('customFields.odoo_category_error', null)]
//        ));
        return $this->categoryRepository->search($criteria, $context)->getElements();
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $category)
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $category,
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

    private function buildCategoryData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_categoryId'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_category_id' => $apiItem['odoo_shopware_categoryId'],
                    'odoo_category_update_time' => date("Y-m-d H:i"),
                ],
            ];
        }
        return null;
    }

    private function buildCategoryErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_category_error'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_category_error' => $apiItem['odoo_category_error'],
                ],
            ];
        }
        return null;
    }
}
