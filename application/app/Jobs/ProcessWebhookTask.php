<?php

namespace App\Jobs;

use App\Models\WebhookTask;
use App\Models\WebhookTaskLog;
use App\Services\AmoService;
use App\Services\ProductService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
        $content = $this->task->task_content;

        if (($content['event'] ?? '') !== 'thankyou') {

            $this->log("Пропускаем задачу, ожидается событие thankyou");
            $this->task->update(['task_complete' => true]);
            return;
        }

        $payload = $content['payload'];

        // Контакт
        $contactId = null;
        $email = $payload['client']['email'] ?? null;

        if ($email) {
            $contactId = $amoService->getContactByEmail($email);
        }
        if (!$contactId) {

            $contactId = $amoService->createContact([
                'name' => $payload['client']['pseudonym'] ?? 'Без имени',
                'custom_fields_values' => [
                    [
                        'field_id' => 348289,
                        'values' => [['value' => $email ?? '']]
                    ],
                    [
                        'field_id' => 348287,
                        'values' => [['value' => $payload['client']['username'] ?? '']]
                    ]
                ]
            ]);
        }

        // Компания
        $companyId = null;
        if (!empty($payload['order']['is_company'])) {
            $inn = $payload['client']['company_info']['inn'] ?? '';
            if ($inn) {
                $companyId = $amoService->getCompanyByINN($inn);
            }
            if (!$companyId) {
                $companyId = $amoService->createCompany([
                    'name' => $payload['client']['company_info']['organisation_name'] ?? 'Без названия',
                    'custom_fields_values' => [
                        [
                            'field_id' => 348495,
                            'values' => [['value' => $inn]]
                        ]
                    ]
                ]);
            }
        }

        // Сделка
        $leadId = $amoService->createLead([
            'name' => $payload['items'][0]['product_name'] . '/' . ($payload['order']['order_id'] ?? ''),
            'price' => $payload['order']['total'] ?? 0,
            '_embedded' => [
                'contacts' => $contactId ? [['id' => $contactId]] : [],
                'companies' => $companyId ? [['id' => $companyId]] : [],
            ]
        ]);

        if ($leadId && !empty($payload['items'])) {

            $productIds = [];

            $productService = new ProductService($amoService);

            foreach ($payload['items'] as $item) {

                $product = $productService->findProduct(name, article);

                if (!$product) {

                    $product = $productService->createProduct();
                }

                $productIds[] = $product->getId();
            }

            $amoService->linkProductsToLead($leadId, $productIds, $amoService);
        }

        $this->task->update([
            'task_complete' => true,
            'processed_at' => now()
        ]);
    }

    protected function log(string $message)
    {
        WebhookTaskLog::query()->create([
            'task_id' => $this->task->id,
            'message' => $message
        ]);
    }
}
