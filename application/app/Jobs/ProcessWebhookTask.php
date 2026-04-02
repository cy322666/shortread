<?php

namespace App\Jobs;

use AmoCRM\Collections\CustomFieldsValuesCollection;
use AmoCRM\Exceptions\AmoCRMApiException;
use AmoCRM\Exceptions\AmoCRMMissedTokenException;
use AmoCRM\Exceptions\AmoCRMoAuthApiException;
use AmoCRM\Exceptions\InvalidArgumentException;
use AmoCRM\Models\CompanyModel;
use AmoCRM\Models\ContactModel;
use AmoCRM\Models\CustomFieldsValues\MultitextCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\TextCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\MultitextCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\TextCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueModels\MultitextCustomFieldValueModel;
use AmoCRM\Models\CustomFieldsValues\ValueModels\TextCustomFieldValueModel;
use AmoCRM\Models\LeadModel;
use App\Models\WebhookTask;
use App\Services\AmoService;
use App\Services\ProductService;
use App\Support\CrmSchema;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessWebhookTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected WebhookTask $task;

    public function __construct(WebhookTask $task)
    {
        $this->task = $task;
    }

    public function handle(AmoService $amoService)
    {
        $scenario = null;
        $payload = [];
        $orderId = null;

        try {
            $content = $this->task->task_content;
            $payload = $this->extractPayload($content);
            $isBackfill = !empty($content['_backfill']);
            $orderId = $payload['order']['order_id'] ?? null;
            if ($orderId !== null && $orderId !== '' && (string)$this->task->order_id !== (string)$orderId) {
                $this->task->order_id = (string)$orderId;
            }
            $scenario = $this->detectScenario($content, $payload);

            if ($scenario === null) {
                Log::info(__METHOD__ . ': skip unsupported event', [
                    'event' => (string)($content['event'] ?? ''),
                ]);
                $this->task->scenario = null;
                $this->task->task_complete = true;
                $this->task->save();
                return;
            }

            if (!isset($payload['order']) || !is_array($payload['order'])) {

                Log::debug(__METHOD__.' task : '.$this->task->id, ['Пропуск: нет блока order в payload']);

                $this->task->task_complete = 1;
                $this->task->save();

                return;
            }

            $contact = $this->resolveContact($payload, $amoService);
            if (!$contact || !$contact->getId()) {
                Log::warning(__METHOD__ . ': unresolved contact, continue lead sync without contact link', [
                    'task_id' => $this->task->id,
                    'order_id' => $orderId,
                    'email' => (string)($payload['client']['email'] ?? ''),
                ]);
            }

            $company = $this->resolveCompany($payload, $amoService);

            $lead = $this->upsertLead($scenario, $payload, $contact, $company, $amoService, $isBackfill);
            $productIds = [];

            if ($company?->getId()) {
                $amoService->linkCompanyToLead($lead, (int)$company->getId());
                if ($contact?->getId()) {
                    $amoService->linkCompanyToContact($contact, (int)$company->getId());
                }
            }

            if (!empty($payload['items']) && is_array($payload['items'])) {
                try {
                    $productIds = $this->syncProductsToLead($lead, $payload['items'], $amoService, $orderId);
                } catch (Throwable $e) {
                    Log::warning(__METHOD__ . ': product sync skipped due error', [
                        'task_id' => $this->task->id,
                        'order_id' => $orderId,
                        'lead_id' => $lead?->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->task->scenario = $scenario;
            $this->task->products = $productIds ?? [];
            $this->task->contact_id = $contact?->getId();
            $this->task->company_id = $company?->getId();
            $this->task->lead_id = $lead?->getId();
            $this->task->task_complete = true;
            $this->task->save();

        } catch (Throwable $e) {

            Log::error('Ошибка обработки: ' . $e->getFile().' '.$e->getLine().' '.$e->getMessage(), [
                'task_id' => $this->task->id,
                'order_id' => $orderId,
                'scenario' => $scenario,
            ]);
        }
    }

    /**
     * @throws AmoCRMoAuthApiException
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     */
    protected function resolveRegisteredUserContact(array $payload, AmoService $amoService): ?ContactModel
    {
        $user = $payload['user'] ?? [];
        $email = trim((string)($user['email'] ?? ''));
        $client = [
            'pseudonym' => $user['display_name'] ?? ($user['username'] ?? 'Без имени'),
            'username' => $user['username'] ?? null,
            'user_id' => $user['user_id'] ?? null,
        ];

        $contact = $email !== '' ? $amoService->searchOrCreate($email) : $amoService->createContact();
        if (!$contact || !$contact->getId()) {
            return $contact;
        }

        $updatedContact = $this->buildContactModel($contact->getId(), $client, $email);
        try {
            return $amoService->updateContact($updatedContact);
        } catch (AmoCRMApiException $e) {
            Log::warning(__METHOD__ . ': updateContact skipped, fallback to existing contact. ' . $e->getMessage(), [
                'code' => $e->getCode(),
                'description' => $e->getDescription(),
                'last_request' => $e->getLastRequestInfo(),
            ]);
            return $contact;
        } catch (Throwable $e) {
            Log::warning(__METHOD__ . ': updateContact skipped, fallback to existing contact. ' . $e->getMessage());
            return $contact;
        }
    }

    protected function extractPayload(array $content): array
    {
        if (isset($content['payload']) && is_array($content['payload']))

            return $content['payload'];

        return $content;
    }

    protected function detectScenario(array $content, array $payload): ?string
    {
        $event = (string) ($content['event'] ?? '');
        $event = mb_strtolower(trim($event));

        $supported = ['checkout_viewed', 'payment_complete', 'payment_failed', 'order_abandoned', 'recurrent_payment', 'on_hold'];
        if (in_array($event, $supported, true)) {
            return $event;
        }

        if ($event === 'status_changed') {
            $hint = mb_strtolower(trim((string)($payload['event'] ?? $payload['scenario'] ?? '')));
            if (in_array($hint, $supported, true)) {
                return $hint;
            }

            return $this->mapStatusChangedScenario($payload);
        }

        return null;
    }

    protected function mapStatusChangedScenario(array $payload): ?string
    {
        $order = $payload['order'] ?? [];
        $status = mb_strtolower((string) ($order['status'] ?? ''));
        $paidAt = trim((string) ($order['paid_at'] ?? ''));
        $isRecurrent = !empty($order['is_recurrent']) && ($order['recurrent_type'] ?? '') === 'autopay';

        if ($isRecurrent) {
            return 'recurrent_payment';
        }

        if (in_array($status, ['on-hold', 'on_hold'], true)) {
            return 'on_hold';
        }

        if (in_array($status, ['processing', 'completed'], true)) {
            return 'payment_complete';
        }

        if (in_array($status, ['cancelled', 'failed'], true)) {
            return 'payment_failed';
        }

        if ($paidAt === '' && $status !== 'processing' && $this->isOlderThanMinutes($order['created_at'] ?? null, 30)) {
            return 'order_abandoned';
        }

        return null;
    }

    protected function isOlderThanMinutes(mixed $value, int $minutes): bool
    {
        if (!$value) {
            return false;
        }

        try {
            return Carbon::parse((string) $value)->lt(now()->subMinutes($minutes));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @throws AmoCRMoAuthApiException
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     */
    protected function resolveContact(array $payload, AmoService $amoService): ?\AmoCRM\Models\ContactModel
    {
        $client = $payload['client'] ?? [];
        $email = trim((string) ($client['email'] ?? ''));

        $contact = $email !== '' ? $amoService->searchOrCreate($email) : $amoService->createContact();
        if (!$contact || !$contact->getId()) {
            return $contact;
        }

        $updatedContact = $this->buildContactModel($contact->getId(), $client, $email);
        try {
            return $amoService->updateContact($updatedContact);
        } catch (AmoCRMApiException $e) {
            Log::warning(__METHOD__ . ': updateContact skipped, fallback to existing contact. ' . $e->getMessage(), [
                'code' => $e->getCode(),
                'description' => $e->getDescription(),
                'last_request' => $e->getLastRequestInfo(),
            ]);
            return $contact;
        } catch (Throwable $e) {
            Log::warning(__METHOD__ . ': updateContact skipped, fallback to existing contact. ' . $e->getMessage());
            return $contact;
        }
    }

    protected function buildContactModel(?int $contactId, array $client, string $email): ContactModel
    {
        $contact = new ContactModel();
        $contact->setId((int) $contactId);
        $contact->setName($client['pseudonym'] ?? ($client['username'] ?? 'Без имени'));

        $fields = new CustomFieldsValuesCollection();

        if ($email !== '') {

            $fields->add(
                (new MultitextCustomFieldValuesModel())
                    ->setFieldCode('EMAIL')
                    ->setValues(
                        (new MultitextCustomFieldValueCollection())
                            ->add(
                                (new MultitextCustomFieldValueModel())
                                    ->setValue($email)
                                    ->setEnum('WORK')
                            )
                    )
            );
        }

        $usernameFieldId = CrmSchema::FIELDS['contact']['username']['id'];

        if ($usernameFieldId > 0 && !empty($client['username'])) {

            $fields->add(
                (new TextCustomFieldValuesModel())
                    ->setFieldId($usernameFieldId)
                    ->setValues(
                        (new TextCustomFieldValueCollection())
                            ->add((new TextCustomFieldValueModel())->setValue((string) $client['username']))
                    )
            );
        }

        $userIdFieldId = CrmSchema::FIELDS['contact']['user_id']['id'];

        if ($userIdFieldId > 0 && isset($client['user_id'])) {

            $fields->add(
                (new TextCustomFieldValuesModel())
                    ->setFieldId($userIdFieldId)
                    ->setValues(
                        (new TextCustomFieldValueCollection())
                            ->add((new TextCustomFieldValueModel())->setValue((string) $client['user_id']))
                    )
            );
        }

        if ($fields->count() > 0)
            $contact->setCustomFieldsValues($fields);

        return $contact;
    }

    protected function resolveCompany(array $payload, AmoService $amoService): ?\AmoCRM\Models\CompanyModel
    {
        try {
            if ($payload['order']['is_company'] === false)
                return null;

            $companyInfo = $payload['client']['company_info'] ?? [];
            $inn = (string)($companyInfo['inn'] ?? '');
            $kpp = (string)($companyInfo['kpp'] ?? '');
            $address = (string)($companyInfo['address'] ?? '');
            $companyName = $companyInfo['organisation_name'] ?? 'Без названия';

            $company = $inn !== '' ? $amoService->getCompanyByINN($inn) : null;

            if ($company && $company->getId()) {

                $companyToUpdate = $this->buildCompanyModel($company->getId(), $companyName, $inn, $kpp, $address);

                return $amoService->updateCompany($companyToUpdate);
            }

            $companyFields = [];

            $this->appendCustomField($companyFields, CrmSchema::FIELDS['company']['inn']['id'], $inn);
            $this->appendCustomField($companyFields, CrmSchema::FIELDS['company']['kpp']['id'], $kpp);
            $this->appendCustomField($companyFields, CrmSchema::FIELDS['company']['address']['id'], $address);

            return $amoService->createCompany([
                'name' => $companyName,
                'custom_fields_values' => $companyFields,
            ]);

        } catch (Throwable $e) {
            Log::error(__METHOD__ . ': ' . $e->getMessage());
            return null;
        }
    }

    protected function buildCompanyModel(
        int $companyId,
        string $companyName,
        string $inn,
        string $kpp = '',
        string $address = ''
    ): CompanyModel
    {
        $company = new CompanyModel();
        $company->setId($companyId);
        $company->setName($companyName);

        $innFieldId = (int)(CrmSchema::FIELDS['company']['inn']['id'] ?? 0);
        $kppFieldId = (int)(CrmSchema::FIELDS['company']['kpp']['id'] ?? 0);
        $addressFieldId = (int)(CrmSchema::FIELDS['company']['address']['id'] ?? 0);

        if (($innFieldId > 0 && $inn !== '') || ($kppFieldId > 0 && $kpp !== '') || ($addressFieldId > 0 && $address !== '')) {
            $fields = new CustomFieldsValuesCollection();

            if ($innFieldId > 0 && $inn !== '') {
                $fields->add(
                    (new TextCustomFieldValuesModel())
                        ->setFieldId($innFieldId)
                        ->setValues(
                            (new TextCustomFieldValueCollection())
                                ->add((new TextCustomFieldValueModel())->setValue($inn))
                        )
                );
            }

            if ($kppFieldId > 0 && $kpp !== '') {
                $fields->add(
                    (new TextCustomFieldValuesModel())
                        ->setFieldId($kppFieldId)
                        ->setValues(
                            (new TextCustomFieldValueCollection())
                                ->add((new TextCustomFieldValueModel())->setValue($kpp))
                        )
                );
            }

            if ($addressFieldId > 0 && $address !== '') {
                $fields->add(
                    (new TextCustomFieldValuesModel())
                        ->setFieldId($addressFieldId)
                        ->setValues(
                            (new TextCustomFieldValueCollection())
                                ->add((new TextCustomFieldValueModel())->setValue($address))
                        )
                );
            }

            $company->setCustomFieldsValues($fields);
        }

        return $company;
    }

    protected function upsertLead(
        string $scenario,
        array $payload,
        ?ContactModel $contact,
        ?CompanyModel $company,
        AmoService $amoService,
        bool $isBackfill = false
    ): LeadModel
    {
        $orderId = $payload['order']['order_id'] ?? null;
        $orderIdString = $orderId !== null && $orderId !== '' ? (string)$orderId : null;

        $leadData = $this->buildLeadData($scenario, $payload, $contact?->getId(), $company?->getId(), $isBackfill);

        if ($orderIdString !== null) {

            $existingLeadId = $this->findLinkedLeadIdByOrder($orderIdString);

            if ($existingLeadId !== null) {

                $linkedLead = $amoService->getLeadById($existingLeadId);

                if ($linkedLead instanceof LeadModel) {

                    if ($scenario === 'order_abandoned' && $this->isLeadAlreadyPaid($linkedLead)) {

                        Log::info(__METHOD__ . ': skip abandoned update for paid lead', [
                            'lead_id' => $linkedLead->getId(),
                            'order_id' => $orderIdString,
                        ]);

                        return $linkedLead;
                    }

                    $updated = $this->updateLeadWithFallback(
                        $amoService,
                        (int)$linkedLead->getId(),
                        $leadData,
                        $isBackfill,
                        $orderIdString
                    );

                    if (!$updated) {

                        throw new \RuntimeException(sprintf(
                            'Failed to update lead %d for scenario %s (order_id=%s)',
                            (int)$linkedLead->getId(),
                            $scenario,
                            $orderIdString
                        ));
                    }

                    return $linkedLead;
                }
            }

            $lead = $amoService->findLeadByOrder($orderIdString);

            if ($lead) {
                if ($scenario === 'order_abandoned' && $this->isLeadAlreadyPaid($lead)) {
                    Log::info(__METHOD__ . ': skip abandoned update for paid lead', [
                        'lead_id' => $lead->getId(),
                        'order_id' => $orderIdString,
                    ]);
                    return $lead;
                }

                $updated = $this->updateLeadWithFallback(
                    $amoService,
                    (int)$lead->getId(),
                    $leadData,
                    $isBackfill,
                    $orderIdString
                );
                if (!$updated) {
                    throw new \RuntimeException(sprintf(
                        'Failed to update lead %d for scenario %s (order_id=%s)',
                        (int)$lead->getId(),
                        $scenario,
                        $orderIdString
                    ));
                }

                return $lead;
            }
        }

        return $this->createLeadWithFallback($amoService, $leadData, $isBackfill, $orderIdString);
    }

    protected function updateLeadWithFallback(
        AmoService $amoService,
        int $leadId,
        array $leadData,
        bool $allowFallback,
        ?string $orderId
    ): bool {
        $updated = $amoService->updateLead($leadId, $leadData);
        if ($updated || !$allowFallback) {
            return $updated;
        }

        $fallbackData = $this->sanitizeLeadDataForRetry($leadData);
        if ($fallbackData === $leadData) {
            return false;
        }

        Log::warning(__METHOD__ . ': retry update lead with sanitized fields', [
            'lead_id' => $leadId,
            'order_id' => $orderId,
        ]);

        return $amoService->updateLead($leadId, $fallbackData);
    }

    protected function createLeadWithFallback(
        AmoService $amoService,
        array $leadData,
        bool $allowFallback,
        ?string $orderId
    ): LeadModel {
        try {
            return $amoService->createLead($leadData);
        } catch (Throwable $e) {
            if (!$allowFallback) {
                throw $e;
            }

            $fallbackData = $this->sanitizeLeadDataForRetry($leadData);
            if ($fallbackData === $leadData) {
                throw $e;
            }

            Log::warning(__METHOD__ . ': retry create lead with sanitized fields', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            try {
                return $amoService->createLead($fallbackData);
            } catch (Throwable $retryError) {
                $minimalData = $this->buildMinimalLeadDataForRetry($leadData);

                Log::warning(__METHOD__ . ': retry create lead with minimal fields', [
                    'order_id' => $orderId,
                    'error' => $retryError->getMessage(),
                ]);

                return $amoService->createLead($minimalData);
            }
        }
    }

    protected function sanitizeLeadDataForRetry(array $leadData): array
    {
        $blockedFieldIds = array_values(array_filter([
            (int)(CrmSchema::FIELDS['lead']['status']['id'] ?? 0),
            (int)(CrmSchema::FIELDS['lead']['period_subscribe']['id'] ?? 0),
            (int)(CrmSchema::FIELDS['lead']['payment_method']['id'] ?? 0),
            (int)(CrmSchema::FIELDS['lead']['customer_type']['id'] ?? 0),
            (int)(CrmSchema::FIELDS['lead']['origin']['id'] ?? 0),
            (int)($this->leadFieldId('is_recurrent')),
            (int)($this->leadFieldId('recurrent_type')),
        ]));

        if (empty($blockedFieldIds) || empty($leadData['custom_fields_values']) || !is_array($leadData['custom_fields_values'])) {
            return $leadData;
        }

        $leadData['custom_fields_values'] = array_values(array_filter(
            $leadData['custom_fields_values'],
            static function ($fieldData) use ($blockedFieldIds) {
                if (!is_array($fieldData)) {
                    return false;
                }

                $fieldId = (int)($fieldData['field_id'] ?? 0);
                if ($fieldId > 0 && in_array($fieldId, $blockedFieldIds, true)) {
                    return false;
                }

                return true;
            }
        ));

        return $leadData;
    }

    protected function buildMinimalLeadDataForRetry(array $leadData): array
    {
        $minimal = [
            'name' => (string)($leadData['name'] ?? 'Заказ'),
            'price' => (int)($leadData['price'] ?? 0),
            'custom_fields_values' => [],
        ];

        if (!empty($leadData['status_id'])) {
            $minimal['status_id'] = (int)$leadData['status_id'];
        }

        if (!empty($leadData['closed_at'])) {
            $minimal['closed_at'] = (int)$leadData['closed_at'];
        }

        if (!empty($leadData['tags_to_add']) && is_array($leadData['tags_to_add'])) {
            $minimal['tags_to_add'] = $leadData['tags_to_add'];
        }

        if (!empty($leadData['contacts']) && is_array($leadData['contacts'])) {
            $contacts = [];
            foreach ($leadData['contacts'] as $contactData) {
                if (!is_array($contactData) || empty($contactData['id'])) {
                    continue;
                }
                $contacts[] = ['id' => (int)$contactData['id']];
            }
            if (!empty($contacts)) {
                $minimal['contacts'] = $contacts;
            }
        }

        if (!empty($leadData['company']['id'])) {
            $minimal['company'] = ['id' => (int)$leadData['company']['id']];
        }

        $orderFieldId = (int)(CrmSchema::FIELDS['lead']['order_id']['id'] ?? 0);
        if ($orderFieldId > 0 && !empty($leadData['custom_fields_values']) && is_array($leadData['custom_fields_values'])) {
            foreach ($leadData['custom_fields_values'] as $fieldData) {
                if (!is_array($fieldData)) {
                    continue;
                }

                if ((int)($fieldData['field_id'] ?? 0) !== $orderFieldId) {
                    continue;
                }

                $value = $fieldData['values'][0]['value'] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }

                $minimal['custom_fields_values'][] = [
                    'field_id' => $orderFieldId,
                    'values' => [
                        ['value' => (string)$value],
                    ],
                ];
                break;
            }
        }

        return $minimal;
    }

    protected function findLinkedLeadIdByOrder(string $orderId): ?int
    {
        $leadId = WebhookTask::query()
            ->where('order_id', $orderId)
            ->whereNotNull('lead_id')
            ->orderBy('id')
            ->value('lead_id');

        return $leadId !== null ? (int)$leadId : null;
    }

    protected function isLeadAlreadyPaid(LeadModel $lead): bool
    {
        $paidStatusIds = [
            (int)(CrmSchema::STATUSES['payment_complete']['id'] ?? 0),
            (int)(CrmSchema::STATUSES['recurrent_payment']['id'] ?? 0),
            (int)(CrmSchema::STATUSES['on_hold']['id'] ?? 0),
        ];

        if (in_array((int)$lead->getStatusId(), $paidStatusIds, true)) {
            return true;
        }

        $paidAtFieldId = (int)(CrmSchema::FIELDS['lead']['paid_at']['id'] ?? 0);
        if ($paidAtFieldId <= 0) {
            return false;
        }

        foreach ($lead->getCustomFieldsValues() ?? [] as $customField) {
            if ((int)$customField->getFieldId() !== $paidAtFieldId) {
                continue;
            }

            foreach ($customField->getValues() ?? [] as $valueModel) {
                $value = $valueModel->getValue();
                if ($value !== null && (string)$value !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    protected function buildLeadData(
        string $scenario,
        array $payload,
        ?int $contactId,
        ?int $companyId,
        bool $isBackfill = false
    ): array
    {
        $order = $payload['order'] ?? [];
        $firstItem = $payload['items'][0] ?? [];
        $leadBudget = (isset($order['total']) && is_numeric($order['total'])) ? (int)$order['total'] : 0;

        $leadData = [
            'name' => ($firstItem['product_name'] ?? '') . '/' . ($order['order_id'] ?? ''),
            'price' => $leadBudget,
            'custom_fields_values' => [],
            'contacts' => $contactId ? [['id' => $contactId]] : [],
            'company' => $companyId ? ['id' => $companyId] : null,
        ];

        $closedAt = $this->resolveLeadClosedAt($scenario, $order);
        if ($closedAt !== null) {
            $leadData['closed_at'] = $closedAt;
        }

        $statusId = (int) (CrmSchema::STATUSES[$scenario]['id'] ?? 0);

        if ($statusId > 0)
            $leadData['status_id'] = $statusId;

        $errorReason = $order['error_reason'] ?? ($order['fail_reason'] ?? ($order['error'] ?? null));
        $periodSubscribe = $this->resolvePeriodSubscribe($order, $firstItem);
        $accessCount = $order['access_count'] ?? ($order['quantity'] ?? null);
        $nextPaymentAt = $this->resolveNextPaymentAt($order, $firstItem);
        $resolvedRecurrentType = $this->resolveRecurrentType($order, $firstItem);
        $resolvedIsRecurrent = $this->resolveIsRecurrent($order, $resolvedRecurrentType, $firstItem);
        $paidAtValue = $this->resolvePaidAtValue($scenario, $order);

        $this->appendCustomField($leadData['custom_fields_values'], CrmSchema::FIELDS['lead']['order_id']['id'], $order['order_id'] ?? null);
        $this->appendCustomField($leadData['custom_fields_values'], CrmSchema::FIELDS['lead']['parent_order_id']['id'], $order['parent_order_id'] ?? null);
        $this->appendCustomField($leadData['custom_fields_values'], CrmSchema::FIELDS['lead']['product']['id'], $order['product'] ?? null);
        $this->appendCustomField($leadData['custom_fields_values'], CrmSchema::FIELDS['lead']['created_at']['id'], $this->timestampValue($order['created_at'] ?? null));
        $this->appendCustomField($leadData['custom_fields_values'], CrmSchema::FIELDS['lead']['paid_at']['id'], $paidAtValue);
        $this->appendCustomField($leadData['custom_fields_values'], CrmSchema::FIELDS['lead']['status']['id'], $order['status'] ?? null);
        $this->appendCustomField($leadData['custom_fields_values'], CrmSchema::FIELDS['lead']['period_subscribe']['id'], $periodSubscribe);
        $this->appendCustomField($leadData['custom_fields_values'], CrmSchema::FIELDS['lead']['payment_method']['id'], $order['payment_method'] ?? null);
        $this->appendCustomField($leadData['custom_fields_values'], CrmSchema::FIELDS['lead']['subtotal']['id'], $order['subtotal'] ?? null);
        $this->appendCustomField($leadData['custom_fields_values'], CrmSchema::FIELDS['lead']['quantity']['id'], $order['quantity'] ?? null);
        $this->appendCustomField($leadData['custom_fields_values'], CrmSchema::FIELDS['lead']['total']['id'], $order['total'] ?? null);
        $this->appendCustomField(
            $leadData['custom_fields_values'],
            (int) (CrmSchema::FIELDS['lead']['origin']['id'] ?? 348279),
            'shortread.ru'
        );
        $leadData['custom_fields_values'][] = [
            'field_code' => 'UTM_SOURCE',
            'values' => [
                ['value' => $this->normalizeUtmSource($order['origin'] ?? null)],
            ],
        ];
        $this->appendCustomField($leadData['custom_fields_values'], CrmSchema::FIELDS['lead']['product_name']['id'], $firstItem['product_name'] ?? null);
        $this->appendCustomField($leadData['custom_fields_values'], $this->leadFieldId('access_count'), $accessCount);
        $this->appendCustomField($leadData['custom_fields_values'], $this->leadFieldId('error_reason'), $errorReason);
        $this->appendCustomField($leadData['custom_fields_values'], $this->leadFieldId('subscription_start_at'), $this->timestampValue($order['subscription_start_at'] ?? null));
        $this->appendCustomField(
            $leadData['custom_fields_values'],
            $this->leadFieldId('subscription_end_at'),
            $nextPaymentAt
        );
        $this->appendCustomField(
            $leadData['custom_fields_values'],
            $this->leadFieldId('recurrent_type'),
            $resolvedRecurrentType
        );

        if ($resolvedIsRecurrent !== null) {
            $this->appendCustomField(
                $leadData['custom_fields_values'],
                $this->leadFieldId('is_recurrent'),
                $resolvedIsRecurrent ? 'true' : 'false'
            );
        }

        if (array_key_exists('is_company', $order)) {

            $this->appendCustomField(
                $leadData['custom_fields_values'],
                CrmSchema::FIELDS['lead']['customer_type']['id'],
                $order['is_company'] ? 'Юр.лицо' : 'Физ.лицо'
            );
        }

        $tagsToAdd = [];
        if ($isBackfill) {
            $tagsToAdd[] = ['name' => 'backfill'];
        }
        if ($scenario === 'on_hold') {
            $tagsToAdd[] = ['name' => 'на удержании'];
        }

        if (!empty($tagsToAdd)) {
            $leadData['tags_to_add'] = $tagsToAdd;
        }

        return $leadData;
    }

    protected function resolvePeriodSubscribe(array $order, array $firstItem): ?string
    {
        $raw = $order['period_subscribe'] ?? null;
        if ($raw !== null && $raw !== '') {
            return (string) $raw;
        }

        $format = mb_strtolower((string)($firstItem['format'] ?? ''));
        if ($format === '') {
            return null;
        }

        if (str_contains($format, 'yearly')) {
            return 'y';
        }

        if (str_contains($format, 'monthly')) {
            return 'm';
        }

        return null;
    }

    protected function resolveNextPaymentAt(array $order, array $firstItem): ?int
    {
        $explicit = $this->timestampValue($order['next_payment_at'] ?? ($order['subscription_end_at'] ?? null));
        if ($explicit !== null) {
            return $explicit;
        }

        $period = $this->resolvePeriodSubscribe($order, $firstItem);
        if ($period === null) {
            return null;
        }

        $baseTimestamp = $this->timestampValue($order['paid_at'] ?? null)
            ?? $this->timestampValue($order['created_at'] ?? null);

        if ($baseTimestamp === null) {
            return null;
        }

        try {
            $baseDate = Carbon::createFromTimestamp($baseTimestamp);

            return match ($period) {
                'm' => $baseDate->addMonthNoOverflow()->timestamp,
                'y' => $baseDate->addYear()->timestamp,
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    protected function resolvePaidAtValue(string $scenario, array $order): ?int
    {
        $paidAt = $this->timestampValue($order['paid_at'] ?? null);
        if ($paidAt !== null) {
            return $paidAt;
        }

        if (in_array($scenario, ['payment_complete', 'recurrent_payment'], true)) {
            return $this->timestampValue($order['created_at'] ?? null);
        }

        return null;
    }

    protected function resolveRecurrentType(array $order, array $firstItem): ?string
    {
        $explicit = $this->normalizeRecurrentType($order['recurrent_type'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }

        $format = mb_strtolower(trim((string)($firstItem['format'] ?? '')));
        if ($format === '') {
            return null;
        }

        if (str_contains($format, 'autopay')) {
            return 'autopay';
        }

        if (str_contains($format, 'recurrent')) {
            return 'recurrent';
        }

        if (str_contains($format, 'normal')) {
            return 'normal';
        }

        return null;
    }

    protected function resolveIsRecurrent(array $order, ?string $recurrentType, array $firstItem): ?bool
    {
        if (array_key_exists('is_recurrent', $order)) {
            $raw = $order['is_recurrent'];
            if (is_bool($raw)) {
                return $raw;
            }

            $normalized = mb_strtolower(trim((string)$raw));
            if (in_array($normalized, ['1', 'true', 'yes', 'y'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'n'], true)) {
                return false;
            }
        }

        if ($recurrentType !== null) {
            return $recurrentType !== 'normal';
        }

        $format = mb_strtolower(trim((string)($firstItem['format'] ?? '')));
        if ($format === '') {
            return null;
        }

        if (str_contains($format, 'normal')) {
            return false;
        }

        if (str_contains($format, 'recurrent') || str_contains($format, 'autopay')) {
            return true;
        }

        return null;
    }

    protected function resolveLeadClosedAt(string $scenario, array $order): ?int
    {
        if ($scenario === 'checkout_viewed') {
            return null;
        }

        if (in_array($scenario, ['payment_complete', 'recurrent_payment', 'on_hold'], true)) {
            return $this->timestampValue($order['paid_at'] ?? null)
                ?? $this->timestampValue($order['created_at'] ?? null)
                ?? now()->timestamp;
        }

        if ($scenario === 'payment_failed') {
            return $this->timestampValue($order['created_at'] ?? null) ?? now()->timestamp;
        }

        if ($scenario === 'order_abandoned') {
            $createdAt = $this->timestampValue($order['created_at'] ?? null);
            if ($createdAt !== null) {
                return $createdAt + (30 * 60);
            }

            return now()->timestamp;
        }

        return null;
    }

    /**
     * @throws InvalidArgumentException
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     * @throws AmoCRMoAuthApiException
     */
    protected function syncProductsToLead(LeadModel $lead, array $items, AmoService $amoService, mixed $orderId = null): array
    {
        $productService = new ProductService($amoService);
        $productIds = [];

        foreach ($items as $item) {

            if (!is_array($item))

                continue;

            $productName = trim((string)($item['product_name'] ?? ''));
            if ($productName === '') {
                continue;
            }

            try {
                $product = $productService->findOrCreate($productName, [
                    'price' => $item['price'] ?? 0
                ]);
            } catch (Throwable $e) {
                Log::warning(__METHOD__ . ': skip product item due error', [
                    'task_id' => $this->task->id,
                    'order_id' => $orderId,
                    'lead_id' => $lead->getId(),
                    'product_name' => $productName,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $productIds[] = $product->getId();
        }

        $productIds = array_values(array_unique(array_filter($productIds)));

        $amoService->linkProductsToLead($lead, $productIds);

        return $productIds;
    }

    protected function appendCustomField(array &$customFields, int $fieldId, mixed $value): void
    {
        if ($fieldId <= 0 || $value === null || $value === '') {
            return;
        }

        $value = $this->normalizeCustomFieldValue($fieldId, $value);

        $customFields[] = [
            'field_id' => $fieldId,
            'values' => [
                ['value' => $value],
            ],
        ];
    }

    protected function normalizeCustomFieldValue(int $fieldId, mixed $value): mixed
    {
        $dateFields = [
            $this->leadFieldId('created_at'),
            $this->leadFieldId('paid_at'),
            $this->leadFieldId('subscription_start_at'),
            $this->leadFieldId('subscription_end_at'),
        ];

        if (in_array($fieldId, $dateFields, true)) {
            return is_numeric($value) ? (int) $value : $this->timestampValue($value);
        }

        $numericFields = [
            $this->leadFieldId('subtotal'),
            $this->leadFieldId('quantity'),
            $this->leadFieldId('total'),
        ];

        if (in_array($fieldId, $numericFields, true)) {
            if (is_int($value)) {
                return $value;
            }

            if (is_float($value)) {
                return $value;
            }

            if (is_numeric($value) && ctype_digit((string) $value)) {
                return (int) $value;
            }

            return is_numeric($value) ? (float) $value : $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    protected function normalizeRecurrentType(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = mb_strtolower(trim((string) $value));
        $allowed = ['recurrent', 'autopay', 'normal'];

        return in_array($normalized, $allowed, true) ? $normalized : null;
    }

    protected function leadFieldId(string $key): int
    {
        return (int) (CrmSchema::FIELDS['lead'][$key]['id'] ?? 0);
    }

    protected function timestampValue(mixed $value): ?int
    {
        if (!$value) {
            return null;
        }

        $timestamp = strtotime((string) $value);
        return $timestamp === false ? null : $timestamp;
    }

    protected function normalizeUtmSource(mixed $origin): string
    {
        $value = mb_strtolower(trim((string)$origin));
        if ($value === '') {
            return 'direct';
        }

        if ($value === '(direct)') {
            return 'direct';
        }

        return $value;
    }

    protected function markComplete(): void
    {
        $this->task->update([
            'task_complete' => true,
        ]);
    }
}
