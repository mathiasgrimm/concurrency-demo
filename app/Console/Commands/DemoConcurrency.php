<?php

namespace App\Console\Commands;

use Illuminate\Concurrency\TaskTimedOutException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Concurrency;
use RuntimeException;

class DemoConcurrency extends Command
{
    protected $signature = 'demo:concurrency {--timeout=30 : Total wait in seconds}';

    protected $description = 'Demonstrate the queue concurrency driver (run queue workers first)';

    public function handle(): int
    {
        $this->components->info('Caller pid '.getmypid().' is dispatching 3 tasks to queue workers...');

        $start = microtime(true);

        try {
            $results = Concurrency::driver('queue')->run([
                'sum' => fn () => array_sum(range(1, 1000)),
                'worker' => fn () => 'ran on '.gethostname().' pid '.getmypid(),
                'slow (2s)' => function () {
                    sleep(2);

                    return 'done after sleeping 2s';
                },
            ], timeout: (int) $this->option('timeout'));
        } catch (TaskTimedOutException $e) {
            $this->components->error($e->getMessage());
            $this->components->warn('Are queue workers running? Try: php artisan queue:work --sleep=0');

            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $start, 2);

        foreach ($results as $key => $value) {
            $this->components->twoColumnDetail($key, var_export($value, true));
        }

        $this->components->info("Wall time: {$elapsed}s. With 3 workers this stays close to the slowest task (2s), not the sum.");

        $this->components->info('Now a task that throws on the worker...');

        try {
            Concurrency::driver('queue')->run([
                fn () => throw new RuntimeException('Task failed on the worker'),
            ], timeout: (int) $this->option('timeout'));
        } catch (RuntimeException $e) {
            $this->components->twoColumnDetail('Rethrown in the caller', get_class($e).': '.$e->getMessage());
            $this->components->info('The failure is also recorded in failed_jobs (php artisan queue:failed).');
        }

        return self::SUCCESS;
    }
}
