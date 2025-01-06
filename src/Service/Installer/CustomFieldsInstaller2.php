<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Service\Installer;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\CustomFieldTypes;

class CustomFieldsInstaller
{
    private const ID = 'Id';
    private const ERROR = 'Error';
    private const ODOO_SHOPWARE_PRODUCT = 'odoo_shopware_product';
    private const ODOO_SHOPWARE_CATEGORY = 'odoo_shopware_category';
    private const ODOO_SHOPWARE_MANUFACTURER = 'odoo_shopware_manufacturer';
    private const ODOO_SHOPWARE_CUSTOMER = 'odoo_shopware_customer';
    private const ODOO_SHOPWARE_ORDER = 'odoo_shopware_order';
    private const CUSTOM_FIELDSET_NAME = [
        'odoo_shopware_product',
        'odoo_shopware_category',
        'odoo_shopware_manufacturer',
        'odoo_shopware_customer',
        'odoo_shopware_order',
    ];
    private const CUSTOM_FIELDSET = [
        [
            'name' => self::ODOO_SHOPWARE_PRODUCT,
            'position' => 1,
            'config' => [
                'label' => [
                    'de-DE' => 'Odoo Produkt',
                    'en-GB' => 'Odoo Product'
                ],
                'translated' => true,
            ],
            'customFields' => [
                [
                    'name' => self::ODOO_SHOPWARE_PRODUCT . self::ID,
                    'type' => CustomFieldTypes::NUMBER,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Product Id',
                            'de-DE' => 'Odoo Produkt Id',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Product Id'
                        ],
                        'customFieldType' => 'number',
                        'customFieldPosition' => 1
                    ],
                ],
                [
                    'name' => self::ODOO_SHOPWARE_PRODUCT . self::ERROR,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Product Error',
                            'de-DE' => 'Odoo-Produktfehler',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Product Error'
                        ],
                        'customFieldType' => 'text',
                        'customFieldPosition' => 2
                    ]
                ],
            ],
            'relations' => [
                [
                    'entityName' => 'product',
                ],
            ],
        ],
        [
            'name' => self::ODOO_SHOPWARE_CATEGORY,
            'position' => 1,
            'config' => [
                'label' => [
                    'de-DE' => 'Odoo Kategorie',
                    'en-GB' => 'Odoo Category'
                ],
                'translated' => true,
            ],
            'customFields' => [
                [
                    'name' => self::ODOO_SHOPWARE_CATEGORY . self::ID,
                    'type' => CustomFieldTypes::INT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Category Id',
                            'de-DE' => 'Odoo Kategorie Id',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Category Id'
                        ],
                        'customFieldType' => 'int',
                        'customFieldPosition' => 1
                    ]
                ],
                [
                    'name' => self::ODOO_SHOPWARE_CATEGORY . self::ERROR,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Category Error',
                            'de-DE' => 'Odoo-Kategoriefeler',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Category Error'
                        ],
                        'customFieldType' => 'text',
                        'customFieldPosition' => 2
                    ]
                ],
            ],
            'relations' => [
                [
                    'entityName' => 'category',
                ],
            ],
        ],
        [
            'name' => self::ODOO_SHOPWARE_MANUFACTURER,
            'position' => 1,
            'config' => [
                'label' => [
                    'de-DE' => 'Odoo Hersteller',
                    'en-GB' => 'Odoo Manufacturer'
                ],
                'translated' => true,
            ],
            'customFields' => [
                [
                    'name' => self::ODOO_SHOPWARE_MANUFACTURER . self::ID,
                    'type' => CustomFieldTypes::INT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Manufacturer Id',
                            'de-DE' => 'Odoo Hersteller Id',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Manufacturer Id'
                        ],
                        'customFieldType' => 'int',
                        'customFieldPosition' => 1
                    ]
                ],
                [
                    'name' => self::ODOO_SHOPWARE_MANUFACTURER . self::ERROR,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Manufacturer Error',
                            'de-DE' => 'Odoo-Herstellungsfehler',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Manufacturer Error'
                        ],
                        'customFieldType' => 'text',
                        'customFieldPosition' => 2
                    ]
                ],
            ],
            'relations' => [
                [
                    'entityName' => 'product_manufacturer',
                ],
            ],
        ],
        [
            'name' => self::ODOO_SHOPWARE_CUSTOMER,
            'position' => 1,
            'config' => [
                'label' => [
                    'de-DE' => 'Odoo Kunde',
                    'en-GB' => 'Odoo Customer'
                ],
                'translated' => true,
            ],
            'customFields' => [
                [
                    'name' => self::ODOO_SHOPWARE_CUSTOMER . self::ID,
                    'type' => CustomFieldTypes::INT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Customer Id',
                            'de-DE' => 'Odoo Kunden Id',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Customer Id'
                        ],
                        'customFieldType' => 'int',
                        'customFieldPosition' => 1
                    ],
                    'allowCustomerWrite' => true
                ],
                [
                    'name' => self::ODOO_SHOPWARE_CUSTOMER . self::ERROR,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Customer Error',
                            'de-DE' => 'Odoo Kundenfehler',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Customer Error'
                        ],
                        'customFieldType' => 'text',
                        'customFieldPosition' => 2
                    ],
                    'allowCustomerWrite' => true
                ],
            ],
            'relations' => [
                [
                    'entityName' => 'customer',
                ],
            ],
        ],
        [
            'name' => self::ODOO_SHOPWARE_ORDER,
            'position' => 1,
            'config' => [
                'label' => [
                    'de-DE' => 'Odoo Bestellung',
                    'en-GB' => 'Odoo Order'
                ],
                'translated' => true,
            ],
            'customFields' => [
                [
                    'name' => self::ODOO_SHOPWARE_ORDER . self::ID,
                    'type' => CustomFieldTypes::NUMBER,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Order Id',
                            'de-DE' => 'Odoo Bestell-ID',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Order Id'
                        ],
                        'customFieldType' => 'number',
                        'customFieldPosition' => 1
                    ],
                    'allowCustomerWrite' => true
                ],
                [
                    'name' => self::ODOO_SHOPWARE_ORDER . self::ERROR,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Order Error',
                            'de-DE' => 'Odoo Bestellfehler',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Order Error'
                        ],
                        'customFieldType' => 'text',
                        'customFieldPosition' => 2
                    ],
                    'allowCustomerWrite' => true
                ],
            ],
            'relations' => [
                [
                    'entityName' => 'order',
                ],
            ],
        ],
    ];

    public function __construct(
        private readonly EntityRepository $customFieldSetRepository,
    ) {
    }

    public function install(Context $context): void
    {
        $customFieldCount = count(self::CUSTOM_FIELDSET_NAME);
        for ($i = 0; $i < $customFieldCount; $i++) {
            $name = self::CUSTOM_FIELDSET_NAME[$i];
            $getReferralCustomFieldSet = $this->getCustomFieldSetIds($name, $context);
            if (!$getReferralCustomFieldSet) {
                $this->customFieldSetRepository->upsert([
                    self::CUSTOM_FIELDSET[$i]
                ], $context);
            }
        }
    }

    private function getCustomFieldSetIds($name, $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $name));
        return $this->customFieldSetRepository->searchIds($criteria, $context)->getIds();
    }

    public function uninstall(Context $context): void
    {
        $customFieldCount = count(self::CUSTOM_FIELDSET_NAME);
        for ($i = 0; $i < $customFieldCount; $i++) {
            $name = self::CUSTOM_FIELDSET_NAME[$i];
            $getReferralCustomFieldSet = $this->getCustomFieldSetIds($name, $context);
            if ($getReferralCustomFieldSet) {
                $this->customFieldSetRepository->delete([
                    ['id' => $getReferralCustomFieldSet[0]]
                ], $context);
            }
        }
    }
}