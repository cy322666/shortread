<?php

namespace App\Services;

use AmoCRM\Client\AmoCRMApiClient;
use AmoCRM\Collections\CustomFieldsValuesCollection;
use AmoCRM\Exceptions\AmoCRMApiException;
use AmoCRM\Exceptions\AmoCRMApiNoContentException;
use AmoCRM\Exceptions\AmoCRMMissedTokenException;
use AmoCRM\Exceptions\AmoCRMoAuthApiException;
use AmoCRM\Exceptions\InvalidArgumentException;
use AmoCRM\Filters\CatalogElementsFilter;
use AmoCRM\Models\CatalogElementModel;
use AmoCRM\Models\CustomFieldsValues\PriceCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\TextCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\PriceCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\TextCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueModels\NumericCustomFieldValueModel;
use AmoCRM\Models\CustomFieldsValues\ValueModels\TextCustomFieldValueModel;
use App\Support\CrmSchema;

class ProductService
{
    private AmoCRMApiClient $client;
    private int $catalogId = CrmSchema::FIELDS['catalog']['id'];

    public function __construct(AmoService $amoService)
    {
        $this->client = $amoService->client();
    }

    /**
     * @throws InvalidArgumentException
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     * @throws AmoCRMoAuthApiException
     */
    public function findOrCreate(string $name, array $item): CatalogElementModel
    {
        try {
            $products = $this->client
                ->catalogElements($this->catalogId)
                ->get();

            foreach ($products as $product) {

                if ($product->getName() === $name)

                    return $product;
            }

        } catch (AmoCRMApiNoContentException) {}

        return $this->createProduct($name, $item);
    }

    /**
     * @throws InvalidArgumentException
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     * @throws AmoCRMoAuthApiException
     */
    public function findProduct(string $name): ?CatalogElementModel
    {
        if ($name) {

            $filter = new CatalogElementsFilter();
            $filter->setQuery($name);

            try {

                $products = $this->client
                    ->catalogElements($this->catalogId)
                    ->get($filter);

                return $products->first();

            } catch (AmoCRMApiNoContentException $e) {

                return null;
            }
        }
    }

    /**
     * @throws InvalidArgumentException
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     * @throws AmoCRMoAuthApiException
     */
    public function createProduct(string $name, array $item): CatalogElementModel
    {
        $product = new CatalogElementModel();

        $product->setName($name);
        $product->setCustomFieldsValues($this->buildProductCustomFields($item));

        return $this->client
            ->catalogElements($this->catalogId)
            ->addOne($product);
    }

    protected function buildProductCustomFields(array $item): CustomFieldsValuesCollection
    {
        $collection = new CustomFieldsValuesCollection();

//        $articleField = (new TextCustomFieldValuesModel())
//            ->setFieldId(CrmSchema::FIELDS['catalog']['fields']['article']['id'])
//            ->setValues(
//                (new TextCustomFieldValueCollection())
//                    ->add((new TextCustomFieldValueModel())->setValue((string) ($item['article_title'] ?? '')))
//            );

        $priceField = (new PriceCustomFieldValuesModel())
            ->setFieldId(CrmSchema::FIELDS['catalog']['fields']['price']['id'])
            ->setValues(
                (new PriceCustomFieldValueCollection())
                    ->add((new NumericCustomFieldValueModel())->setValue((float) ($item['price'] ?? 0)))
            );

//        $collection->add($articleField);
        $collection->add($priceField);

        return $collection;
    }
}
