<?php

namespace App\Services;

use AmoCRM\Client\AmoCRMApiClient;
use AmoCRM\Exceptions\AmoCRMApiException;
use AmoCRM\Filters\ContactsFilter;
use AmoCRM\Models\ContactModel;
use AmoCRM\Models\Contacts\ContactModel;
use Illuminate\Support\Facades\Log;

class AmoService
{
    protected AmoCRMApiClient $client;

    public function __construct()
    {
        //TODO
        $subdomain = config('amo.subdomain');
        $clientId = config('amo.client_id');
        $clientSecret = config('amo.client_secret');
        $redirectUri = config('amo.redirect_uri');
        $accessToken = json_decode(file_get_contents(storage_path('amo_token.json')), true);

        $this->client = new AmoCRMApiClient($clientId, $clientSecret, $redirectUri);
        $this->client->setAccessToken($accessToken);
    }

    public function getContactByEmail(string $email): ?int
    {
        try {
            $filter = new ContactsFilter();
            $filter->setEmail($email);

            $contacts = $this->client->contacts()->get($filter);

            return $contacts->first() ? $contacts->first()->getId() : null;

        } catch (AmoCRMApiException $e) {

            Log::error("AmoCRM getContactByEmail error: " . $e->getMessage());
            return null;
        }
    }

    public function createContact(array $data): ?int
    {
        try {
            $contact = $this->client->contacts()->addOne(new ContactModel($data));

            return $contact->getId();

        } catch (AmoCRMApiException $e) {

            Log::error("AmoCRM createContact error: " . $e->getMessage());

            return null;
        }
    }

    public function getCompanyByINN(string $inn): ?int
    {
        try {
            $companies = $this->client->companies()->get();

            foreach ($companies as $company) {

                foreach ($company->getCustomFieldsValues() ?? [] as $field) {

                    if ($field->getFieldId() === 348495 && $field->getValues()[0]->getValue() === $inn)

                        return $company->getId();
                }
            }
            return null;

        } catch (AmoCRMApiException $e) {

            Log::error("AmoCRM getCompanyByINN error: " . $e->getMessage());

            return null;
        }
    }

    public function createCompany(array $data): ?int
    {
        try {
            $company = $this->client->companies()->addOne(new \AmoCRM\Models\Companies\CompanyModel($data));
            return $company->getId();
        } catch (AmoCRMApiException $e) {
            Log::error("AmoCRM createCompany error: " . $e->getMessage());
            return null;
        }
    }

    public function createLead(array $data): ?int
    {
        try {
            $lead = $this->client->leads()->addOne(new \AmoCRM\Models\Leads\LeadModel($data));

            return $lead->getId();

        } catch (AmoCRMApiException $e) {

            Log::error("AmoCRM createLead error: " . $e->getMessage());

            return null;
        }
    }

    public function linkProductsToLead(int $leadId, array $productIds, $amoService)
    {

        try {

        $links = [];

        foreach ($productIds as $productId) {

            $links[] = [
                    'to_entity_id' => $productId,
                    'to_entity_type' => 'catalog_elements',
                    'metadata' => [
                        'quantity' => 1,
                        'catalog_id' => 3059
                    ]
                ];
            }

            $amoService->client()
                ->leads()
                ->link($leadId, $links);

        } catch (AmoCRMApiException $e) {

            Log::error("AmoCRM linkProductsToLead error: " . $e->getMessage());
        }
    }
}
