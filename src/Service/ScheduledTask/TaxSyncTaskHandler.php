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

#[AllowDynamicProperties] #[AsMessageHandler(handles: TaxSyncTask::class)]
class TaxSyncTaskHandler extends ScheduledTaskHandler
{
    private const MODULE = '/modify/account.tax';

    public function __construct(
        EntityRepository                  $scheduledTaskRepository,
        private readonly PluginConfig     $pluginConfig,
        private readonly EntityRepository $taxRepository,
    ) {
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
            $taxDataArray = $this->fetchTaxMethodData($context);
            if ($taxDataArray) {
                $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $taxDataArray);
                dd($apiResponseData);
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
                            $taxData = $this->buildTaxMethodErrorData($apiItem);
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

    public function fetchTaxMethodData($context)
    {
        $criteria = new Criteria();
        $criteria->addAssociation('translations');
        $criteria->addAssociation('prices');
        $criteria->addAssociation('media');
        $criteria->addAssociation('salesChannels');
        return $this->taxRepository->search($criteria, $context)->getElements();
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
                    'odoo_tax_method' => $apiItem['odoo_shopware_taxId'],
                    'odoo_tax_method_update_time' => date("Y-m-d H:i"),
                ],
            ];
        }
        return null;
    }

    private function buildTaxMethodErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_tax_error'])) {
            return [
                "id" => $apiItem['id'],
                'customFields' => [
                    'odoo_tax_method_error' => $apiItem['odoo_tax_error'],
                ],
            ];
        }
        return null;
    }
}
