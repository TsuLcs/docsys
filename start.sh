#!/bin/sh
set -e

# Render provides PORT; default to 10000 for safety
export PORT="${PORT:-10000}"

# Inject PORT into nginx config
envsubst '${PORT}' < /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf

# Start php-fpm in background
php-fpm -D

# Start nginx in foreground (keeps container alive)
nginx -g 'daemon off;'
