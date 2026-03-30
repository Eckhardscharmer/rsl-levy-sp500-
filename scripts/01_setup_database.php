#!/usr/bin/env php
<?php
/**
 * Sprint 1 — Setup Script
 * Erstellt die Datenbank und lädt die S&P 500-Basisliste
 *
 * Aufruf: /Applications/XAMPP/xamppfiles/bin/php scripts/01_setup_database.php
 */

chdir(dirname(__DIR__));
require_once 'config/database.php';

echo "=== RSL System — Datenbank-Setup ===\n\n";

// 1. Datenbank anlegen (ohne DB-Auswahl verbinden)
try {
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "[OK] Datenbank '" . DB_NAME . "' bereit.\n";
} catch (PDOException $e) {
    die("[FEHLER] " . $e->getMessage() . "\n");
}

// 2. Schema einlesen und ausführen
$sql = file_get_contents(__DIR__ . '/../sql/schema.sql');

// DB-Auswahl-Statement überspringen (PDO braucht einzelne Statements)
$db = getDB();

// Schema-Statements aufteilen und ausführen
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    fn($s) => strlen($s) > 5
);

$ok = 0; $skip = 0;
foreach ($statements as $stmt) {
    // CREATE DATABASE / USE überspringen (bereits erledigt)
    if (preg_match('/^(CREATE DATABASE|USE )/i', $stmt)) {
        $skip++;
        continue;
    }
    try {
        $db->exec($stmt);
        $ok++;
    } catch (PDOException $e) {
        echo "[WARNUNG] " . substr($stmt, 0, 60) . "...\n  → " . $e->getMessage() . "\n";
    }
}
echo "[OK] Schema: $ok Statements ausgeführt, $skip übersprungen.\n\n";

// 3. S&P 500-Liste von Wikipedia laden
echo "Lade S&P 500-Liste von Wikipedia...\n";
$tickers = fetchSP500FromWikipedia();

if (empty($tickers)) {
    echo "[WARNUNG] Wikipedia-Abruf fehlgeschlagen. Versuche lokale Fallback-Liste...\n";
    $tickers = loadFallbackTickers();
}

echo "  Gefunden: " . count($tickers) . " Aktien\n";

// 4. In Datenbank speichern
$inserted = 0;
$stmtStock = $db->prepare(
    'INSERT INTO stocks (ticker, name, sector, industry)
     VALUES (:ticker, :name, :sector, :industry)
     ON DUPLICATE KEY UPDATE name=VALUES(name), sector=VALUES(sector), industry=VALUES(industry)'
);
$stmtMember = $db->prepare(
    'INSERT IGNORE INTO sp500_membership (ticker, date_added, reason_added)
     VALUES (:ticker, :date_added, :reason)'
);

$db->beginTransaction();
foreach ($tickers as $t) {
    $stmtStock->execute([
        ':ticker'   => $t['ticker'],
        ':name'     => $t['name'],
        ':sector'   => $t['sector'],
        ':industry' => $t['industry'],
    ]);
    // Alle aktuellen Mitglieder als "seit mindestens 2019-06-01" eintragen
    // (Für Backtest-Zwecke; detaillierte Änderungshistorie kommt via CSV)
    $stmtMember->execute([
        ':ticker'     => $t['ticker'],
        ':date_added' => '2019-01-01',
        ':reason'     => 'Initial load from Wikipedia',
    ]);
    $inserted++;
}
$db->commit();

echo "[OK] $inserted Aktien in Datenbank gespeichert.\n\n";

// 5. Sektoren-Übersicht
$sectors = $db->query(
    'SELECT sector, COUNT(*) as cnt FROM stocks GROUP BY sector ORDER BY cnt DESC'
)->fetchAll();
echo "Sektoren-Verteilung:\n";
foreach ($sectors as $s) {
    echo sprintf("  %-45s %3d Aktien\n", $s['sector'], $s['cnt']);
}

echo "\n=== Setup abgeschlossen ===\n";
echo "Nächster Schritt: php scripts/02_download_prices.php\n";

// ============================================================
// HILFSFUNKTIONEN
// ============================================================

