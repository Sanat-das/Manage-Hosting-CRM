<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CronEmailsHealthCommand extends Command
{
    protected $signature = 'cron:emails-health';
    protected $description = 'Health check for the emails queue cron - shows last drain, queue depth and cron firing';

    public function handle(): int
    {
        $jobs = DB::table('jobs')->where('queue', 'emails')->count();
        $failed = DB::table('failed_jobs')->count();
        $healthPath = storage_path('app/cron-emails-health.json');
        $health = file_exists($healthPath) ? json_decode(file_get_contents($healthPath), true) : null;

        $this->info('Emails queue depth: '.$jobs.' pending, '.$failed.' failed');
        if ($health) {
            $this->line('Health file: '.$healthPath);
            $this->line(json_encode($health, JSON_PRETTY_PRINT));
            if (isset($health['last_run'])) {
                $age = now()->diffInMinutes(\Carbon\Carbon::parse($health['last_run']));
                if ($age > 10) {
                    $this->warn('Last drain was '.$age.' min ago - cron may not be firing (expected every 1 min)');
                } else {
                    $this->info('Last drain '.$age.' min ago - OK');
                }
            }
            if (($health['heartbeat_ok'] ?? true) === false) {
                $this->warn('Heartbeat reports queue backlog');
            }
        } else {
            $this->warn('No health file yet - cron has not fired since deploy. Run: php artisan schedule:run');
        }

        $logPath = storage_path('logs/laravel.log');
        $logs = collect();
        if (file_exists($logPath)) {
            $handle = @fopen($logPath, 'r');
            if ($handle) {
                fseek($handle, 0, SEEK_END);
                $pos = ftell($handle);
                $buffer = '';
                $lines = [];
                $chunk = 4096;
                while ($pos > 0 && count($lines) < 200) {
                    $read = min($chunk, $pos);
                    $pos -= $read;
                    fseek($handle, $pos);
                    $buffer = fread($handle, $read) . $buffer;
                    $lines = explode("\n", $buffer);
                }
                fclose($handle);
                $logs = collect($lines)->filter(fn ($l) => str_contains($l, 'queue-emails-cron') || str_contains($l, 'emails-queue-heartbeat'))->slice(-5)->values();
            }
        }

        if ($logs->isNotEmpty()) {
            $this->line('');
            $this->line('Recent log entries:');
            foreach ($logs as $line) {
                $this->line('  '.substr($line, -220));
            }
        }

        return self::SUCCESS;
    }
}
