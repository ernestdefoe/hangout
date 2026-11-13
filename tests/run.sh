#!/bin/sh
# Run the integration tests on a named database, with a table prefix (the stricter case).
#   tests/run.sh mysql | pgsql | sqlite
set -e
cd "$(dirname "$0")/.."
DRIVER=$1; shift || true
case "$DRIVER" in
  mysql) export DB_DRIVER=mysql DB_HOST=127.0.0.1 DB_PORT=${DB_PORT:-3306} DB_DATABASE=hangout_test DB_USERNAME=root DB_PASSWORD=${DB_PASSWORD:-} DB_PREFIX=hx_ ;;
  pgsql) export DB_DRIVER=pgsql DB_HOST=127.0.0.1 DB_PORT=${DB_PORT:-55435} DB_DATABASE=hangout_test DB_USERNAME=postgres DB_PASSWORD=${DB_PASSWORD:-hangout} DB_PREFIX=hx_ ;;
  *) export DB_DRIVER=sqlite DB_DATABASE=flarum_test DB_PREFIX= ;;
esac
export FLARUM_TEST_TMP_DIR_LOCAL=tests/integration/tmp
php tests/integration/setup.php | grep -E "Connecting|prefix|complete"
vendor/bin/phpunit -c tests/phpunit.integration.xml "$@"
