#!/usr/bin/env bash
# block-destructive-db.sh — PreToolUse gate on every Bash command.
#
# The deny rules in settings.json match a command's prefix. That is enough for
# `php artisan migrate:fresh`, and not enough for anything that hides the same
# instruction further along the line:
#
#     docker exec db mariadb -e "DROP DATABASE kurash"
#     bash -c 'cd app && php artisan migrate:fresh'
#     php -r '...DB::statement("TRUNCATE users")...'
#
# This reads the whole command string and refuses on the operation rather than
# on the program that carries it. It is the third layer: settings deny rules
# first, this second, App\Support\DatabaseGuard inside the application last.
#
# Reads the hook payload on stdin, writes a PreToolUse permission decision.
set -uo pipefail

payload=$(cat)
command=$(printf '%s' "$payload" | jq -r '.tool_input.command // empty' 2>/dev/null || true)

[ -z "$command" ] && { printf '{}'; exit 0; }

deny() {
    jq -nc --arg reason "$1" '{
      hookSpecificOutput: {
        hookEventName: "PreToolUse",
        permissionDecision: "deny",
        permissionDecisionReason: $reason
      }
    }'
    exit 0
}

# Lower-cased once, so the patterns below stay readable.
lc=$(printf '%s' "$command" | tr '[:upper:]' '[:lower:]')

# 1. Artisan commands that destroy data. `migrate` on its own is NOT here:
#    an incremental migration must keep working.
if printf '%s' "$lc" | grep -Eq 'artisan[[:space:]]+(migrate:(fresh|refresh|reset|rollback)|db:wipe)'; then
    deny "Blocked: this destroys database data. The Kurash database has been emptied three times this way. If you truly need to rebuild the LOCAL database, run ./scripts/reset-local-database.sh, which backs up and restores the users table and asks for confirmation at a terminal. See CLAUDE.md, 'Database safety'."
fi

# 2. Seeding overwrites rows that were typed in by hand.
if printf '%s' "$lc" | grep -Eq 'artisan[[:space:]]+db:seed'; then
    deny "Blocked: seeding overwrites real data. Use kurash:demo against a disposable database, or ask the user first."
fi

# 3. Raw SQL that drops, truncates or empties — wherever it appears in the
#    line, including inside docker exec, bash -c, php -r or a heredoc.
if printf '%s' "$lc" | grep -Eq '(drop[[:space:]]+(database|table|schema)|truncate[[:space:]]+(table[[:space:]]+)?|delete[[:space:]]+from)'; then
    deny "Blocked: this command contains DROP / TRUNCATE / DELETE FROM. Destructive SQL against any database is not permitted from this session. Ask the user, and use ./scripts/reset-local-database.sh if a local rebuild is really what is wanted."
fi

# 4. The interactive mysql/mariadb clients, which can run any of the above.
#    mariadb-dump and mysqldump are read-only and stay allowed — backups need
#    them, and the guarded reset script depends on them.
if printf '%s' "$lc" | grep -Eq '(^|[[:space:];&|])(mysql|mariadb)([[:space:]]|$)'; then
    deny "Blocked: direct mysql/mariadb client access. It can run any statement, including DROP. Read the database through 'php artisan tinker' or a read-only query in application code. mariadb-dump and mysqldump remain available for backups."
fi

# 5. The old unguarded reset path.
if printf '%s' "$lc" | grep -Eq 'dev\.sh[[:space:]]+reset'; then
    deny "Blocked: ./dev.sh reset destroyed the database twice. The guarded replacement is ./scripts/reset-local-database.sh."
fi

printf '{}'
