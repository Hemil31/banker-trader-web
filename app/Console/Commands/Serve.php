<?php

namespace App\Console\Commands;

use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;

use function Illuminate\Support\php_binary;

/**
 * `php artisan serve` that also runs the task scheduler (bootstrap/app.php
 * ->withSchedule()) for as long as the server is up, so no host cron entry is
 * needed. Overrides the framework command of the same name.
 *
 * Pass --no-schedule when a real `schedule:run` cron already exists on the
 * machine, otherwise tasks would fire twice.
 */
#[AsCommand(name: 'serve')]
class Serve extends ServeCommand
{
    protected $signature = 'serve
                    {--host= : The host address to serve the application on}
                    {--port= : The port to serve the application on}
                    {--tries=10 : The max number of ports to attempt to serve from}
                    {--no-reload : Do not reload the development server on .env file changes}
                    {--no-schedule : Do not run the task scheduler alongside the server}';

    protected ?Process $scheduler = null;

    #[\Override]
    public function handle()
    {
        if (! $this->option('no-schedule')) {
            $this->startScheduler();
        }

        try {
            return parent::handle();
        } finally {
            $this->stopScheduler();
        }
    }

    protected function startScheduler(): void
    {
        if ($this->scheduler?->isRunning()) {
            return;
        }

        $this->scheduler = new Process(
            [php_binary(), 'artisan', 'schedule:work'],
            base_path(),
            $this->schedulerEnvironment(),
            timeout: null,
        );

        // Nothing polls this process, so its output must not go through a pipe
        // (a full pipe would block schedule:work). Task output is already
        // appended to storage/logs/scheduler.log by each scheduled event.
        $this->scheduler->disableOutput();
        $this->scheduler->start();

        // exit() in the parent's signal handler skips `finally`.
        register_shutdown_function($this->stopScheduler(...));

        $this->components->info('Scheduler running (schedule:work) — task output in storage/logs/scheduler.log.');
    }

    protected function stopScheduler(): void
    {
        if ($this->scheduler?->isRunning()) {
            $this->scheduler->stop(5);
        }
    }

    /**
     * Same .env-reload rule the server process uses: when .env changes are
     * picked up live, don't freeze .env values into the child's environment.
     *
     * @return array<string, mixed>
     */
    protected function schedulerEnvironment(): array
    {
        if ($this->option('no-reload') || ! file_exists(base_path('.env'))) {
            return [];
        }

        return (new Collection($_ENV))
            ->mapWithKeys(fn ($value, $key) => [$key => $this->shouldPassThroughEnvironmentVariable($key) ? $value : false])
            ->all();
    }
}
