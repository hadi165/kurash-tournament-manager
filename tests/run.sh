#!/bin/sh
# run.sh — spin up a throwaway database and server, run the mock tournament.
#
# This machine's system PHP has no pdo_sqlite, so everything runs inside the
# official php:8.3-cli image, which ships with it. Nothing here touches the
# real data/kurash.db.
set -eu

PROJECT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

docker run --rm \
    -v "${PROJECT_DIR}":/proj \
    -w /proj \
    -u "$(id -u):$(id -g)" \
    -e KURASH_DB=/proj/data/test-kurash.db \
    -e SCOREBOARD_WEBHOOK_SECRET=test-secret-do-not-use-in-production \
    -e KURASH_DEBUG=1 \
    php:8.3-cli sh -c '
        set -e
        rm -f /proj/data/test-kurash.db /proj/data/test-kurash.db-*

        # setup.php prints a one-time random administrator password; capture it.
        SETUP_OUT=$(cd /proj/app && php setup.php)
        KURASH_TEST_PASSWORD=$(printf "%s" "$SETUP_OUT" | sed -n "s/^  password: //p")
        export KURASH_TEST_PASSWORD
        if [ -z "$KURASH_TEST_PASSWORD" ]; then
            echo "Could not read the generated password from setup.php:"
            printf "%s\n" "$SETUP_OUT"
            exit 1
        fi

        php -S 127.0.0.1:8111 -t /proj/app >/tmp/server.log 2>&1 &
        SERVER_PID=$!
        trap "kill $SERVER_PID 2>/dev/null || true" EXIT

        # Wait for the server to accept connections.
        i=0
        while [ $i -lt 50 ]; do
            if php -r "exit(@fsockopen(\"127.0.0.1\", 8111) ? 0 : 1);" 2>/dev/null; then break; fi
            i=$((i + 1))
            sleep 0.1
        done

        php /proj/tests/mock-tournament.php http://127.0.0.1:8111
        STATUS=$?

        if [ $STATUS -ne 0 ]; then
            echo
            echo "--- server log ---"
            cat /tmp/server.log
        fi
        exit $STATUS
    '
