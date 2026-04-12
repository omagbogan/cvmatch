<?php
// ============================================
// CVMatch IA - Configuration globale
// Standard: XAMPP local + Docker local + prod simple MySQL
// ============================================

function loadEnvFile(string $path): void {
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if ($key === '' || getenv($key) !== false) {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

loadEnvFile(__DIR__ . '/.env');

function env(string $key, $default = null) {
    $value = getenv($key);
    return $value !== false ? $value : $default;
}

define('APP_DEBUG', filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN));
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/php-error.log');

define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_PORT', env('DB_PORT', 3306));
define('DB_NAME', env('DB_NAME', 'cvmatch_db'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

// --- Application ---
define('APP_NAME', env('APP_NAME', 'CVMatch IA'));
define('APP_URL', env('APP_URL', 'http://localhost/cvmatch'));
define('APP_VERSION', env('APP_VERSION', '1.0.0'));

// --- Upload ---
define('UPLOAD_DIR', __DIR__ . '/uploads/cvs/');
define('UPLOAD_URL', APP_URL . '/uploads/cvs/');
define('UPLOAD_MAX_SIZE', 5 * 1024 * 1024); // 5 Mo
define('UPLOAD_ALLOWED_TYPES', ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png']);
define('UPLOAD_ALLOWED_EXT', ['pdf', 'docx', 'jpg', 'jpeg', 'png']);

// --- Service IA Python ---
define('IA_SERVICE_URL', env('IA_SERVICE_URL', 'http://localhost:5000'));  // URL du microservice Python
define('IA_SERVICE_TIMEOUT', env('IA_SERVICE_TIMEOUT', 300));                  // Timeout en secondes

// --- Service IA DeepSeek ---
define('DEEPSEEK_API_URL', env('DEEPSEEK_API_URL', 'https://api.deepseek.com'));
define('DEEPSEEK_API_KEY', env('DEEPSEEK_API_KEY', ''));

// --- Email (simulé - log fichier) ---
define('EMAIL_LOG_FILE', __DIR__ . '/logs/emails.log');

// --- Session ---
define('SESSION_LIFETIME', 3600 * 8); // 8 heures

// --- Sécurité ---
define('CSRF_TOKEN_NAME', 'cvmatch_csrf_token');

// ============================================
// Connexion PDO (Singleton)
// ============================================
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            if (defined('DB_PORT') && DB_PORT) {
                $dsn .= ";port=" . DB_PORT;
            }

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Connexion base de données impossible : ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// ============================================
// Démarrage de session sécurisé
// ============================================
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => false, // Mettre true en production avec HTTPS
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}

startSession();
