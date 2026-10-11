<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DevCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dev
        {--host=127.0.0.1 : The host address to serve the application on}
        {--port=8000 : The port to serve the application on}
        {--no-server : Do not run the PHP development server}
        {--no-queue : Do not run the queue worker}
        {--no-schedule : Do not run the schedule worker}
        {--no-reverb : Do not run the Reverb WebSocket server}
        {--dry-run : Print the commands that would be executed without running them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the development processes concurrently (server, queue, schedule, reverb)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $processes = $this->collectProcesses();

        if (empty($processes)) {
            $this->warn('No development processes selected to run.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(['Process', 'Command'], array_map(fn (array $p): array => [$p['name'], $p['command']], $processes));

            return self::SUCCESS;
        }

        $this->info('Starting DermAssist development processes...');
        foreach ($processes as $proc) {
            $this->line("  <fg=gray>•</> <fg={$proc['color']}>{$proc['name']}</>: {$proc['command']}");
        }
        $this->newLine();

        $names = implode(',', array_column($processes, 'name'));
        $colors = implode(',', array_map(fn (array $p): string => $p['hex_color'], $processes));
        $escapedCommands = implode(' ', array_map(fn (array $p): string => escapeshellarg($p['command']), $processes));

        $concurrentlyCmd = "npx --no-install concurrently -c \"{$colors}\" --names \"{$names}\" --kill-others {$escapedCommands}";

        passthru($concurrentlyCmd, $exitCode);

        return $exitCode;
    }

    /**
     * Collect active development processes based on options.
     *
     * @return array<int, array{name: string, command: string, color: string, hex_color: string}>
     */
    public function collectProcesses(): array
    {
        $processes = [];
        $host = (string) $this->option('host');
        $port = (string) $this->option('port');

        if (! $this->option('no-server')) {
            $processes[] = [
                'name' => 'server',
                'command' => "php artisan serve --host={$host} --port={$port}",
                'color' => 'blue',
                'hex_color' => '#93c5fd',
            ];
        }

        if (! $this->option('no-queue')) {
            $processes[] = [
                'name' => 'queue',
                'command' => 'php artisan queue:listen --tries=1 --timeout=0',
                'color' => 'magenta',
                'hex_color' => '#c4b5fd',
            ];
        }

        if (! $this->option('no-schedule')) {
            $processes[] = [
                'name' => 'schedule',
                'command' => 'php artisan schedule:work',
                'color' => 'yellow',
                'hex_color' => '#fde047',
            ];
        }

        if (! $this->option('no-reverb')) {
            $processes[] = [
                'name' => 'reverb',
                'command' => 'php artisan reverb:start',
                'color' => 'cyan',
                'hex_color' => '#67e8f9',
            ];
        }

        return $processes;
    }
}
