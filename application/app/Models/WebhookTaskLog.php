<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookTaskLog extends Model
{
    protected $table = 'webhook_task_logs';

    protected $fillable = [
        'task_id',
        'message',
    ];

    public function task()
    {
        return $this->belongsTo(WebhookTask::class, 'task_id');
    }
}
