<?php

namespace App\Console\Commands;

use App\Jobs\ProcessWebhookTask;
use App\Models\WebhookTask;
use App\Services\AmoService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Throwable;

class BackfillOrders extends Command
{
    protected $signature = 'app:backfill-orders
        {file=/Users/integrator/Downloads/shortread-orders-2025-12-25--2026-04-02.json : Path to source JSON file}
        {--from=2025-12-25 : Import orders created from this date (Y-m-d)}
        {--tail=0 : Process only last N rows from file (0 = all)}
        {--order-id= : Process only this order_id}
        {--only-scenario= : Process only one scenario (checkout_viewed|payment_complete|payment_failed|order_abandoned|recurrent_payment|on_hold)}
        {--dry-run : Show counters only, do not create tasks}
        {--force : Process orders even if a task with lead_id already exists for the same order_id}';

    protected $description = 'Backfill historical orders into amoCRM via existing webhook processing logic';

    public function handle(AmoService $amoService): int
    {
        if (!Schema::hasColumn('webhook_tasks', 'order_id')) {
            $this->error('Column webhook_tasks.order_id not found. Run migrations first: php artisan migrate');
            return self::FAILURE;
        }

        $file = (string) $this->argument('file');
        if (!is_file($file) || !is_readable($file)) {
            $this->error("JSON file is not readable: {$file}");
            return self::FAILURE;
        }

        try {
            $from = Carbon::parse((string) $this->option('from'))->startOfDay();
        } catch (Throwable) {
            $this->error('Invalid --from date. Use format Y-m-d, e.g. 2025-12-25');
            return self::FAILURE;
        }

        try {
            $rows = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->error('Failed to parse JSON: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (!is_array($rows)) {
            $this->error('Source JSON must contain an array of orders');
            return self::FAILURE;
        }

        $tail = (int) $this->option('tail');
        if ($tail < 0) {
            $this->error('Option --tail must be >= 0');
            return self::FAILURE;
        }
        if ($tail > 0) {
            $rows = array_slice($rows, -$tail);
        }

        $stats = [
            'total' => 0,
            'before_from' => 0,
            'invalid_row' => 0,
            'unsupported' => 0,
            'forced_scenario' => 0,
            'scenario_filtered' => 0,
            'already_processed' => 0,
            'requeued_missing_lead' => 0,
            'queued' => 0,
            'processed_ok' => 0,
            'processed_error' => 0,
        ];

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $orderIdFilter = trim((string) $this->option('order-id'));
        $onlyScenario = mb_strtolower(trim((string) $this->option('only-scenario')));
        $allowedScenarios = ['checkout_viewed', 'payment_complete', 'payment_failed', 'order_abandoned', 'recurrent_payment', 'on_hold'];
        if ($onlyScenario !== '' && !in_array($onlyScenario, $allowedScenarios, true)) {
            $this->error('Invalid --only-scenario. Allowed: ' . implode(', ', $allowedScenarios));
            return self::FAILURE;
        }
        $scenarioDetector = $this->buildScenarioDetector();
        $leadExistsCache = [];

        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        foreach ($rows as $row) {
            $stats['total']++;
            $bar->advance();

            if (!is_array($row)) {
                $stats['invalid_row']++;
                continue;
            }

            $payload = $this->normalizePayload($row);
            $order = $payload['order'] ?? null;
            if (!is_array($order)) {
                $stats['invalid_row']++;
                continue;
            }

            $orderId = trim((string) ($order['order_id'] ?? ''));
            if ($orderId === '') {
                $stats['invalid_row']++;
                continue;
            }

            if ($orderIdFilter !== '' && $orderId !== $orderIdFilter) {
                continue;
            }

            $createdAt = $this->parseOrderCreatedAt($order['created_at'] ?? null);
            if ($createdAt === null || $createdAt->lt($from)) {
                $stats['before_from']++;
                continue;
            }

            $content = [
                'event' => 'status_changed',
                'payload' => $payload,
                '_backfill' => true,
            ];

            $scenario = $this->detectScenario($scenarioDetector, $content, $payload);
            if ($scenario === null && $onlyScenario !== '') {
                $scenario = $onlyScenario;
                $stats['forced_scenario']++;
            }

            if ($scenario === null) {
                $stats['unsupported']++;
                continue;
            }

            if ($onlyScenario !== '' && $scenario !== $onlyScenario) {
                $stats['scenario_filtered']++;
                continue;
            }

            if (!$force) {
                $existingLeadIds = WebhookTask::query()
                    ->where('order_id', $orderId)
                    ->whereNotNull('lead_id')
                    ->pluck('lead_id')
                    ->map(fn ($id) => (int) $id)
                    ->filter(fn (int $id) => $id > 0)
                    ->unique()
                    ->values();

                if ($existingLeadIds->isNotEmpty()) {
                    $hasLiveLead = false;

                    foreach ($existingLeadIds as $leadId) {
                        if (array_key_exists($leadId, $leadExistsCache)) {
                            $existsInAmo = $leadExistsCache[$leadId];
                        } else {
                            $existsInAmo = $amoService->getLeadById($leadId) !== null;
                            $leadExistsCache[$leadId] = $existsInAmo;
                        }

                        if ($existsInAmo) {
                            $hasLiveLead = true;
                            break;
                        }
                    }

                    if ($hasLiveLead) {
                        $stats['already_processed']++;
                        continue;
                    }

                    $stats['requeued_missing_lead']++;
                }
            }

            $stats['queued']++;

            if ($dryRun) {
                continue;
            }

            $task = WebhookTask::query()->create([
                'task_content' => $content,
                'order_id' => $orderId,
                'task_complete' => false,
            ]);

            ProcessWebhookTask::dispatchSync($task);

            $task->refresh();
            if ($task->task_complete && $task->lead_id) {
                $stats['processed_ok']++;
            } else {
                $stats['processed_error']++;
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->line('Backfill summary:');
        $this->line('  file: ' . $file);
        $this->line('  from: ' . $from->toDateString());
        $this->line('  tail: ' . ($tail > 0 ? (string) $tail : 'all'));
        $this->line('  order_id: ' . ($orderIdFilter !== '' ? $orderIdFilter : 'all'));
        $this->line('  only_scenario: ' . ($onlyScenario !== '' ? $onlyScenario : 'all'));
        $this->line('  dry_run: ' . ($dryRun ? 'yes' : 'no'));
        $this->line('  force: ' . ($force ? 'yes' : 'no'));
        foreach ($stats as $key => $value) {
            $this->line("  {$key}: {$value}");
        }

        return self::SUCCESS;
    }

    protected function normalizePayload(array $row): array
    {
        return [
            'order' => is_array($row['order'] ?? null) ? $row['order'] : [],
            'items' => is_array($row['items'] ?? null) ? $row['items'] : [],
            'client' => is_array($row['client'] ?? null) ? $row['client'] : [],
        ];
    }

    protected function parseOrderCreatedAt(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    protected function buildScenarioDetector(): array
    {
        $probeTask = new WebhookTask([
            'task_content' => [],
            'task_complete' => false,
        ]);
        $probeJob = new ProcessWebhookTask($probeTask);

        $method = new ReflectionMethod(ProcessWebhookTask::class, 'detectScenario');
        $method->setAccessible(true);

        return [$probeJob, $method];
    }

    protected function detectScenario(array $scenarioDetector, array $content, array $payload): ?string
    {
        /** @var ProcessWebhookTask $probeJob */
        [$probeJob, $method] = $scenarioDetector;

        /** @var ReflectionMethod $method */
        $result = $method->invoke($probeJob, $content, $payload);

        return is_string($result) && $result !== '' ? $result : null;
    }
}
