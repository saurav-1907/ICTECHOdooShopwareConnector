<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Service\SyncToOdoo;

class Product
{
    public function generateProductPayload($productData, $context): array
    {
        dd($productData);
        $productPayload = [
            $productData
        ];
        return $productPayload;
    }

}