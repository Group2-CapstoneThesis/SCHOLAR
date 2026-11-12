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
        // use the same bounds as the unit-tested policy instead of accepting unsafe configuration
        new \Scholar\Auth\SessionPolicy($sessionValues['SESSION_IDLE_SECONDS'], $sessionValues['SESSION_MAXIMUM_SECONDS'], $sessionValues['SESSION_RENEWAL_SECONDS']);
        $session = [
            'idle_seconds' => $sessionValues['SESSION_IDLE_SECONDS'],
            'maximum_seconds' => $sessionValues['SESSION_MAXIMUM_SECONDS'],
            'renewal_seconds' => $sessionValues['SESSION_RENEWAL_SECONDS'],
        ];

        // failed-login limits: 5 wrong tries per username, or 20 from one IP address, within 15 minutes
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

        // largest thesis PDF RDPO may upload, in megabytes; a provisional limit until P-11 sets the real one
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

        // where backups are written (M12); by default the private storage/backups folder, but RDPO or IT can point it
        // at another drive or a network folder, because a backup on the same drive is lost with that drive (FB-04)
        $backupDirectory = __DIR__ . '/../storage/backups';
        $typedDirectory = '';
        if (isset($_SERVER['BACKUP_DIRECTORY'])) {
            $typedDirectory = $_SERVER['BACKUP_DIRECTORY'];
        } elseif (isset($_ENV['BACKUP_DIRECTORY'])) {
            $typedDirectory = $_ENV['BACKUP_DIRECTORY'];
        }
        if (is_string($typedDirectory) && trim($typedDirectory) !== '') {
            $typedDirectory = trim($typedDirectory);
            // a full path is required ("D:\scholar_backups" or "/srv/backups"), so the folder never depends on
            // which folder the command happened to be started from
            if (!preg_match('/\A([A-Za-z]:[\\\\\/]|\/|\\\\\\\\)/', $typedDirectory)) {
                throw new RuntimeException('BACKUP_DIRECTORY must be a full path.');
            }
            $backupDirectory = $typedDirectory;
        }
        $backup = ['directory' => rtrim($backupDirectory, '/\\')];

        // semantic search (M14): Ollama makes the embeddings with bge-m3, Qdrant stores and compares them
        // every setting is optional; the defaults fit a local Laragon machine with both services on this computer
        $enabledText = strtolower(self::optionalSetting('SEARCH_SEMANTIC_ENABLED', 'true'));
        if ($enabledText !== 'true' && $enabledText !== 'false') {
            throw new RuntimeException('SEARCH_SEMANTIC_ENABLED must be true or false.');
        }
        $search = [
            'enabled' => $enabledText === 'true',
            'ollama_url' => rtrim(self::optionalSetting('SEARCH_OLLAMA_URL', 'http://127.0.0.1:11434'), '/'),
            'model' => self::optionalSetting('SEARCH_EMBEDDING_MODEL', 'bge-m3'),
            'qdrant_url' => rtrim(self::optionalSetting('SEARCH_QDRANT_URL', 'http://127.0.0.1:6333'), '/'),
            'qdrant_api_key' => self::optionalSetting('SEARCH_QDRANT_API_KEY', ''),
            'collection' => self::optionalSetting('SEARCH_QDRANT_COLLECTION', 'scholar_theses'),
            'min_score' => self::optionalSetting('SEARCH_MIN_SCORE', '0.5'),
        ];
        // only plain http(s)://host:port addresses, so a typo cannot turn into a request somewhere unexpected
        foreach (['ollama_url', 'qdrant_url'] as $urlKey) {
            if (!preg_match('/\Ahttps?:\/\/[A-Za-z0-9.\-]+(:[0-9]{1,5})?\z/', $search[$urlKey])) {
                throw new RuntimeException('Invalid semantic search address setting.');
            }
        }
        if (!preg_match('/\A[A-Za-z0-9._:\/\-]{1,100}\z/', $search['model']) || !preg_match('/\A[A-Za-z0-9_\-]{1,64}\z/', $search['collection'])) {
            throw new RuntimeException('Invalid semantic search model or collection setting.');
        }
        // the lowest similarity (0 to 1) a meaning-only match needs to be shown; see Scholar\Search\HybridRanking
        if (!preg_match('/\A(0(\.[0-9]{1,3})?|1(\.0{1,3})?)\z/', $search['min_score'])) {
            throw new RuntimeException('SEARCH_MIN_SCORE must be a number from 0 to 1.');
        }
        $search['min_score'] = (float) $search['min_score'];

        // AI relevance explanations through OpenRouter (abstract-only, see Scholar\Search\RelevanceExplainer)
        // both values stay on the server; the explanations are simply left out when either one is empty
        $openRouter = [
            'api_key' => self::optionalSetting('OPENROUTER_API_KEY', ''),
            'model' => self::optionalSetting('OPENROUTER_MODEL', ''),
        ];
        // OpenRouter model names look like "openai/gpt-4o-mini" or "meta-llama/llama-3.1-8b-instruct:free"
        if ($openRouter['model'] !== '' && !preg_match('/\A[A-Za-z0-9._\-]{1,64}\/[A-Za-z0-9._:\-]{1,100}\z/', $openRouter['model'])) {
            throw new RuntimeException('OPENROUTER_MODEL must look like provider/model-name.');
        }
        $openRouter['enabled'] = $openRouter['api_key'] !== '' && $openRouter['model'] !== '';

        return ['database' => $database, 'session' => $session, 'login' => $login, 'files' => $files, 'backup' => $backup, 'search' => $search, 'openrouter' => $openRouter];
    }

    // one optional .env setting as trimmed text, or $default when it is missing or empty
    private static function optionalSetting(string $key, string $default): string {
        $value = '';
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
            $value = $_SERVER[$key];
        } elseif (isset($_ENV[$key]) && is_string($_ENV[$key])) {
            $value = $_ENV[$key];
        }
        if (trim($value) === '') {
            return $default;
        }
        return trim($value);
    }
}
