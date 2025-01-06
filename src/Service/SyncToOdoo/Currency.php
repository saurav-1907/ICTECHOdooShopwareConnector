<?php

namespace ICTECHOdooShopwareConnector\Service\SyncToOdoo;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

class Currency
{
    public function __construct(
       private readonly EntityRepository $currencyRepository
    ) {
    }

    public function getCurrencyData($context)
    {
        $country = $this->currencyRepository->search(new Criteria([]), $context);
        dd($country);
    }
}