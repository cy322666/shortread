<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhookTask;
use App\Models\WebhookTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function receive(Request $request): JsonResponse
    {
        $content = $request->all();
        $orderId = $this->extractOrderId($content);

        $task = WebhookTask::query()->create([
            'task_content' => $content,
            'order_id' => $orderId,
            'task_complete' => false,
        ]);

        ProcessWebhookTask::dispatch($task);

        return response()->json(['status' => 'ok']);
    }

    protected function extractOrderId(array $content): ?string
    {
        $payload = $content['payload'] ?? $content;
        $orderId = $payload['order']['order_id'] ?? null;

        if ($orderId === null || $orderId === '') {
            return null;
        }

        return (string) $orderId;
    }
}
