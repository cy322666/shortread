<?php

namespace App\Services;

use AmoCRM\Client\AmoCRMApiClient;
use AmoCRM\Exceptions\AmoCRMApiException;
use AmoCRM\Exceptions\AmoCRMMissedTokenException;
use AmoCRM\Exceptions\AmoCRMoAuthApiException;
use AmoCRM\Exceptions\InvalidArgumentException;
use AmoCRM\Filters\CatalogElementsFilter;
use AmoCRM\Models\CatalogElements\CatalogElementModel;

class ProductService
{
    private AmoCRMApiClient $client;
    private int $catalogId = 3059;

    public function __construct(AmoService $amoService)
    {
        $this->client = $amoService->client();
    }

    public function findOrCreate(string $name, array $item)
    {
        $products = $this->client
            ->catalogElements($this->catalogId)
            ->get();

        foreach ($products as $product) {
            if ($product->getName() === $name) {
                return $product->getId();
            }
        }

        return $this->createProduct($name, $item);
    }

    /**
     * @throws InvalidArgumentException
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     * @throws AmoCRMoAuthApiException
     */
    public function findProduct(?string $name, ?string $article)
    {
        if ($article) {

            $filter = new CatalogElementsFilter();

            $filter->setCustomFieldsValues([
                [
                    'field_id' => 348293,
                    'values' => [
                        ['value' => $article]
                    ]
                ]
            ]);

            $products = $this->client
                ->catalogElements($this->catalogId)
                ->get($filter);

            if ($products->count()) {
                return $products->first();
            }
        }

        if ($name) {

            $filter = new CatalogElementsFilter();
            $filter->setNames([$name]);

            $products = $this->client
                ->catalogElements($this->catalogId)
                ->get($filter);

            if ($products->count()) {
                return $products->first();
            }
        }

        return null;
    }

    public function createProduct(string $name, array $item)
    {
        $product = new CatalogElementModel();

        $product->setName($name);

        $product->setCustomFieldsValues([
            [
                'field_id' => 348293, // article
                'values' => [
                    [
                        'value' => $item['article_title'] ?? ''
                    ]
                ]
            ],
            [
                'field_id' => 348297, // price
                'values' => [
                    [
                        'value' => $item['price'] ?? 0
                    ]
                ]
            ]
        ]);

        $result = $this->client
            ->catalogElements($this->catalogId)
            ->addOne($product);

        return $result->getId();
    }
}
