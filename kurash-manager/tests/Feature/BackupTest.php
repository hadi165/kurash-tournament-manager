<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->backupPath = storage_path('framework/testing/backups');
    File::deleteDirectory($this->backupPath);
});

afterEach(function () {
    File::deleteDirectory($this->backupPath);
});

/** mysqldump is a system binary, not a composer dependency. */
function hasMysqldump(): bool
{
    exec('command -v mysqldump', $out, $status);

    return $status === 0;
}

it('refuses to run against a connection it cannot dump', function () {
    $original = config('database.default');

    try {
        config(['database.default' => 'sqlite']);

        $this->artisan('kurash:backup', ['--path' => $this->backupPath])
            ->expectsOutputToContain('supports MySQL and MariaDB')
            ->assertExitCode(1);
    } finally {
        // Restored before teardown, which otherwise rolls the test transaction
        // back on a sqlite connection that does not exist.
        config(['database.default' => $original]);
    }
});

/**
 * Structure only.
 *
 * Rows created by a test live inside its uncommitted transaction, and
 * mysqldump connects separately, so it cannot see them — that is the test
 * harness, not the command. Whether a dump restores with its data was verified
 * end to end against the development database: dumped, restored into a scratch
 * schema, and the athletes counted back.
 */
it('writes a compressed dump of the competition schema', function () {
    $this->artisan('kurash:backup', ['--path' => $this->backupPath])->assertExitCode(0);

    $files = File::glob($this->backupPath.'/kurash-*.sql.gz');

    expect($files)->toHaveCount(1);

    $contents = gzdecode((string) File::get($files[0]));

    expect($contents)->toContain('CREATE TABLE `athletes`')
        ->and($contents)->toContain('CREATE TABLE `bouts`')
        ->and($contents)->toContain('Dump completed');
})->skip(fn () => ! hasMysqldump(), 'mysqldump is not installed');

it('puts the label in the filename so a session backup is findable', function () {
    $this->artisan('kurash:backup', [
        '--path' => $this->backupPath,
        '--label' => 'before-session-2',
    ])->assertExitCode(0);

    expect(File::glob($this->backupPath.'/*before-session-2*'))->toHaveCount(1);
})->skip(fn () => ! hasMysqldump(), 'mysqldump is not installed');

/** The label reaches a shell, so it must not be able to escape the filename. */
it('strips path separators out of a label', function () {
    $this->artisan('kurash:backup', [
        '--path' => $this->backupPath,
        '--label' => '../../etc/passwd',
    ])->assertExitCode(0);

    $files = File::glob($this->backupPath.'/kurash-*.sql.gz');

    expect($files)->toHaveCount(1)
        ->and(basename($files[0]))->not->toContain('/')
        ->and(basename($files[0]))->toContain('etcpasswd');
})->skip(fn () => ! hasMysqldump(), 'mysqldump is not installed');

/**
 * The command used to prune to --keep=30. It does not any more, and this is the
 * test that used to assert the opposite.
 *
 * Automatic deletion of backups was tidiness dressed as housekeeping. The three
 * times the Kurash database has been emptied, what saved it was an older backup
 * nobody had planned to need — and a retention window is exactly what would
 * have thrown that away. Backups are now removed by a person who has decided to
 * remove them.
 */
it('never deletes an existing backup', function () {
    foreach (range(1, 4) as $i) {
        $this->artisan('kurash:backup', [
            '--path' => $this->backupPath,
            '--label' => "run{$i}",
        ])->assertExitCode(0);

        $created = File::glob($this->backupPath."/*run{$i}*");
        expect($created)->toHaveCount(1);

        // Age each file so any retention rule would have something to discard.
        touch($created[0], time() - (100 - $i * 10));
    }

    $remaining = array_map('basename', File::glob($this->backupPath.'/kurash-*.sql.gz'));
    sort($remaining);

    expect($remaining)->toHaveCount(4);

    foreach (range(1, 4) as $i) {
        expect(implode(' ', $remaining))->toContain("run{$i}");
    }
})->skip(fn () => ! hasMysqldump(), 'mysqldump is not installed');

/** And there is no option left that could be asked to delete one. */
it('offers no retention option at all', function () {
    $definition = $this->app->make(Kernel::class)
        ->all()['kurash:backup']->getDefinition();

    expect($definition->hasOption('keep'))->toBeFalse();
});
