<?php

namespace App\Support;

use Illuminate\Database\Console\Seeds\SeedCommand;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Refuses to let anything destructive run against a database that holds real data.
 *
 * This exists because the competition database has been emptied three times —
 * twice by a `migrate:fresh` aimed at what somebody believed was a scratch
 * database, once by an automated agent running a "throwaway script". Each time
 * the users table went with it, and each time the only thing that saved the
 * event was a backup taken minutes earlier by luck rather than by design.
 *
 * Two rules, and BOTH must hold before anything may drop, wipe, truncate or
 * refresh:
 *
 *   1. APP_ENV is exactly "testing"
 *   2. the resolved database name ends in "_test"
 *
 * The second is read from Laravel's *resolved* configuration and never from
 * getenv(). That distinction is the whole point. PHPUnit's <env> elements do
 * not overwrite a variable that is already set in the process environment, so
 * a developer with DB_DATABASE exported in their shell — or a CI runner that
 * injects one — gets a test suite pointed at production while phpunit.xml on
 * disk still reads "kurash_test". Asking the config for the name that the PDO
 * connection will actually use closes that gap; asking the environment
 * reproduces it.
 *
 * Nothing here is advisory. CLAUDE.md tells a human and a model not to do this;
 * this class is what stops them when the telling fails, which it has.
 */
final class DatabaseGuard
{
    /** The only environment in which a database may be rebuilt. */
    public const TEST_ENVIRONMENT = 'testing';

    /** The suffix that marks a database as disposable. */
    public const TEST_SUFFIX = '_test';

    /**
     * Artisan commands that destroy data.
     *
     * migrate:rollback is in here with the rest. It is reversible in principle
     * and irreversible in practice: a rollback that drops a column has thrown
     * the column's contents away, and the down() method cannot put them back.
     *
     * Plain `migrate` is deliberately absent — an incremental migration is how
     * the schema moves forward and must keep working everywhere.
     *
     * @var list<string>
     */
    public const DESTRUCTIVE_COMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'migrate:rollback',
        'db:wipe',
    ];

    /**
     * The database the application will actually connect to.
     *
     * Resolved through the connection config, so it reflects DB_URL parsing,
     * a connection override in a test, and anything a service provider has
     * rewritten — none of which getenv('DB_DATABASE') can see.
     */
    public static function resolvedDatabase(): ?string
    {
        $connection = config('database.default');

        if (! is_string($connection) || $connection === '') {
            return null;
        }

        $name = config("database.connections.{$connection}.database");

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** Does the resolved database name mark it as disposable? */
    public static function onTestDatabase(): bool
    {
        $database = self::resolvedDatabase();

        if ($database === null) {
            return false;
        }

        // basename() so a sqlite path such as /tmp/kurash_test.sqlite is judged
        // on its file name rather than on the directory it happens to sit in.
        $name = basename($database);

        // Strip a single known extension so "kurash_test.sqlite" still reads as
        // a test database; anything else is compared whole.
        $name = preg_replace('/\.(sqlite|sqlite3|db)$/i', '', $name) ?? $name;

        return str_ends_with($name, self::TEST_SUFFIX);
    }

    /** Is the application running as the test suite? */
    public static function inTestEnvironment(): bool
    {
        return app()->environment(self::TEST_ENVIRONMENT);
    }

    /** Both conditions, which is the only state in which data may be destroyed. */
    public static function safeToDestroy(): bool
    {
        return self::inTestEnvironment() && self::onTestDatabase();
    }

    /**
     * Abort unless this process may destroy data.
     *
     * Throws rather than returning a boolean because every caller is a point of
     * no return, and a caller that forgot to check a return value is exactly the
     * failure this class exists to prevent.
     *
     * @param  string  $operation  What was about to happen, named in the message.
     *
     * @throws RuntimeException
     */
    public static function assertSafeToDestroy(string $operation): void
    {
        if (self::safeToDestroy()) {
            return;
        }

        throw new RuntimeException(self::refusal($operation));
    }

    /**
     * Prohibit or release the destructive Artisan commands, framework-side.
     *
     * DB::prohibitDestructiveCommands() sets a static flag on each command
     * class, which every command checks in its own handle(). That is the only
     * mechanism that holds on all three routes into a command: the CLI, a
     * queued Artisan::call(), and a test. The CommandStarting event does not —
     * Illuminate\Foundation\Console\Kernel only reroutes the Symfony console
     * events when the application is NOT running unit tests, so an event-based
     * guard is silently absent from exactly the place a stray script runs.
     *
     * db:seed is added by hand because the framework helper covers the five
     * schema commands and not the one that overwrites rows.
     *
     * Re-callable: the prohibition reflects the configuration at the moment it
     * is applied, so anything that changes the resolved connection must call
     * this again.
     */
    public static function applyCommandProhibitions(): void
    {
        $prohibit = ! self::safeToDestroy();

        DB::prohibitDestructiveCommands($prohibit);
        SeedCommand::prohibit($prohibit);
    }

    /** Is this artisan command one that destroys data? */
    public static function isDestructiveCommand(?string $command): bool
    {
        return $command !== null && in_array($command, self::DESTRUCTIVE_COMMANDS, true);
    }

    /**
     * The message. Written for whoever is staring at a red terminal at an event.
     *
     * It names the database it refused to touch, because the commonest cause is
     * someone believing they were pointed somewhere else.
     */
    public static function refusal(string $operation): string
    {
        $database = self::resolvedDatabase() ?? '(none resolved)';
        $environment = app()->environment();

        return implode(PHP_EOL, [
            "REFUSED: {$operation}",
            '',
            "  database:    {$database}",
            "  environment: {$environment}",
            '',
            '  This would destroy data. It is allowed only when BOTH hold:',
            '    - APP_ENV is "'.self::TEST_ENVIRONMENT.'"',
            '    - the resolved database name ends in "'.self::TEST_SUFFIX.'"',
            '',
            '  The Kurash database has been emptied three times this way. If you',
            '  genuinely mean to rebuild the local database, use the one guarded',
            '  path, which backs up and restores the users table around the',
            '  rebuild and asks you to confirm at a terminal:',
            '',
            '      ./scripts/reset-local-database.sh',
            '',
            '  Do not work around this by editing phpunit.xml, exporting',
            '  DB_DATABASE, or pointing the test suite at the application',
            '  database. See CLAUDE.md, "Database safety".',
        ]);
    }
}
