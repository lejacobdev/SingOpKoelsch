#!/usr/bin/env bash

cd /var/www/html8/

# Concurrent workers so a large download/request doesn't block the whole site.
export PHP_CLI_SERVER_WORKERS=4

php -S 0.0.0.0:5008 -t /var/www/html8 /var/www/html8/router.php