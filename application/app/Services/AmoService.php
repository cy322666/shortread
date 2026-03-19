<?php

namespace App\Services;

use AmoCRM\Client\AmoCRMApiClient;
use AmoCRM\Client\LongLivedAccessToken;
use AmoCRM\Exceptions\AmoCRMApiException;
use AmoCRM\Exceptions\AmoCRMApiNoContentException;
use AmoCRM\Exceptions\AmoCRMMissedTokenException;
use AmoCRM\Exceptions\AmoCRMoAuthApiException;
use AmoCRM\Exceptions\InvalidArgumentException;
use AmoCRM\Collections\ContactsCollection;
use AmoCRM\Collections\CustomFieldsValuesCollection;
use AmoCRM\Models\CustomFieldsValues\BaseCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\DateCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\NumericCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\TextCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\DateCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\NumericCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\TextCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueModels\DateCustomFieldValueModel;
use AmoCRM\Models\CustomFieldsValues\ValueModels\NumericCustomFieldValueModel;
use AmoCRM\Models\CustomFieldsValues\ValueModels\TextCustomFieldValueModel;
use AmoCRM\Filters\CompaniesFilter;
use AmoCRM\Filters\ContactsFilter;
use AmoCRM\Filters\LeadsFilter;
use AmoCRM\Collections\LinksCollection;
use AmoCRM\Models\CompanyModel;
use AmoCRM\Models\ContactModel;
use AmoCRM\Models\LeadModel;
use AmoCRM\Models\LinkModel;
use App\Support\CrmSchema;
use Illuminate\Support\Facades\Log;

class AmoService
{
    protected AmoCRMApiClient $client;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct()
    {
        $subdomain = env('AMO_SUBDOMAIN');
        $clientId = env('AMO_CLIENT_ID');
        $clientSecret = env('AMO_SECRET');
        $redirectUri = env('AMO_REDIRECT');
        $rawToken = (string) env('AMO_TOKEN', '');
        $normalizedToken = preg_replace('/^Bearer\s+/i', '', trim($rawToken));
        $accessToken = new LongLivedAccessToken($normalizedToken);

        $this->client = new AmoCRMApiClient($clientId, $clientSecret, $redirectUri);
        if (!empty($subdomain)) {
            $baseDomain = str_contains($subdomain, '.') ? $subdomain : ($subdomain . '.amocrm.ru');
            $this->client->setAccountBaseDomain($baseDomain);
        }
        $this->client->setAccessToken($accessToken);
    }

    public function client(): AmoCRMApiClient
    {
        return $this->client;
    }

    public function searchOrCreate(string $email): ?ContactModel
    {
        $contact = $this->getContactByEmail($email);

        return $contact ? $contact : $this->createContact();
    }


    public function getContactByEmail(string $email): null|ContactModel
    {
        try {
            $filter = new ContactsFilter();
            $filter->setQuery($email);

            return $this->client
                ->contacts()
                ->get($filter)
                ?->first();

        } catch (AmoCRMApiNoContentException $e) {

            return null;
        } catch (AmoCRMoAuthApiException $e) {
        } catch (AmoCRMApiException $e) {

            Log::error("AmoCRM createContact error: " . $e->getMessage(), [
                'code' => $e->getCode(),
                'description' => $e->getDescription(),
                'last_request' => $e->getLastRequestInfo(),
            ]);
        }
    }

    /**
     * @throws AmoCRMApiException
     * @throws AmoCRMoAuthApiException
     * @throws AmoCRMMissedTokenException
     */
    public function updateContact(ContactModel $contact): ContactModel
    {
        if (!$contact->getId()) {
            throw new InvalidArgumentException('Can not update contact without id');
        }

        return $this->client->contacts()->updateOne($contact);
    }

    public function createContact(): ?ContactModel
    {
        try {

            $contact = new ContactModel();
            $contact->setName('Новый контакт');

            return $this->client->contacts()->addOne($contact);

        } catch (AmoCRMApiException $e) {

            Log::error("AmoCRM createContact error: " . $e->getMessage(), [
                'code' => $e->getCode(),
                'description' => $e->getDescription(),
                'last_request' => $e->getLastRequestInfo(),
            ]);

            return null;
        }
    }

    public function getCompanyByINN(string $inn): ?\AmoCRM\Models\CompanyModel
    {
        try {
            $innFieldId = CrmSchema::FIELDS['company']['inn']['id'];

            $filter = new CompaniesFilter();
            $filter->setQuery($inn);

            $companies = $this->client->companies()->get($filter);

            foreach ($companies ?? [] as $company) {
                foreach ($company->getCustomFieldsValues() ?? [] as $field) {
                    if ((int)$field->getFieldId() !== (int)$innFieldId) {
                        continue;
                    }

                    foreach ($field->getValues() ?? [] as $valueModel) {
                        if ((string)$valueModel->getValue() === (string)$inn) {
                            return $company;
                        }
                    }
                }
            }

            return $companies?->first();

        } catch (AmoCRMApiException $e) {

            Log::error("AmoCRM getCompanyByINN error: " . $e->getMessage());
        }
        return null;
    }

