<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookTask extends Model
{
    protected $table = 'webhook_tasks';

    protected $fillable = [
        'task_content',
        'task_complete',
        'lead_id',
        'contact_id',
        'company_id',
        'scenario',
        'products',
    ];

    protected $casts = [
        'task_content' => 'array',
        'task_complete' => 'boolean',
    ];

    /**
     * @return HasMany
     */
    public function logs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WebhookTaskLog::class, 'task_id');
    }
}
