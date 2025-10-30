#!/bin/bash

# Create storage framework directories with 777 permissions
mkdir -p /var/www/storage/framework/views
mkdir -p /var/www/storage/framework/cache/data
mkdir -p /var/www/storage/framework/sessions
mkdir -p /var/www/storage/framework/testing
chmod -R 777 /var/www/storage/framework

# Start npm in development mode
npm run dev &

# Start supervisor to manage PHP-FPM and scheduler
/usr/bin/supervisord -c /etc/supervisor/conf.d/supervisor.conf