    public function createCompany(array $data): ?\AmoCRM\Models\CompanyModel
    {
        try {
            $company = new CompanyModel();
            $company->setName((string)($data['name'] ?? 'Без названия'));

            $customFields = $data['custom_fields_values'] ?? [];
            if (is_array($customFields) && !empty($customFields)) {
                $fieldsCollection = new CustomFieldsValuesCollection();

                foreach ($customFields as $fieldData) {
                    if (!is_array($fieldData)) {
                        continue;
                    }

                    $fieldId = (int)($fieldData['field_id'] ?? 0);
                    $value = $fieldData['values'][0]['value'] ?? null;
                    if ($fieldId <= 0 || $value === null || $value === '') {
                        continue;
                    }

                    $fieldsCollection->add(
                        (new TextCustomFieldValuesModel())
                            ->setFieldId($fieldId)
                            ->setValues(
                                (new TextCustomFieldValueCollection())
                                    ->add((new TextCustomFieldValueModel())->setValue((string)$value))
                            )
                    );
                }

                if ($fieldsCollection->count() > 0) {
                    $company->setCustomFieldsValues($fieldsCollection);
                }
            }

            return $this->client
                ->companies()
                ->addOne($company);

        } catch (AmoCRMApiException $e) {

            Log::error("AmoCRM createCompany error: " . $e->getMessage());
        }
    }

    /**
     * @throws AmoCRMApiException
     * @throws AmoCRMoAuthApiException
     * @throws AmoCRMMissedTokenException
     */
    public function updateCompany(CompanyModel $company): CompanyModel
    {
        if (!$company->getId()) {
            throw new InvalidArgumentException('Can not update company without id');
        }

        return $this->client->companies()->updateOne($company);
    }

    public function linkCompanyToLead(LeadModel $lead, int $companyId): bool
    {
        try {
            if (!$lead->getId() || $companyId <= 0) {
                return false;
            }

            $link = new LinkModel();
            $link->setToEntityId($companyId);
            $link->setToEntityType('companies');

            $links = new LinksCollection();
            $links->add($link);

            $this->client->leads()->link($lead, $links);

            return true;
        } catch (AmoCRMApiException $e) {
            Log::error("AmoCRM linkCompanyToLead error: " . $e->getMessage());
            return false;
        }
    }

    public function linkCompanyToContact(ContactModel $contact, int $companyId): bool
    {
        try {
            if (!$contact->getId() || $companyId <= 0) {
                return false;
            }

            $link = new LinkModel();
            $link->setToEntityId($companyId);
            $link->setToEntityType('companies');

            $links = new LinksCollection();
            $links->add($link);

            $this->client->contacts()->link($contact, $links);

            return true;
        } catch (AmoCRMApiException $e) {
            Log::error("AmoCRM linkCompanyToContact error: " . $e->getMessage());
            return false;
        }
    }

    public function createLead(array $leadData): LeadModel
    {
        try {
            $lead = $this->hydrateLeadModel($leadData);

            return $this->client->leads()->addOne($lead);

        } catch (AmoCRMApiException $e) {

            Log::error("AmoCRM createLead error: " . $e->getMessage(), [
                'code' => $e->getCode(),
                'description' => $e->getDescription(),
                'last_request' => $e->getLastRequestInfo(),
            ]);

            throw $e;
        }
    }

    public function findLeadByOrder(int|string $orderId): ?LeadModel
    {
        try {
            $fieldId = CrmSchema::FIELDS['lead']['order_id']['id'];
            $orderId = (string) $orderId;

            $filter = new LeadsFilter();
            $filter->setQuery($orderId);
            $leads = $this->client->leads()->get($filter);

            foreach ($leads ?? [] as $lead) {

                if ($fieldId > 0) {

                    foreach ($lead->getCustomFieldsValues() ?? [] as $customField) {

                        foreach ($customField->getValues() ?? [] as $valueModel) {

                            if ((string) $valueModel->getValue() === $orderId) {

                                return $lead;
                            }
                        }
                    }
                }
            }

            return null;

        } catch (AmoCRMApiNoContentException $e) {

            return null;
        } catch (AmoCRMMissedTokenException|AmoCRMoAuthApiException|AmoCRMApiException $e) {

            Log::error("AmoCRM findLeadByOrderId error: " . $e->getMessage(), [
                'code' => $e->getCode(),
                'description' => method_exists($e, 'getDescription') ? $e->getDescription() : null,
                'last_request' => method_exists($e, 'getLastRequestInfo') ? $e->getLastRequestInfo() : null,
            ]);

            return null;
        }
    }

    public function updateLead(int $leadId, array $leadData): bool
    {
        try {
            $lead = $this->hydrateLeadModel($leadData);

            $lead->setId($leadId);

            $this->client->leads()->updateOne($lead);

            return true;

        } catch (AmoCRMApiException $e) {

            Log::error("AmoCRM updateLead error: " . $e->getMessage(), [
                'code' => $e->getCode(),
                'description' => $e->getDescription(),
                'last_request' => $e->getLastRequestInfo(),
            ]);
            return false;
        }
    }

