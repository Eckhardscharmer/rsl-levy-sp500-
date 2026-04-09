<?php
// RSL System — Datenbank-Konfiguration
// Bitte anpassen falls Passwort gesetzt ist

define('DB_HOST',     'localhost');
define('DB_USER',     'root');
define('DB_PASS',     '');          // XAMPP-Standard: leer
define('DB_NAME',     'rsl_system');
define('DB_PORT',     3306);
define('DB_CHARSET',  'utf8mb4');

define('XAMPP_MYSQL', '/Applications/XAMPP/xamppfiles/bin/mysql');
define('XAMPP_PHP',   '/Applications/XAMPP/xamppfiles/bin/php');

/**
 * PDO-Verbindung zurückgeben (Singleton)
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage() . "\n");
        }
    }
    return $pdo;
}

/**
 * Systemkonfigurationswert lesen
 */
function getConfig(string $key, string $default = ''): string {
    $db  = getDB();
    $stmt = $db->prepare('SELECT config_value FROM system_config WHERE config_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['config_value'] : $default;
}
