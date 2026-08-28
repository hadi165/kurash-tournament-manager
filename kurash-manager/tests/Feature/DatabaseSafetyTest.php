<?php

use App\Support\DatabaseGuard;
use Illuminate\Console\Command;
use Illuminate\Database\Console\Migrations\FreshCommand;

/**
 * The safeguard that should have existed three incidents ago.
 *
 * Every test here works on the in-memory configuration and the guard's own
 * verdict. Nothing opens a connection, and nothing names a real database except
 * to prove the guard refuses it — which is the one thing that must be checked
 * against the actual name, because "kurash" is what was emptied.
 */
beforeEach(function () {
    $this->connection = config('database.default');
    $this->originalDatabase = config("database.connections.{$this->connection}.database");
    $this->originalEnv = app()->environment();
});

afterEach(function () {
    config()->set("database.connections.{$this->connection}.database", $this->originalDatabase);
    app()['env'] = $this->originalEnv;
});

/** Point the guard at a database name without connecting to it. */
function pretendDatabase(string $name): void
{
    config()->set('database.connections.'.config('database.default').'.database', $name);
}

/** Pretend the application booted in another environment. */
function pretendEnvironment(string $env): void
{
    app()['env'] = $env;
}

describe('what it refuses', function () {
    it('refuses the competition database by name', function () {
        pretendDatabase('kurash');

        expect(DatabaseGuard::onTestDatabase())->toBeFalse()
            ->and(DatabaseGuard::safeToDestroy())->toBeFalse();

        expect(fn () => DatabaseGuard::assertSafeToDestroy('running the test suite'))
            ->toThrow(RuntimeException::class, 'REFUSED');
    });

    /** Not a blocklist of one name — anything without the suffix. */
    it('refuses any database that is not suffixed _test', function (string $name) {
        pretendDatabase($name);

        expect(DatabaseGuard::onTestDatabase())->toBeFalse()
            ->and(DatabaseGuard::safeToDestroy())->toBeFalse();
    })->with([
        'the application database' => ['kurash'],
        'production' => ['kurash_production'],
        'a hosting-panel name' => ['hadi_kurash'],
        'a near miss' => ['kurash_testing'],
        'a prefix rather than a suffix' => ['test_kurash'],
        'the word alone' => ['test'],
        'empty' => [''],
    ]);

    /**
     * The environment is not enough on its own. A suite told it is "testing"
     * while pointed at the competition database is precisely the third
     * incident, and it must still be refused.
     */
    it('refuses a test environment pointed at a real database', function () {
        pretendEnvironment('testing');
        pretendDatabase('kurash');

        expect(DatabaseGuard::inTestEnvironment())->toBeTrue()
            ->and(DatabaseGuard::onTestDatabase())->toBeFalse()
            ->and(DatabaseGuard::safeToDestroy())->toBeFalse();
    });

    /** And the suffix is not enough on its own either. Both, or nothing. */
    it('refuses a test database outside the test environment', function (string $env) {
        pretendEnvironment($env);
        pretendDatabase('kurash_test');

        expect(DatabaseGuard::onTestDatabase())->toBeTrue()
            ->and(DatabaseGuard::safeToDestroy())->toBeFalse();
    })->with(['local', 'production', 'staging']);

    it('names the database it refused, so the reason is readable', function () {
        pretendDatabase('kurash');

        $message = DatabaseGuard::refusal('php artisan migrate:fresh');

        expect($message)->toContain('kurash')
            ->and($message)->toContain('migrate:fresh')
            ->and($message)->toContain('reset-local-database.sh');
    });
});

describe('what it allows', function () {
    it('allows the configured test database in the test environment', function () {
        // The state this suite actually runs in.
        expect(DatabaseGuard::resolvedDatabase())->toEndWith('_test')
            ->and(DatabaseGuard::inTestEnvironment())->toBeTrue()
            ->and(DatabaseGuard::onTestDatabase())->toBeTrue()
            ->and(DatabaseGuard::safeToDestroy())->toBeTrue();

        expect(fn () => DatabaseGuard::assertSafeToDestroy('running the test suite'))
            ->not->toThrow(RuntimeException::class);
    });

    it('reads a sqlite file name rather than the directory holding it', function () {
        pretendDatabase('/tmp/somewhere/kurash_test.sqlite');

        expect(DatabaseGuard::onTestDatabase())->toBeTrue();
    });
});