    protected function hydrateLeadModel(array $leadData): LeadModel
    {
        $lead = new LeadModel();

        if (isset($leadData['name']))
            $lead->setName((string)$leadData['name']);

        if (isset($leadData['price']))
            $lead->setPrice((int)$leadData['price']);

        if (isset($leadData['status_id']))
            $lead->setStatusId((int)$leadData['status_id']);

        if (!empty($leadData['custom_fields_values'])) {
            $fieldsCollection = new CustomFieldsValuesCollection();

            foreach ((array)$leadData['custom_fields_values'] as $fieldData) {
                if (!is_array($fieldData)) {
                    continue;
                }

                $fieldModel = $this->makeLeadCustomFieldModel($fieldData);

                if ($fieldModel !== null) {
                    $fieldsCollection->add($fieldModel);
                }
            }

            if ($fieldsCollection->count() > 0) {
                $lead->setCustomFieldsValues($fieldsCollection);
            }
        }

        if (!empty($leadData['contacts'])) {
            $contactsCollection = new ContactsCollection();

            foreach ((array)$leadData['contacts'] as $contactData) {
                if (!is_array($contactData) || empty($contactData['id'])) {
                    continue;
                }

                $contact = new ContactModel();
                $contact->setId((int)$contactData['id']);
                $contact->setIsMain((bool)($contactData['is_main'] ?? false));
                $contactsCollection->add($contact);
            }

            if ($contactsCollection->count() > 0) {
                $lead->setContacts($contactsCollection);
            }
        }

        if (!empty($leadData['company']['id'])) {
            $company = new CompanyModel();
            $company->setId((int)$leadData['company']['id']);
            $lead->setCompany($company);
        }

        return $lead;
    }

    protected function makeLeadCustomFieldModel(array $fieldData): ?BaseCustomFieldValuesModel
    {
        $fieldId = (int)($fieldData['field_id'] ?? 0);
        $fieldCode = isset($fieldData['field_code']) ? (string)$fieldData['field_code'] : null;
        $value = $fieldData['values'][0]['value'] ?? null;

        if (($fieldId <= 0 && empty($fieldCode)) || $value === null || $value === '') {
            return null;
        }

        if ($this->isLeadDateField($fieldId)) {
            try {
                return (new DateCustomFieldValuesModel())
                    ->setFieldId($fieldId > 0 ? $fieldId : null)
                    ->setValues(
                        (new DateCustomFieldValueCollection())
                            ->add((new DateCustomFieldValueModel())->setValue($value))
                    );
            } catch (InvalidArgumentException) {
                // Fallback to plain text for malformed date input from source payload.
            }
        }

        if ($this->isLeadNumericField($fieldId)) {
            return (new NumericCustomFieldValuesModel())
                ->setFieldId($fieldId > 0 ? $fieldId : null)
                ->setValues(
                    (new NumericCustomFieldValueCollection())
                        ->add((new NumericCustomFieldValueModel())->setValue(is_numeric($value) ? $value + 0 : $value))
                );
        }

        $model = new TextCustomFieldValuesModel();
        if ($fieldId > 0) {
            $model->setFieldId($fieldId);
        } elseif (!empty($fieldCode)) {
            $model->setFieldCode($fieldCode);
        }

        return $model->setValues(
            (new TextCustomFieldValueCollection())
                ->add((new TextCustomFieldValueModel())->setValue((string)$value))
        );
    }

    protected function isLeadDateField(int $fieldId): bool
    {
        $dateFields = [
            (int)(CrmSchema::FIELDS['lead']['created_at']['id'] ?? 0),
            (int)(CrmSchema::FIELDS['lead']['paid_at']['id'] ?? 0),
            (int)(CrmSchema::FIELDS['lead']['subscription_start_at']['id'] ?? 0),
            (int)(CrmSchema::FIELDS['lead']['subscription_end_at']['id'] ?? 0),
        ];

        return in_array($fieldId, $dateFields, true);
    }

    protected function isLeadNumericField(int $fieldId): bool
    {
        $numericFields = [
            (int)(CrmSchema::FIELDS['lead']['subtotal']['id'] ?? 0),
            (int)(CrmSchema::FIELDS['lead']['quantity']['id'] ?? 0),
            (int)(CrmSchema::FIELDS['lead']['total']['id'] ?? 0),
            (int)(CrmSchema::FIELDS['lead']['access_count']['id'] ?? 0),
        ];

        return in_array($fieldId, $numericFields, true);
    }

    public function linkProductsToLead(LeadModel $lead, array $productIds): bool
    {
        try {
            if (empty($productIds)) {
                return true;
            }

            $links = new LinksCollection();

            foreach ($productIds as $productId) {

                $link = new LinkModel();
                $link->setMetadata([
                    'quantity' => 1,
                    'catalog_id' => CrmSchema::FIELDS['catalog']['id'],
                ]);
                $link->setToEntityId((int) $productId);
                $link->setToEntityType('catalog_elements');

                $links->add($link);
            }

            $this->client->leads()->link($lead, $links);

            return true;

        } catch (AmoCRMApiException $e) {

            Log::error("AmoCRM linkProductsToLead error: " . $e->getMessage());

            return false;
        }
    }
}
