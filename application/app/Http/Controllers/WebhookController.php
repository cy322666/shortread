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
        $task = WebhookTask::query()->create([
            'task_content' => $request->all(),
            'task_complete' => false,
        ]);

        ProcessWebhookTask::dispatch($task);

        return response()->json(['status' => 'ok']);
    }
}
