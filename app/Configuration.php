<?php
declare(strict_types=1); // keep typed method arguments strict

namespace Scholar;

use Dotenv\Dotenv; // for accessing credentials on .env file
use RuntimeException;

// load and check local settings without putting credentials in application code
class Configuration {
    public static function load(): array {
        $projectDirectory = __DIR__ . '/..'; // .env is at the application root
        $dotenv = Dotenv::createImmutable($projectDirectory); // existing environment values take priority
        $dotenv->safeLoad(); // read .env if present; required() below checks missing values

        // preliminary checks to catch missing settings before PDO attempts a connection
        $dotenv->required(['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME'])->notEmpty();
        $dotenv->required('DB_PASSWORD'); // key must exist, but a local blank password is allowed
        $dotenv->required('DB_PORT')->isInteger();

        $keys = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];
        $values = [];
        foreach ($keys as $key) {
            if (isset($_SERVER[$key])) {
                $values[$key] = $_SERVER[$key];
            } else {
                $values[$key] = $_ENV[$key];
            }
        }

        $database = [
            'host' => $values['DB_HOST'],
            'port' => (int) $values['DB_PORT'],
            'name' => $values['DB_DATABASE'],
            'user' => $values['DB_USERNAME'],
            'password' => $values['DB_PASSWORD'],
        ];

        // names allow only letters/digits/underscore, not extra connection-string settings
        if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $database['name'])) {
            throw new RuntimeException('Invalid database configuration.');
        }
        if ($database['port'] < 1 || $database['port'] > 65535) {
            throw new RuntimeException('Invalid database configuration.');
        }
        // allow normal hostnames/IP addresses, but reject extra PDO connection-string syntax
        if (!preg_match('/\A[a-zA-Z0-9.:-]+\z/', $database['host'])) {
            throw new RuntimeException('Invalid database configuration.');
        }

        // session stuff, 30 minutes for idle timeout, 8 hours for lifetime, every 15 minutes for session renewal
        $defaults = ['SESSION_IDLE_SECONDS' => 1800, 'SESSION_MAXIMUM_SECONDS' => 28800, 'SESSION_RENEWAL_SECONDS' => 900];
        $sessionValues = [];
        foreach ($defaults as $key => $defaultValue) {
            $value = (string) $defaultValue;
            if (isset($_SERVER[$key])) {
                $value = $_SERVER[$key];
            } elseif (isset($_ENV[$key])) {
                $value = $_ENV[$key];
            }
            if (!is_string($value) || !preg_match('/\A[0-9]{1,6}\z/', $value)) {
                throw new RuntimeException('Invalid session policy configuration.');
            }
            $sessionValues[$key] = (int) $value;
        }
        // check the bounds using SessionPolicy before accepting the configuration
        new \Scholar\Auth\SessionPolicy($sessionValues['SESSION_IDLE_SECONDS'], $sessionValues['SESSION_MAXIMUM_SECONDS'], $sessionValues['SESSION_RENEWAL_SECONDS']);
        $session = [
            'idle_seconds' => $sessionValues['SESSION_IDLE_SECONDS'],
            'maximum_seconds' => $sessionValues['SESSION_MAXIMUM_SECONDS'],
            'renewal_seconds' => $sessionValues['SESSION_RENEWAL_SECONDS'],
        ];

        // failed-login limits: 5 wrong tries per username/email, or 20 from one IP address, within 15 minutes
        // the same read-then-check steps as the session timers above; missing keys use these defaults
        $loginDefaults = ['LOGIN_ACCOUNT_MAX_FAILURES' => 5, 'LOGIN_ADDRESS_MAX_FAILURES' => 20, 'LOGIN_FAILURE_WINDOW_SECONDS' => 900];
        $loginValues = [];
        foreach ($loginDefaults as $key => $defaultValue) {
            $value = (string) $defaultValue;
            if (isset($_SERVER[$key])) {
                $value = $_SERVER[$key];
            } elseif (isset($_ENV[$key])) {
                $value = $_ENV[$key];
            }
            // digits only, so values like "-1", "5 minutes" or "1e3" are refused instead of guessed at
            if (!is_string($value) || !preg_match('/\A[0-9]{1,6}\z/', $value)) {
                throw new RuntimeException('Invalid login throttle configuration.');
            }
            $loginValues[$key] = (int) $value;
        }
        // reuse the limiter's own bounds so the .env check and the class can never disagree
        \Scholar\Auth\LoginThrottle::checkSettings($loginValues['LOGIN_ACCOUNT_MAX_FAILURES'], $loginValues['LOGIN_ADDRESS_MAX_FAILURES'], $loginValues['LOGIN_FAILURE_WINDOW_SECONDS']);
        $login = [
            'account_max_failures' => $loginValues['LOGIN_ACCOUNT_MAX_FAILURES'],
            'address_max_failures' => $loginValues['LOGIN_ADDRESS_MAX_FAILURES'],
            'window_seconds' => $loginValues['LOGIN_FAILURE_WINDOW_SECONDS'],
        ];

        // maximum thesis PDF upload size in megabytes
        $maxMegabytes = '50';
        if (isset($_SERVER['THESIS_PDF_MAX_MB'])) {
            $maxMegabytes = $_SERVER['THESIS_PDF_MAX_MB'];
        } elseif (isset($_ENV['THESIS_PDF_MAX_MB'])) {
            $maxMegabytes = $_ENV['THESIS_PDF_MAX_MB'];
        }
        // whole megabytes from 1 to 500; anything else stops the application instead of guessing
        if (!is_string($maxMegabytes) || !preg_match('/\A[0-9]{1,3}\z/', $maxMegabytes)
            || (int) $maxMegabytes < 1 || (int) $maxMegabytes > 500) {
            throw new RuntimeException('Invalid thesis PDF size configuration.');
        }
        $files = ['max_bytes' => (int) $maxMegabytes * 1048576]; // 1 MB = 1,048,576 bytes

        return ['database' => $database, 'session' => $session, 'login' => $login, 'files' => $files];
    }
}
