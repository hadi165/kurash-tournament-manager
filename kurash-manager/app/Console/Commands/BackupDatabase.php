<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Dump the competition database to a compressed file.
 *
 * Written as a command rather than a shell script so it can be scheduled, run
 * from the operator's own machine, and — most importantly — so it fails loudly.
 * A backup job that silently writes an empty file is worse than none, because
 * it is trusted.
 *
 * Run it before and after each competition session:
 *   php artisan kurash:backup --label=before-session-2
 */
class BackupDatabase extends Command
{
    protected $signature = 'kurash:backup
                            {--label= : Added to the filename, e.g. before-session-2}
                            {--keep=30 : How many backups to retain}
                            {--path= : Where to write (defaults to storage/app/backups)}';

    protected $description = 'Dump the database to a compressed, timestamped file';

    public function handle(): int
    {
        $connection = config('database.default');

        if (! in_array($connection, ['mysql', 'mariadb'], true)) {
            $this->error("kurash:backup supports MySQL and MariaDB; this app is on [{$connection}].");

            return self::FAILURE;
        }

        $config = config("database.connections.{$connection}");
        $directory = $this->option('path') ?: storage_path('app/backups');

        File::ensureDirectoryExists($directory);

        $label = $this->option('label');
        $name = implode('-', array_filter([
            'kurash',
            $config['database'],
            now()->format('Ymd-His'),
            $label ? preg_replace('/[^A-Za-z0-9._-]/', '', $label) : null,
        ])).'.sql.gz';

        $target = rtrim($directory, '/')."/{$name}";

        if (! $this->dump($config, $target)) {
            return self::FAILURE;
        }

        $size = File::size($target);

        // A dump smaller than a gzip header cannot contain a schema. Catching
        // it here is the difference between finding out now and finding out
        // when someone needs to restore.
        if ($size < 100) {
            $this->error("Backup is only {$size} bytes — treating that as a failure.");
            File::delete($target);

            return self::FAILURE;
        }

        $this->info("Backed up to {$target} (".number_format($size / 1024, 1).' KiB)');

        $this->prune($directory, (int) $this->option('keep'));

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $config */
    private function dump(array $config, string $target): bool
    {
        // The password goes through the environment rather than the argument
        // list, where every other user on the box could read it from `ps`.
        $command = [
            'mysqldump',
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            '--single-transaction',     // consistent dump without locking the event out
            '--quick',
            '--default-character-set=utf8mb4',
            $config['database'],
        ];

        $process = Process::fromShellCommandline(
            implode(' ', array_map('escapeshellarg', $command)).' | gzip > '.escapeshellarg($target)
        );

        $process->setTimeout(600);
        $process->setEnv(['MYSQL_PWD' => (string) $config['password']]);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            $this->error('mysqldump failed: '.trim($process->getErrorOutput() ?: $e->getMessage()));
            File::delete($target);

            return false;
        }

        return true;
    }

    private function prune(string $directory, int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        $backups = collect(File::glob(rtrim($directory, '/').'/kurash-*.sql.gz'))
            ->sortByDesc(fn (string $path) => File::lastModified($path))
            ->values();

        $stale = $backups->slice($keep);

        foreach ($stale as $path) {
            File::delete($path);
        }

        if ($stale->isNotEmpty()) {
            $this->line("Removed {$stale->count()} backup(s) beyond the most recent {$keep}.");
        }
    }
}
