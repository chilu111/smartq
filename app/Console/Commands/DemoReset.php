<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class DemoReset extends Command
{
    protected $signature = 'smartqueue:demo-reset';

    protected $description = 'Reset and reseed the explicitly enabled local SQLite demonstration database';

    public function handle(): int
    {
        $database = config('database.connections.sqlite.database');
        $expected = database_path('database.sqlite');
        $databasePath = realpath($database) ?: $database;
        $expectedPath = realpath($expected) ?: $expected;

        if (! app()->environment('local') || ! filter_var(env('QUEUE_DEMO_MODE', false), FILTER_VALIDATE_BOOL)
            || config('database.default') !== 'sqlite' || $databasePath !== $expectedPath) {
            $this->error('Reset refused. Require APP_ENV=local, QUEUE_DEMO_MODE=true, and the repository demonstration SQLite database.');

            return self::FAILURE;
        }

        if (! $this->confirm('This permanently erases the configured demonstration SQLite database. Continue?')) {
            return self::FAILURE;
        }

        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true], $this->output);

        return self::SUCCESS;
    }
}
