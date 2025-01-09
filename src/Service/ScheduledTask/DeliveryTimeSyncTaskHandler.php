<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Service\ScheduledTask;

use AllowDynamicProperties;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AllowDynamicProperties] #[AsMessageHandler(handles: DeliveryTimeSyncTask::class)]
class DeliveryTimeSyncTaskHandler extends ScheduledTaskHandler
{
    private const MODULE = '/modify/shopware.deliveryTime';

    public function __construct(
        EntityRepository                  $scheduledTaskRepository,
        private readonly PluginConfig     $pluginConfig,
        private readonly EntityRepository $deliveryTimeRepository,
        private readonly LoggerInterface  $logger,
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
            $deliveryTimeDataArray = $this->fetchDeliveryTime($context);
            dd($deliveryTimeDataArray);
            if ($deliveryTimeDataArray) {
                foreach ($deliveryTimeDataArray as $deliveryTime) {
                    $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $deliveryTime);
                    if ($apiResponseData['result']) {
                        $apiData = $apiResponseData['result'];
                        $deliveryTimeToUpsert = [];
                        if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                            foreach ($apiData['data'] as $apiItem) {
                                $deliveryTimeData = $this->buildDeliveryTime($apiItem);
                                if ($deliveryTimeData) {
                                    $deliveryTimeToUpsert[] = $deliveryTimeData;
                                }
                            }
                        } else {
                            foreach ($apiData['data'] ?? [] as $apiItem) {
                                $deliveryTimeData = $this->buildDeliveryTimeErrorData($apiItem);
                                if ($deliveryTimeData) {
                                    $deliveryTimeToUpsert[] = $deliveryTimeData;
                                }
                            }
                        }
                        if (!empty($deliveryTimeToUpsert)) {
                            $this->deliveryTimeRepository->upsert($deliveryTimeToUpsert, $context);
                        }
                    }
                }
            }
        }
    }

    public function fetchDeliveryTime($context): ?array
    {
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('shippingMethods');
//        $criteria->addFilter(new EqualsFilter('customFields.odoo_deliveryTime_id', null));
//        $criteria->addFilter(new NotFilter(
//            MultiFilter::CONNECTION_AND,
//            [new EqualsFilter('customFields.odoo_deliveryTime_error', null)]
//        ));
        return $this->deliveryTimeRepository->search($criteria, $context)->getElements();
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $deliveryTime): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $deliveryTime,
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

    private function buildDeliveryTime($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_deliveryTimeId'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_deliveryTime_id' => $apiItem['odoo_shopware_deliveryTimeId'],
                    'odoo_deliveryTime_update_time' => date("Y-m-d H:i"),
                ],
            ];
        }
        return null;
    }

    private function buildDeliveryTimeErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_deliveryTime_error'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_deliveryTime_error' => $apiItem['odoo_deliveryTime_error'],
                ],
            ];
        }
        return null;
    }
}