function fetchSP500FromWikipedia(): array {
    $url = 'https://en.wikipedia.org/wiki/List_of_S%26P_500_companies';
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => 'Mozilla/5.0 (RSL-System/1.0)',
        ]
    ]);
    $html = @file_get_contents($url, false, $ctx);
    if (!$html) return [];

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    // Erste Tabelle mit id="constituents"
    $table = $xpath->query('//table[@id="constituents"]')->item(0);
    if (!$table) return [];

    $rows   = $xpath->query('.//tr', $table);
    $result = [];
    $isHeader = true;

    foreach ($rows as $row) {
        if ($isHeader) { $isHeader = false; continue; }
        $cells = $xpath->query('.//td', $row);
        if ($cells->length < 4) continue;

        $ticker   = trim($cells->item(0)->textContent);
        $name     = trim($cells->item(1)->textContent);
        $sector   = trim($cells->item(2)->textContent);
        $industry = trim($cells->item(3)->textContent);

        // Ticker bereinigen (Wikipedia hat manchmal \n oder Links)
        $ticker = preg_replace('/\s+/', '', $ticker);
        if (strlen($ticker) < 1 || strlen($ticker) > 10) continue;

        // Yahoo Finance nutzt Punkt statt Bindestrich bei manchen Tickern
        // z.B. BRK.B bleibt BRK.B, aber BRK/B → BRK-B in Yahoo
        $ticker = str_replace('/', '-', $ticker);

        $result[] = compact('ticker', 'name', 'sector', 'industry');
    }
    return $result;
}

function loadFallbackTickers(): array {
    // Minimale Fallback-Liste mit den 11 GICS-Sektoren
    // wird durch den echten Download ersetzt
    return [
        ['ticker'=>'AAPL',  'name'=>'Apple Inc.',           'sector'=>'Information Technology', 'industry'=>'Technology Hardware'],
        ['ticker'=>'MSFT',  'name'=>'Microsoft Corp.',       'sector'=>'Information Technology', 'industry'=>'Systems Software'],
        ['ticker'=>'NVDA',  'name'=>'NVIDIA Corp.',          'sector'=>'Information Technology', 'industry'=>'Semiconductors'],
        ['ticker'=>'AMZN',  'name'=>'Amazon.com Inc.',       'sector'=>'Consumer Discretionary', 'industry'=>'Broadline Retail'],
        ['ticker'=>'GOOGL', 'name'=>'Alphabet Inc.',         'sector'=>'Communication Services', 'industry'=>'Interactive Media'],
        ['ticker'=>'META',  'name'=>'Meta Platforms Inc.',   'sector'=>'Communication Services', 'industry'=>'Interactive Media'],
        ['ticker'=>'JPM',   'name'=>'JPMorgan Chase & Co.',  'sector'=>'Financials',             'industry'=>'Diversified Banks'],
        ['ticker'=>'JNJ',   'name'=>'Johnson & Johnson',     'sector'=>'Health Care',            'industry'=>'Pharmaceuticals'],
        ['ticker'=>'XOM',   'name'=>'Exxon Mobil Corp.',     'sector'=>'Energy',                 'industry'=>'Integrated Oil & Gas'],
        ['ticker'=>'UNH',   'name'=>'UnitedHealth Group',    'sector'=>'Health Care',            'industry'=>'Managed Health Care'],
        ['ticker'=>'CAT',   'name'=>'Caterpillar Inc.',      'sector'=>'Industrials',            'industry'=>'Construction Machinery'],
        ['ticker'=>'PG',    'name'=>'Procter & Gamble Co.',  'sector'=>'Consumer Staples',       'industry'=>'Household Products'],
        ['ticker'=>'NEE',   'name'=>'NextEra Energy Inc.',   'sector'=>'Utilities',              'industry'=>'Electric Utilities'],
        ['ticker'=>'AMT',   'name'=>'American Tower Corp.',  'sector'=>'Real Estate',            'industry'=>'Specialized REITs'],
        ['ticker'=>'LIN',   'name'=>'Linde plc',             'sector'=>'Materials',              'industry'=>'Industrial Gases'],
    ];
}
