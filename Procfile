release: php artisan migrate --force
web: php artisan serve --host=0.0.0.0 --port=${PORT:-8000}
worker: php artisan queue:work --tries=3 --timeout=120 --sleep=3
