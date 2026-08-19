<?php

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

it('keeps only the most recent backups', function () {
    foreach (range(1, 4) as $i) {
        $this->artisan('kurash:backup', [
            '--path' => $this->backupPath,
            '--keep' => 2,
            '--label' => "run{$i}",
        ])->assertExitCode(0);

        // Age each file once it exists, so the run just made is always the
        // newest and "oldest" is unambiguous. Backing the timestamps into the
        // past rather than the future is what makes that true.
        $created = File::glob($this->backupPath."/*run{$i}*");
        expect($created)->toHaveCount(1);
        touch($created[0], time() - (100 - $i * 10));
    }

    $remaining = File::glob($this->backupPath.'/kurash-*.sql.gz');

    expect($remaining)->toHaveCount(2);

    // …and they are the two most recent, not an arbitrary pair.
    $names = array_map('basename', $remaining);
    sort($names);

    expect($names[0])->toContain('run3')
        ->and($names[1])->toContain('run4');
})->skip(fn () => ! hasMysqldump(), 'mysqldump is not installed');
