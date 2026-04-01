<?php
// ============================================
// CVMatch IA - Fonctions globales
// ============================================

// --- Utilisateur ---
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;

    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

// --- Affichage sécurisé (anti-XSS) ---
function clean(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function formatTaille(int $bytes): string {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', ' ') . ' Mo';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1, ',', ' ') . ' Ko';
    }
    return $bytes . ' octets';
}

// --- Initiales depuis un nom complet ---
function getInitiales(string $nom): string {
    $mots = explode(' ', trim($nom));
    $initiales = '';
    foreach ($mots as $mot) {
        $initiales .= strtoupper(mb_substr($mot, 0, 1));
    }
    return mb_substr($initiales, 0, 2);
}

// --- Messages Flash ---
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function flash(string $type, string $message): void {
    setFlash($type, $message);
}

function validateEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validatePassword(string $password): bool {
    return strlen($password) >= 6;
}

// --- CSRF Protection ---
function generateCsrfToken(): string {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function verifyCsrfToken(string $token): bool {
    return isset($_SESSION[CSRF_TOKEN_NAME])
        && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

function validateCsrfToken(string $token): bool {
    return verifyCsrfToken($token);
}

function csrfField(): string {
    $token = generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

// --- Upload de fichier ---
function uploadCV(array $file): array {
    // Vérification taille
    if ($file['size'] > UPLOAD_MAX_SIZE) {
        return ['success' => false, 'message' => 'Fichier trop volumineux (max 5 Mo).'];
    }

    // Vérification extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, UPLOAD_ALLOWED_EXT)) {
        return ['success' => false, 'message' => 'Format non autorisé (PDF, DOCX, JPG, PNG).'];
    }

    // Vérification type MIME réel
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, UPLOAD_ALLOWED_TYPES)) {
        return ['success' => false, 'message' => 'Type de fichier invalide.'];
    }

    // Création du dossier si absent
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    // Nom unique sécurisé
    $filename = uniqid('cv_', true) . '.' . $ext;
    $destination = UPLOAD_DIR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'message' => 'Erreur lors de l\'enregistrement du fichier.'];
    }

    return [
        'success'  => true,
        'message'  => 'CV téléchargé avec succès.',
        'filename' => $filename,
        'url'      => UPLOAD_URL . $filename,
        'ext'      => $ext,
        'mime'     => $mime,
        'size'     => $file['size'],
        'original' => $file['name'],
    ];
}

// --- Appel au service IA Python ---
function callIA(string $endpoint, array $data): ?array {
    $url = IA_SERVICE_URL . $endpoint;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => IA_SERVICE_TIMEOUT,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) return null;

    return json_decode($response, true);
}

function callDeepSeek(string $endpoint, array $data): ?array {
    if (empty(DEEPSEEK_API_KEY)) {
        return null;
    }

    $url = rtrim(DEEPSEEK_API_URL, '/') . '/' . ltrim($endpoint, '/');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . DEEPSEEK_API_KEY,
        ],
        CURLOPT_TIMEOUT        => IA_SERVICE_TIMEOUT,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return null;
    }

    return json_decode($response, true);
}

// --- Log email (simulé) ---
function logEmail(string $destinataire, string $sujet, string $corps): void {
    $logDir = dirname(EMAIL_LOG_FILE);
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);

    $ligne = sprintf(
        "[%s] À: %s | Sujet: %s\n%s\n%s\n",
        date('Y-m-d H:i:s'),
        $destinataire,
        $sujet,
        $corps,
        str_repeat('-', 60)
    );
    file_put_contents(EMAIL_LOG_FILE, $ligne, FILE_APPEND);
}

// --- Redirection ---
function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

// --- Vérification du rôle ---
function requireRole(string $role): void {
    if (!isLoggedIn()) {
        setFlash('error', 'Veuillez vous connecter.');
        redirect('connexion.php');
    }
    $user = getCurrentUser();
    if ($user['role'] !== $role) {
        setFlash('error', 'Accès non autorisé.');
        redirect('index.php');
    }
}