describe('which commands are destructive', function () {
    it('names the commands that destroy data', function (string $command) {
        expect(DatabaseGuard::isDestructiveCommand($command))->toBeTrue();
    })->with(['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'db:wipe']);

    /**
     * An incremental migration is how the schema moves forward. Guarding it
     * would make the guard something people switch off.
     */
    it('leaves ordinary migrations alone', function (?string $command) {
        expect(DatabaseGuard::isDestructiveCommand($command))->toBeFalse();
    })->with(['migrate', 'migrate:status', 'migrate:install', 'kurash:backup', 'test', 'route:list', null]);
});

/**
 * The guard reads Laravel's resolved configuration, not getenv(). This is the
 * gap that lets a suite believe phpunit.xml while connecting somewhere else:
 * PHPUnit's <env> elements do not overwrite a variable already present in the
 * process environment.
 */
describe('where it reads the name from', function () {
    it('follows the resolved connection, not the environment variable', function () {
        putenv('DB_DATABASE=kurash_test');
        pretendDatabase('kurash');

        expect(DatabaseGuard::resolvedDatabase())->toBe('kurash')
            ->and(DatabaseGuard::onTestDatabase())->toBeFalse();

        putenv('DB_DATABASE');
    });

    it('follows the default connection when it changes', function () {
        config()->set('database.connections.scratch.database', 'kurash');
        config()->set('database.default', 'scratch');

        expect(DatabaseGuard::resolvedDatabase())->toBe('kurash')
            ->and(DatabaseGuard::onTestDatabase())->toBeFalse();

        config()->set('database.default', $this->connection);
    });
});

/**
 * The Artisan listener in AppServiceProvider.
 *
 * These name a database that does not exist. That is deliberate: if the guard
 * ever stopped working, the command would fail to connect rather than empty
 * something real. A test for a safeguard must not be able to cause the thing it
 * guards against.
 */
describe('destructive Artisan commands', function () {
    beforeEach(function () {
        pretendDatabase('kurash_guard_probe_does_not_exist');
        DatabaseGuard::applyCommandProhibitions();
    });

    afterEach(function () {
        // Restore the suite's own permission, since the flags are static and
        // would otherwise leak into every test that follows.
        config()->set("database.connections.{$this->connection}.database", $this->originalDatabase);
        DatabaseGuard::applyCommandProhibitions();
    });

    /**
     * Refused, not thrown. The framework prohibition makes each command return
     * FAILURE from its own handle() before it opens a connection — which is the
     * behaviour that matters, and the one that holds in a test where the
     * CommandStarting event is never dispatched at all.
     */
    it('refuses them outside a test database', function (string $command) {
        expect($this->artisan($command)->run())->toBe(Command::FAILURE);
    })->with(['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'db:wipe', 'db:seed']);

    /** APP_ENV=local is the machine running the competition. Not disposable. */
    it('refuses them in local as firmly as in production', function (string $env) {
        pretendEnvironment($env);
        DatabaseGuard::applyCommandProhibitions();

        expect($this->artisan('migrate:fresh')->run())->toBe(Command::FAILURE);
    })->with(['local', 'production', 'staging']);

    /** And they are permitted again the moment the target is a test database. */
    it('permits them once the database is a test database', function () {
        config()->set("database.connections.{$this->connection}.database", $this->originalDatabase);
        DatabaseGuard::applyCommandProhibitions();

        expect(DatabaseGuard::safeToDestroy())->toBeTrue();

        // Not actually run — proving the prohibition is lifted is enough, and
        // rebuilding the schema mid-suite would cost every later test.
        expect((new ReflectionClass(FreshCommand::class))
            ->getStaticPropertyValue('prohibitedFromRunning'))->toBeFalse();
    });

    /**
     * The guard has to leave a working way to move the schema forward, or it
     * becomes something people switch off.
     */
    it('never prohibits an incremental migration', function () {
        expect(DatabaseGuard::isDestructiveCommand('migrate'))->toBeFalse();

        expect($this->artisan('migrate:status')->run())->not->toBe(Command::FAILURE);
    });
});

/**
 * The guarded reset script.
 *
 * Only its refusals are exercised. Each one fires before the script touches a
 * database, a backup or a file, so running it here destroys nothing — which is
 * also the property being asserted.
 */
describe('the guarded reset script', function () {
    beforeEach(function () {
        $this->script = base_path('../scripts/reset-local-database.sh');

        if (! is_file($this->script)) {
            $this->markTestSkipped('reset-local-database.sh not found');
        }
    });

    it('exists and is executable', function () {
        expect(is_executable($this->script))->toBeTrue();
    });

    /** An agent, a cron job or a piped `yes` has no terminal. */
    it('refuses when stdin is not a terminal', function () {
        exec('printf "" | '.escapeshellarg($this->script).' 2>&1', $output, $status);

        expect($status)->not->toBe(0)
            ->and(implode("\n", $output))->toContain('not a terminal');
    });

    it('refuses when CI is set', function () {
        exec('CI=1 printf "" | '.escapeshellarg($this->script).' 2>&1', $output, $status);

        expect($status)->not->toBe(0);
    });

    /**
     * There must be no flag that skips the confirmation. The third incident was
     * an automated process, and every --force this codebase has would have been
     * passed by one.
     */
    it('offers no way to skip the confirmation', function () {
        $source = file_get_contents($this->script);

        // Executable lines only. The script's own header says "no --force, no
        // --yes", and prose promising the flag does not exist must not be read
        // as the flag existing. --force does appear in code, once, on
        // `php artisan migrate --force` — the non-interactive flag of a
        // NON-destructive command.
        $code = collect(explode("\n", $source))
            ->map(fn (string $line) => trim($line))
            ->reject(fn (string $line) => $line === '' || str_starts_with($line, '#'))
            ->implode("\n");

        expect($code)->not->toMatch('/--yes|--no-confirm|SKIP_CONFIRM|FORCE=1/')
            ->and($code)->toContain('[ -t 0 ]')
            ->and($source)->toContain('There is no non-interactive mode');
    });

    /** Each verification must abort rather than carry on hopefully. */
    it('aborts on a failed backup, a short backup and a user-count mismatch', function () {
        $source = file_get_contents($this->script);

        expect($source)->toContain('Backup command failed')
            ->and($source)->toContain('is empty')
            ->and($source)->toContain('too small to contain a schema')
            ->and($source)->toContain('ACCOUNT COUNT MISMATCH')
            ->and($source)->toContain('RESTORE FAILED');
    });

    /** A failure must leave the evidence behind. */
    it('never deletes a backup on any failure path', function () {
        $source = file_get_contents($this->script);

        expect($source)->not->toMatch('/rm\s+.*BACKUP/')
            ->and($source)->not->toMatch('/rm\s+.*USERS_DUMP/');
    });

    /** It must not need an exception carved into the runtime guard. */
    it('rebuilds without any command the guard refuses', function () {
        $source = file_get_contents($this->script);

        foreach (DatabaseGuard::DESTRUCTIVE_COMMANDS as $command) {
            expect($source)->not->toContain("artisan {$command}");
        }
    });
});

/** The Claude Code layer, checked as configuration rather than as behaviour. */
describe('the Claude Code deny rules', function () {
    beforeEach(function () {
        $this->settings = base_path('../.claude/settings.json');

        if (! is_file($this->settings)) {
            $this->markTestSkipped('.claude/settings.json not found');
        }

        $this->config = json_decode(file_get_contents($this->settings), true, 512, JSON_THROW_ON_ERROR);
    });

    it('denies every destructive command the incidents involved', function (string $rule) {
        expect($this->config['permissions']['deny'] ?? [])->toContain($rule);
    })->with([
        'Bash(php artisan migrate:fresh *)',
        'Bash(php artisan migrate:refresh *)',
        'Bash(php artisan migrate:reset *)',
        'Bash(php artisan migrate:rollback *)',
        'Bash(php artisan db:wipe *)',
        'Bash(./dev.sh reset *)',
        'Bash(mysql *)',
        'Bash(mariadb *)',
        'Bash(docker exec *)',
    ]);

    it('disables bypass-permissions mode', function () {
        expect($this->config['permissions']['disableBypassPermissionsMode'] ?? null)->toBe('disable');
    });

    /** Prefix rules cannot see inside `docker exec … -e "DROP …"`; the hook can. */
    it('registers the command-inspecting hook on Bash', function () {
        $hooks = collect($this->config['hooks']['PreToolUse'] ?? [])
            ->firstWhere('matcher', 'Bash');

        expect($hooks)->not->toBeNull()
            ->and($hooks['hooks'][0]['command'])->toContain('block-destructive-db.sh');

        expect(is_executable(base_path('../.claude/hooks/block-destructive-db.sh')))->toBeTrue();
    });
});

/** dev.sh must no longer carry a destructive command at all. */
it('has no destructive command left in dev.sh', function () {
    // Comments are allowed to name the command — one of them records the bug
    // this replaced, which CLAUDE.md asks for. Only executable lines are checked.
    $code = collect(file(base_path('../dev.sh')))
        ->map(fn (string $line) => trim($line))
        ->reject(fn (string $line) => $line === '' || str_starts_with($line, '#'))
        ->implode("\n");

    expect($code)->not->toContain('migrate:fresh')
        ->and($code)->not->toContain('migrate:rollback')
        ->and($code)->not->toContain('db:wipe')
        ->and($code)->toContain('reset-local-database.sh');
});
