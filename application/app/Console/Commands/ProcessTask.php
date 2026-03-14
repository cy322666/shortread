<?php

namespace App\Console\Commands;

use App\Jobs\ProcessWebhookTask;
use App\Models\WebhookTask;
use Illuminate\Console\Command;

class ProcessTask extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:process-task {task_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $task = WebhookTask::query()->find($this->argument('task_id'));

        ProcessWebhookTask::dispatch($task);
    }
}
