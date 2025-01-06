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

#[AllowDynamicProperties] #[AsMessageHandler(handles: CustomerGroupSyncTask::class)]
class CustomerGroupSyncTaskHandler extends ScheduledTaskHandler
{
    private const MODULE = '/modify/shopware.customerGroup';

    public function __construct(
        EntityRepository                  $scheduledTaskRepository,
        private readonly PluginConfig     $pluginConfig,
        private readonly EntityRepository $customerGroupRepository,
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
            $customerGroupDataArray = $this->fetchCustomerGroupData($context);
            dd($customerGroupDataArray);
            if ($customerGroupDataArray) {
                foreach ($customerGroupDataArray as $customerGroup) {
                    $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $customerGroup);
                    if ($apiResponseData['result']) {
                        $apiData = $apiResponseData['result'];
                        $customerGroupToUpsert = [];
                        if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                            foreach ($apiData['data'] as $apiItem) {
                                $customerGroupData = $this->buildCustomerGroupData($apiItem);
                                if ($customerGroupData) {
                                    $customerGroupToUpsert[] = $customerGroupData;
                                }
                            }
                        } else {
                            foreach ($apiData['data'] ?? [] as $apiItem) {
                                $customerGroupData = $this->buildCustomerGroupErrorData($apiItem);
                                if ($customerGroupData) {
                                    $customerGroupToUpsert[] = $customerGroupData;
                                }
                            }
                        }
                        if (!empty($customerGroupToUpsert)) {
                            $this->customerGroupRepository->upsert($customerGroupToUpsert, $context);
                        }
                    }
                }
            }
        }
    }

    public function fetchCustomerGroupData($context)
    {
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('languages');
        $criteria->addAssociation('salesChannels');
        return $this->customerGroupRepository->search($criteria, $context)->getElements();
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $customerGroup)
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $customerGroup,
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

    private function buildCustomerGroupData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_customerGroupId'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_customer_group_id' => $apiItem['odoo_shopware_customerGroupId'],
                    'odoo_customer_group_update_time' => date("Y-m-d H:i"),
                ],
            ];
        }
        return null;
    }

    private function buildCustomerGroupErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_customerGroup_error'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_customer_group_error' => $apiItem['odoo_customerGroup_error'],
                ],
            ];
        }
        return null;
    }
}
