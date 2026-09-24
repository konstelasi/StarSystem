-- A separate database for the test suite, so `php artisan test` never
-- touches the development data.
CREATE DATABASE IF NOT EXISTS starsystem_testing;
GRANT ALL PRIVILEGES ON starsystem_testing.* TO 'starsystem'@'%';
FLUSH PRIVILEGES;
