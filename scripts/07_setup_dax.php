#!/usr/bin/env php
<?php
/**
 * DAX Setup Script
 * - Migriert DB (universe-Spalten, dax_membership-Tabelle)
 * - Lädt aktuelle DAX 40-Zusammensetzung von Wikipedia
 * - Lädt historische DAX-Änderungen von Wikipedia (ab ~2010)
 *
 * Aufruf: /Applications/XAMPP/xamppfiles/bin/php scripts/07_setup_dax.php
 */

chdir(dirname(__DIR__));
require_once 'config/database.php';

echo "=== DAX Setup ===\n\n";

$db = getDB();

// ============================================================
// 1. DB-Migration (für bestehende Installationen)
// ============================================================
echo "1. Datenbank-Migration...\n";

$migrations = [
    "ALTER TABLE stocks ADD COLUMN IF NOT EXISTS universe VARCHAR(10) NOT NULL DEFAULT 'sp500' COMMENT 'sp500 oder dax'",
    "ALTER TABLE stocks ADD INDEX IF NOT EXISTS idx_universe (universe)",
    "ALTER TABLE rsl_rankings ADD COLUMN IF NOT EXISTS universe VARCHAR(10) NOT NULL DEFAULT 'sp500' COMMENT 'sp500 oder dax'",
    "ALTER TABLE rsl_rankings ADD INDEX IF NOT EXISTS idx_universe (universe)",
    // UNIQUE KEY anpassen: alte uk_date_ticker durch neue uk_date_ticker_universe ersetzen
    // (nur nötig wenn alter Key noch existiert)
];

foreach ($migrations as $sql) {
    try {
        $db->exec($sql);
        echo "  [OK] " . substr($sql, 0, 70) . "...\n";
    } catch (PDOException $e) {
        // Ignoriere "Duplicate key name" Fehler
        if (strpos($e->getMessage(), 'Duplicate key') === false) {
            echo "  [WARN] " . $e->getMessage() . "\n";
        }
    }
}

// UNIQUE KEY migrieren (von uk_date_ticker → uk_date_ticker_universe)
try {
    $db->exec("ALTER TABLE rsl_rankings DROP INDEX uk_date_ticker");
    $db->exec("ALTER TABLE rsl_rankings ADD UNIQUE KEY uk_date_ticker_universe (ranking_date, ticker, universe)");
    echo "  [OK] UNIQUE KEY auf rsl_rankings migriert.\n";
} catch (PDOException $e) {
    // Entweder existiert der alte Key nicht mehr, oder der neue schon — beides OK
}

// dax_membership Tabelle anlegen
$db->exec("
    CREATE TABLE IF NOT EXISTS dax_membership (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        ticker        VARCHAR(20) NOT NULL,
        date_added    DATE        NOT NULL,
        date_removed  DATE        NULL COMMENT 'NULL = aktuell Mitglied',
        reason_added  VARCHAR(255),
        reason_removed VARCHAR(255),
        INDEX idx_ticker      (ticker),
        INDEX idx_date_added  (date_added),
        INDEX idx_date_removed (date_removed)
    ) ENGINE=InnoDB
");
echo "  [OK] dax_membership Tabelle bereit.\n\n";

// ============================================================
// 2. Aktuelle DAX 40-Zusammensetzung von Wikipedia
// ============================================================
echo "2. Lade aktuelle DAX 40-Zusammensetzung von Wikipedia...\n";
$currentMembers = fetchCurrentDAX40();

if (empty($currentMembers)) {
    echo "  [FEHLER] Wikipedia-Abruf fehlgeschlagen. Verwende Fallback-Liste.\n";
    $currentMembers = getFallbackDAX40();
}

echo "  Gefunden: " . count($currentMembers) . " aktuelle DAX-Mitglieder\n";

// ============================================================
// 3. Historische DAX-Änderungen von Wikipedia
// ============================================================
echo "\n3. Lade historische DAX-Änderungen von Wikipedia...\n";
$historicalChanges = fetchDAXHistory();
echo "  Gefunden: " . count($historicalChanges) . " historische Änderungen\n";

// ============================================================
// 4. In Datenbank speichern
// ============================================================
echo "\n4. Speichere in Datenbank...\n";

$stmtStock = $db->prepare(
    "INSERT INTO stocks (ticker, name, sector, industry, universe)
     VALUES (:ticker, :name, :sector, :industry, 'dax')
     ON DUPLICATE KEY UPDATE
       name=VALUES(name), sector=VALUES(sector),
       industry=VALUES(industry), universe='dax'"
);

// Aktuelle Mitglieder einfügen
$insertedStocks = 0;
foreach ($currentMembers as $m) {
    $stmtStock->execute([
        ':ticker'   => $m['ticker'],
        ':name'     => $m['name'],
        ':sector'   => $m['sector'],
        ':industry' => $m['industry'] ?? $m['sector'],
    ]);
    $insertedStocks++;
}
echo "  [OK] $insertedStocks Aktien in stocks-Tabelle gespeichert.\n";

// ============================================================
// 5. dax_membership aufbauen
// ============================================================
echo "\n5. Baue DAX-Mitgliedschaft auf...\n";

// Bestehende Einträge löschen (bei Wiederholung des Scripts)
$db->exec("DELETE FROM dax_membership");

// Mitgliedschafts-Zeitlinien aus historischen Änderungen rekonstruieren
$membership = buildMembershipTimeline($currentMembers, $historicalChanges);

$stmtMember = $db->prepare(
    "INSERT INTO dax_membership (ticker, date_added, date_removed, reason_added, reason_removed)
     VALUES (:ticker, :date_added, :date_removed, :reason_added, :reason_removed)"
);

$insertedMemberships = 0;
foreach ($membership as $entry) {
    // Stelle sicher, dass der Ticker in stocks existiert (historische Mitglieder)
    try {
        $db->prepare(
            "INSERT IGNORE INTO stocks (ticker, name, sector, industry, universe)
             VALUES (:ticker, :name, :sector, :industry, 'dax')"
        )->execute([
            ':ticker'   => $entry['ticker'],
            ':name'     => $entry['name'] ?? $entry['ticker'],
            ':sector'   => $entry['sector'] ?? 'Unknown',
            ':industry' => $entry['sector'] ?? 'Unknown',
        ]);
    } catch (PDOException $e) {}

    $stmtMember->execute([
        ':ticker'        => $entry['ticker'],
        ':date_added'    => $entry['date_added'],
        ':date_removed'  => $entry['date_removed'],
        ':reason_added'  => $entry['reason_added'] ?? null,
        ':reason_removed'=> $entry['reason_removed'] ?? null,
    ]);
    $insertedMemberships++;
}

echo "  [OK] $insertedMemberships Mitgliedschafts-Einträge gespeichert.\n";

// ============================================================
// 6. Übersicht
// ============================================================
echo "\n6. Übersicht:\n";

$currentCount = $db->query(
    "SELECT COUNT(*) FROM dax_membership WHERE date_removed IS NULL"
)->fetchColumn();
$historicalCount = $db->query(
    "SELECT COUNT(*) FROM dax_membership WHERE date_removed IS NOT NULL"
)->fetchColumn();
$earliestDate = $db->query(
    "SELECT MIN(date_added) FROM dax_membership"
)->fetchColumn();

echo "  Aktuelle DAX-Mitglieder: $currentCount\n";
echo "  Historische Aussteiger:  $historicalCount\n";
echo "  Datenbeginn:             $earliestDate\n";

echo "\n  DAX-Sektoren:\n";
$sectors = $db->query(
    "SELECT s.sector, COUNT(*) as cnt FROM dax_membership dm
     JOIN stocks s ON s.ticker = dm.ticker
     WHERE dm.date_removed IS NULL
     GROUP BY s.sector ORDER BY cnt DESC"
)->fetchAll();
foreach ($sectors as $s) {
    echo sprintf("    %-45s %2d Aktien\n", $s['sector'], $s['cnt']);
}

echo "\n=== DAX-Setup abgeschlossen ===\n";
echo "Nächster Schritt: php scripts/02_download_prices.php --universe=dax\n";
echo "Danach:           php scripts/03_calculate_rsl.php --universe=dax\n";

// ============================================================
// HILFSFUNKTIONEN
// ============================================================

function fetchCurrentDAX40(): array
{
    $url = 'https://en.wikipedia.org/wiki/DAX';
    $ctx = stream_context_create([
        'http' => ['timeout' => 30, 'user_agent' => 'Mozilla/5.0 (RSL-System/1.0)'],
    ]);
    $html = @file_get_contents($url, false, $ctx);
    if (!$html) return [];

    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);

    // Tabelle mit wikitable-Klasse suchen, die "Ticker" oder "Symbol" in der Header-Zeile hat
    $tables = $xpath->query('//table[contains(@class,"wikitable")]');
    $result = [];

    foreach ($tables as $table) {
        $headers = $xpath->query('.//th', $table);
        $headerTexts = [];
        foreach ($headers as $h) {
            $headerTexts[] = strtolower(trim($h->textContent));
        }

        // Prüfe ob dies die Constituents-Tabelle ist
        $hasCompany = false;
        $hasTicker  = false;
        foreach ($headerTexts as $ht) {
            if (str_contains($ht, 'compan') || str_contains($ht, 'name')) $hasCompany = true;
            if (str_contains($ht, 'ticker') || str_contains($ht, 'symbol')) $hasTicker = true;
        }
        if (!$hasCompany || !$hasTicker) continue;

        // Spalten-Indices bestimmen
        $colCompany  = -1; $colTicker = -1; $colSector = -1;
        foreach ($headerTexts as $i => $ht) {
            if ($colCompany < 0 && (str_contains($ht, 'compan') || str_contains($ht, 'name'))) $colCompany = $i;
            if ($colTicker  < 0 && (str_contains($ht, 'ticker') || str_contains($ht, 'symbol'))) $colTicker = $i;
            if ($colSector  < 0 && str_contains($ht, 'industr')) $colSector = $i;
        }
        if ($colCompany < 0 || $colTicker < 0) continue;

        $rows = $xpath->query('.//tr', $table);
        $isFirst = true;
        foreach ($rows as $row) {
            if ($isFirst) { $isFirst = false; continue; }
            $cells = $xpath->query('.//td', $row);
            if ($cells->length < 2) continue;

            $name     = cleanText($cells->item($colCompany)?->textContent ?? '');
            $rawTicker = cleanText($cells->item($colTicker)?->textContent ?? '');
            $sector   = $colSector >= 0 ? cleanText($cells->item($colSector)?->textContent ?? '') : 'Unknown';

            if (empty($rawTicker) || empty($name)) continue;

            // Yahoo Finance Ticker: XETRA-Symbol + .DE
            $ticker = yahooTicker($rawTicker);
            if (empty($ticker)) continue;

            $result[$ticker] = [
                'ticker'   => $ticker,
                'name'     => $name,
                'sector'   => mapSector($sector),
                'industry' => $sector,
                'xetra'    => $rawTicker,
            ];

            // Nur 40 Mitglieder (Sicherheitslimit)
            if (count($result) >= 45) break;
        }

        if (!empty($result)) break; // Erste passende Tabelle reicht
    }

    return array_values($result);
}

function fetchDAXHistory(): array
{
    $url = 'https://en.wikipedia.org/wiki/DAX';
    $ctx = stream_context_create([
        'http' => ['timeout' => 30, 'user_agent' => 'Mozilla/5.0 (RSL-System/1.0)'],
    ]);
    $html = @file_get_contents($url, false, $ctx);
    if (!$html) return [];

    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);

    $changes = [];

    // Alle wikitables durchsuchen
    $tables = $xpath->query('//table[contains(@class,"wikitable")]');
    foreach ($tables as $table) {
        $headers = $xpath->query('.//th', $table);
        $headerTexts = [];
        foreach ($headers as $h) {
            $headerTexts[] = strtolower(trim($h->textContent));
        }

        // Tabelle mit Datum und Additions/Removals
        $hasDate   = false;
        $hasAdded  = false;
        $hasRemoved = false;
        foreach ($headerTexts as $ht) {
            if (str_contains($ht, 'date') || str_contains($ht, 'datum')) $hasDate = true;
            if (str_contains($ht, 'add') || str_contains($ht, 'replac') || str_contains($ht, 'join')) $hasAdded = true;
            if (str_contains($ht, 'remov') || str_contains($ht, 'replac') || str_contains($ht, 'left')) $hasRemoved = true;
        }
        if (!$hasDate) continue;

        // Spalten bestimmen
        $colDate    = -1; $colAdded = -1; $colRemoved = -1; $colNotes = -1;
        foreach ($headerTexts as $i => $ht) {
            if ($colDate < 0 && (str_contains($ht, 'date') || str_contains($ht, 'datum'))) $colDate = $i;
            if ($colAdded < 0 && (str_contains($ht, 'add') || str_contains($ht, 'join') || str_contains($ht, 'includ'))) $colAdded = $i;
            if ($colRemoved < 0 && (str_contains($ht, 'remov') || str_contains($ht, 'exclud') || str_contains($ht, 'left'))) $colRemoved = $i;
            // "Replaced by" Spalte — oft: added ist "Replaced by", removed ist erstes
            if ($colAdded < 0 && str_contains($ht, 'replac')) $colAdded = $i;
            if ($colNotes < 0 && str_contains($ht, 'note')) $colNotes = $i;
        }
        if ($colDate < 0) continue;

        $rows = $xpath->query('.//tr', $table);
        $isFirst = true;
        foreach ($rows as $row) {
            if ($isFirst) { $isFirst = false; continue; }
            $cells = $xpath->query('.//td', $row);
            if ($cells->length < 2) continue;

            $dateStr  = cleanText($cells->item($colDate)?->textContent ?? '');
            $date     = parseDate($dateStr);
            if (!$date || $date < '2009-01-01') continue;

            $addedName   = $colAdded >= 0   ? cleanText($cells->item($colAdded)?->textContent ?? '')   : '';
            $removedName = $colRemoved >= 0 ? cleanText($cells->item($colRemoved)?->textContent ?? '') : '';
            $notes       = $colNotes >= 0   ? cleanText($cells->item($colNotes)?->textContent ?? '')   : '';

            if (!empty($addedName) || !empty($removedName)) {
                $changes[] = [
                    'date'         => $date,
                    'added_name'   => $addedName,
                    'removed_name' => $removedName,
                    'notes'        => $notes,
                ];
            }
        }
    }

    // Nach Datum sortieren (älteste zuerst)
    usort($changes, fn($a, $b) => strcmp($a['date'], $b['date']));
    return $changes;
}

/**
 * Aus aktuellen Mitgliedern + historischen Änderungen eine vollständige
 * Mitgliedschafts-Zeitlinie bauen.
 */
function buildMembershipTimeline(array $currentMembers, array $historicalChanges): array
{
    // Manuelle Mapping-Tabelle: Wikipedia-Firmenname → Yahoo Finance Ticker
    // (wird gebraucht um historische Änderungen den richtigen Tickern zuzuordnen)
    static $nameToTicker = [
        // Aktuelle DAX-Mitglieder (werden unten dynamisch befüllt)
    ];

    // Mapping aus aktuellen Mitgliedern aufbauen
    foreach ($currentMembers as $m) {
        $key = strtolower(preg_replace('/[^a-z0-9]/i', '', $m['name']));
        $nameToTicker[$key] = $m['ticker'];
    }

    // Bekannte historische Ticker-Mappings (häufige Ex-DAX-Mitglieder)
    $historicalTickerMap = getHistoricalTickerMap();

    $entries = [];
    $today   = date('Y-m-d');

    // Alle aktuellen Mitglieder: date_removed = NULL
    // Da wir keine genauen Aufnahmedaten haben, nutzen wir Standarddaten
    $currentTickers = [];
    foreach ($currentMembers as $m) {
        $currentTickers[$m['ticker']] = $m;
        // Aufnahmedatum: versuche aus historischen Änderungen zu ermitteln
        $entries[$m['ticker']] = [
            'ticker'        => $m['ticker'],
            'name'          => $m['name'],
            'sector'        => $m['sector'],
            'date_added'    => '2009-06-01',  // Warmup-Datum als Fallback
            'date_removed'  => null,
            'reason_added'  => 'Aktuelles DAX-Mitglied',
            'reason_removed'=> null,
        ];
    }

    // Historische Änderungen verarbeiten
    foreach ($historicalChanges as $change) {
        $date         = $change['date'];
        $addedName    = $change['added_name'];
        $removedName  = $change['removed_name'];

        // Ticker für "added" bestimmen
        if (!empty($addedName)) {
            $addedTicker = resolveTicker($addedName, $nameToTicker, $historicalTickerMap);
            if ($addedTicker && isset($entries[$addedTicker])) {
                // Aufnahmedatum aus Änderungs-Log präzisieren
                if ($date > '2009-06-01') {
                    $entries[$addedTicker]['date_added'] = $date;
                    $entries[$addedTicker]['reason_added'] = "Aufgenommen: ersetzt $removedName";
                }
            } elseif ($addedTicker) {
                // Ticker war mal im DAX, ist es jetzt wieder
                if (!isset($entries[$addedTicker])) {
                    $entries[$addedTicker] = [
                        'ticker'        => $addedTicker,
                        'name'          => $addedName,
                        'sector'        => $nameToTicker[$addedTicker]['sector'] ?? 'Unknown',
                        'date_added'    => $date,
                        'date_removed'  => null,
                        'reason_added'  => "Aufgenommen: ersetzt $removedName",
                        'reason_removed'=> null,
                    ];
                }
            }
        }

        // Ticker für "removed" bestimmen
        if (!empty($removedName)) {
            $removedTicker = resolveTicker($removedName, $nameToTicker, $historicalTickerMap);
            if ($removedTicker) {
                if (!isset($entries[$removedTicker])) {
                    $entries[$removedTicker] = [
                        'ticker'        => $removedTicker,
                        'name'          => $removedName,
                        'sector'        => 'Unknown',
                        'date_added'    => '2009-06-01',
                        'date_removed'  => $date,
                        'reason_added'  => 'Historisches DAX-Mitglied',
                        'reason_removed'=> "Ausgeschieden: ersetzt durch $addedName",
                    ];
                } elseif (!isset($currentTickers[$removedTicker])) {
                    // Kein aktuelles Mitglied → wurde entfernt
                    $entries[$removedTicker]['date_removed']   = $date;
                    $entries[$removedTicker]['reason_removed'] = "Ausgeschieden: ersetzt durch $addedName";
                }
            }
        }
    }

    return array_values($entries);
}

function resolveTicker(string $name, array $nameToTicker, array $historicalMap): ?string
{
    // Direkt im historischen Map
    $cleanName = strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
    if (isset($historicalMap[$cleanName])) return $historicalMap[$cleanName];

    // Im dynamischen Name→Ticker Map
    if (isset($nameToTicker[$cleanName])) return $nameToTicker[$cleanName];

    // Fuzzy: erster Treffer bei Teilstring-Match
    foreach ($nameToTicker as $key => $ticker) {
        if (str_contains($cleanName, $key) || str_contains($key, $cleanName)) {
            return $ticker;
        }
    }
    return null;
}

/**
 * Bekannte historische DAX-Mitglieder (Ex-Mitglieder seit 2009)
 * Firmenname (lowercase, alphanumerisch) → Yahoo Finance Ticker
 */
function getHistoricalTickerMap(): array
{
    return [
        // Ex-DAX-Mitglieder (entfernt zwischen 2009 und heute)
        'commerzbankag'         => 'CBK.DE',
        'commerzbank'           => 'CBK.DE',
        'thyssen'               => 'TKA.DE',
        'thyssenkrupp'          => 'TKA.DE',
        'thyssenkruppag'        => 'TKA.DE',
        'rwe'                   => 'RWE.DE',
        'rweag'                 => 'RWE.DE',
        'eon'                   => 'EOAN.DE',
        'eonse'                 => 'EOAN.DE',
        'eonag'                 => 'EOAN.DE',
        'linde'                 => 'LIN.DE',
        'lindeag'               => 'LIN.DE',
        'bayer'                 => 'BAYN.DE',
        'bayerag'               => 'BAYN.DE',
        'basf'                  => 'BAS.DE',
        'basfse'                => 'BAS.DE',
        'volkswagen'            => 'VOW3.DE',
        'volkswagenag'          => 'VOW3.DE',
        'bmw'                   => 'BMW.DE',
        'bmwag'                 => 'BMW.DE',
        'mercedes'              => 'MBG.DE',
        'mercedesbenz'          => 'MBG.DE',
        'mercedesbenzbenz'      => 'MBG.DE',
        'mercedesbenzgroup'     => 'MBG.DE',
        'daimler'               => 'MBG.DE',
        'daimlerbenz'           => 'MBG.DE',
        'daimlerag'             => 'MBG.DE',
        'siemens'               => 'SIE.DE',
        'siemensag'             => 'SIE.DE',
        'deutschetelekom'       => 'DTE.DE',
        'deutschetelekomag'     => 'DTE.DE',
        'telekom'               => 'DTE.DE',
        'allianz'               => 'ALV.DE',
        'allianzse'             => 'ALV.DE',
        'sap'                   => 'SAP.DE',
        'sapse'                 => 'SAP.DE',
        'münchenerrück'         => 'MUV2.DE',
        'munichre'              => 'MUV2.DE',
        'munchenerruck'         => 'MUV2.DE',
        'münchenerrückversicherung' => 'MUV2.DE',
        'adidas'                => 'ADS.DE',
        'adidasag'              => 'ADS.DE',
        'fresenius'             => 'FRE.DE',
        'freseniusmedicalcare'  => 'FME.DE',
        'fresheniusmedicalcare' => 'FME.DE',
        'fmc'                   => 'FME.DE',
        'infineon'              => 'IFX.DE',
        'infineonag'            => 'IFX.DE',
        'infineonechnologies'   => 'IFX.DE',
        'merck'                 => 'MRK.DE',
        'merckag'               => 'MRK.DE',
        'merckkommanditgesellschaftaufaktien' => 'MRK.DE',
        'henkel'                => 'HNKG.DE',  // Vorzüge
        'henkelag'              => 'HNKG.DE',
        'mutuaheinrich'         => '',
        'wirecard'              => '',  // insolvent, kein Ticker mehr
        'wirecardmag'           => '',
        'beiersdorf'            => 'BEI.DE',
        'beiersdorfag'          => 'BEI.DE',
        'continental'           => 'CON.DE',
        'continentalag'         => 'CON.DE',
        'covestro'              => '1COV.DE',
        'coverstro'             => '1COV.DE',
        'covestroag'            => '1COV.DE',
        'deuche'                => '',
        'vonovia'               => 'VNA.DE',
        'vonoviase'             => 'VNA.DE',
        'hellofresh'            => 'HFG.DE',
        'hellofreshse'          => 'HFG.DE',
        'puma'                  => 'PUM.DE',
        'pumaag'                => 'PUM.DE',
        'deutz'                 => 'DEZ.DE',
        'mannesmanna'           => '',  // historisch aufgelöst
        'schering'              => '',  // von Bayer übernommen
        'metro'                 => 'B4B.DE',
        'metroag'               => 'B4B.DE',
        'evonik'                => 'EVK.DE',
        'evonikindustries'      => 'EVK.DE',
        'evonikindustriesag'    => 'EVK.DE',
        'airbus'                => 'AIR.DE',
        'airbusse'              => 'AIR.DE',
        'symrise'               => 'SY1.DE',
        'symriseag'             => 'SY1.DE',
        'zalando'               => 'ZAL.DE',
        'zalandose'             => 'ZAL.DE',
        'qiagen'                => 'QIA.DE',
        'qiagennv'              => 'QIA.DE',
        'brenntag'              => 'BNR.DE',
        'brenntagse'            => 'BNR.DE',
        'porsche'               => 'PAH3.DE',  // Holding
        'porscheautomobilholding' => 'PAH3.DE',
        'sap'                   => 'SAP.DE',
        'heidelbergmaterials'   => 'HLBK.DE',
        'heidelbergcement'      => 'HLBK.DE',
        'heidelbergcementag'    => 'HLBK.DE',
    ];
}

/**
 * XETRA-Ticker → Yahoo Finance Ticker (meist + .DE)
 */
function yahooTicker(string $xetra): string
{
    $xetra = trim($xetra);
    if (empty($xetra)) return '';
    // Bereits mit Exchange-Suffix
    if (str_contains($xetra, '.')) return strtoupper($xetra);
    // Zahlen/Sonderzeichen bereinigen (nur alphanumerisch)
    $clean = preg_replace('/[^A-Z0-9]/i', '', strtoupper($xetra));
    if (empty($clean) || strlen($clean) > 6) return '';
    return $clean . '.DE';
}

/**
 * Deutschen Sektor-Namen → GICS-Sektor
 */
function mapSector(string $rawSector): string
{
    $map = [
        'automobile' => 'Consumer Discretionary',
        'automotive' => 'Consumer Discretionary',
        'car'        => 'Consumer Discretionary',
        'consumer'   => 'Consumer Staples',
        'chemical'   => 'Materials',
        'chemicals'  => 'Materials',
        'material'   => 'Materials',
        'financial'  => 'Financials',
        'finance'    => 'Financials',
        'bank'       => 'Financials',
        'insurance'  => 'Financials',
        'reinsur'    => 'Financials',
        'health'     => 'Health Care',
        'pharma'     => 'Health Care',
        'medic'      => 'Health Care',
        'tech'       => 'Information Technology',
        'software'   => 'Information Technology',
        'semicond'   => 'Information Technology',
        'telecom'    => 'Communication Services',
        'communic'   => 'Communication Services',
        'media'      => 'Communication Services',
        'energy'     => 'Energy',
        'utility'    => 'Utilities',
        'utilities'  => 'Utilities',
        'real estat' => 'Real Estate',
        'industri'   => 'Industrials',
        'engineer'   => 'Industrials',
        'logistic'   => 'Industrials',
        'transport'  => 'Industrials',
        'sport'      => 'Consumer Discretionary',
        'retail'     => 'Consumer Discretionary',
        'luxury'     => 'Consumer Discretionary',
        'food'       => 'Consumer Staples',
        'beverage'   => 'Consumer Staples',
    ];

    $lower = strtolower($rawSector);
    foreach ($map as $key => $gics) {
        if (str_contains($lower, $key)) return $gics;
    }
    return $rawSector ?: 'Unknown';
}

/**
 * Datumsstrings aus Wikipedia parsen (verschiedene Formate)
 */
function parseDate(string $raw): ?string
{
    $raw = trim(preg_replace('/\[.*?\]/', '', $raw));  // Fußnoten entfernen
    if (empty($raw)) return null;

    // ISO: 2021-09-20
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
        return $raw;
    }
    // Englisch: 20 September 2021 / September 20, 2021
    $months = ['january'=>1,'february'=>2,'march'=>3,'april'=>4,'may'=>5,'june'=>6,
               'july'=>7,'august'=>8,'september'=>9,'october'=>10,'november'=>11,'december'=>12];

    $raw2 = strtolower($raw);
    foreach ($months as $name => $num) {
        if (str_contains($raw2, $name)) {
            preg_match('/(\d{4})/', $raw2, $yearM);
            preg_match('/(\d{1,2})\s+' . $name . '|' . $name . '\s+(\d{1,2})/', $raw2, $dayM);
            $year = $yearM[1] ?? null;
            $day  = $dayM[1] ?? $dayM[2] ?? 1;
            if ($year) {
                return sprintf('%04d-%02d-%02d', $year, $num, $day);
            }
        }
    }

    // Nur Jahr: z.B. "2018"
    if (preg_match('/^(\d{4})$/', $raw, $m)) {
        return $m[1] . '-01-01';
    }

    return null;
}

function cleanText(string $text): string
{
    // Fußnoten-Referenzen und überflüssige Whitespace entfernen
    $text = preg_replace('/\[[\d\w]+\]/', '', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

/**
 * Fallback: Aktuelle DAX 40-Zusammensetzung (Stand April 2025)
 * Yahoo Finance Ticker (.DE Suffix)
 */
function getFallbackDAX40(): array
{
    return [
        ['ticker'=>'ADS.DE',  'name'=>'Adidas AG',                       'sector'=>'Consumer Discretionary', 'industry'=>'Textiles, Apparel & Luxury'],
        ['ticker'=>'AIR.DE',  'name'=>'Airbus SE',                       'sector'=>'Industrials',            'industry'=>'Aerospace & Defense'],
        ['ticker'=>'ALV.DE',  'name'=>'Allianz SE',                      'sector'=>'Financials',             'industry'=>'Insurance'],
        ['ticker'=>'BAS.DE',  'name'=>'BASF SE',                         'sector'=>'Materials',              'industry'=>'Chemicals'],
        ['ticker'=>'BAYN.DE', 'name'=>'Bayer AG',                        'sector'=>'Health Care',            'industry'=>'Pharmaceuticals'],
        ['ticker'=>'BEI.DE',  'name'=>'Beiersdorf AG',                   'sector'=>'Consumer Staples',       'industry'=>'Personal Care Products'],
        ['ticker'=>'BMW.DE',  'name'=>'BMW AG',                          'sector'=>'Consumer Discretionary', 'industry'=>'Automobiles'],
        ['ticker'=>'BNR.DE',  'name'=>'Brenntag SE',                     'sector'=>'Materials',              'industry'=>'Chemicals'],
        ['ticker'=>'CBK.DE',  'name'=>'Commerzbank AG',                  'sector'=>'Financials',             'industry'=>'Diversified Banks'],
        ['ticker'=>'CON.DE',  'name'=>'Continental AG',                  'sector'=>'Consumer Discretionary', 'industry'=>'Auto Parts'],
        ['ticker'=>'1COV.DE', 'name'=>'Covestro AG',                     'sector'=>'Materials',              'industry'=>'Chemicals'],
        ['ticker'=>'DHER.DE', 'name'=>'Delivery Hero SE',                'sector'=>'Consumer Discretionary', 'industry'=>'Internet & Direct Marketing'],
        ['ticker'=>'DB1.DE',  'name'=>'Deutsche Börse AG',               'sector'=>'Financials',             'industry'=>'Financial Exchanges'],
        ['ticker'=>'DBK.DE',  'name'=>'Deutsche Bank AG',                'sector'=>'Financials',             'industry'=>'Diversified Banks'],
        ['ticker'=>'DPW.DE',  'name'=>'Deutsche Post AG',                'sector'=>'Industrials',            'industry'=>'Air Freight & Logistics'],
        ['ticker'=>'DTE.DE',  'name'=>'Deutsche Telekom AG',             'sector'=>'Communication Services', 'industry'=>'Integrated Telecommunication'],
        ['ticker'=>'EOAN.DE', 'name'=>'E.ON SE',                         'sector'=>'Utilities',              'industry'=>'Multi-Utilities'],
        ['ticker'=>'FRE.DE',  'name'=>'Fresenius SE & Co. KGaA',        'sector'=>'Health Care',            'industry'=>'Health Care Facilities'],
        ['ticker'=>'FME.DE',  'name'=>'Fresenius Medical Care AG',       'sector'=>'Health Care',            'industry'=>'Health Care Equipment'],
        ['ticker'=>'HNR1.DE', 'name'=>'Hannover Rück SE',                'sector'=>'Financials',             'industry'=>'Reinsurance'],
        ['ticker'=>'HEI.DE',  'name'=>'HeidelbergCement AG',             'sector'=>'Materials',              'industry'=>'Construction Materials'],
        ['ticker'=>'HNKG.DE', 'name'=>'Henkel AG & Co. KGaA',           'sector'=>'Consumer Staples',       'industry'=>'Household Products'],
        ['ticker'=>'IFX.DE',  'name'=>'Infineon Technologies AG',        'sector'=>'Information Technology', 'industry'=>'Semiconductors'],
        ['ticker'=>'MBG.DE',  'name'=>'Mercedes-Benz Group AG',          'sector'=>'Consumer Discretionary', 'industry'=>'Automobiles'],
        ['ticker'=>'MRK.DE',  'name'=>'Merck KGaA',                      'sector'=>'Health Care',            'industry'=>'Pharmaceuticals'],
        ['ticker'=>'MTX.DE',  'name'=>'MTU Aero Engines AG',             'sector'=>'Industrials',            'industry'=>'Aerospace & Defense'],
        ['ticker'=>'MUV2.DE', 'name'=>'Münchener Rückversicherungs-Gesellschaft AG', 'sector'=>'Financials', 'industry'=>'Reinsurance'],
        ['ticker'=>'RHM.DE',  'name'=>'Rheinmetall AG',                  'sector'=>'Industrials',            'industry'=>'Aerospace & Defense'],
        ['ticker'=>'RWE.DE',  'name'=>'RWE AG',                          'sector'=>'Utilities',              'industry'=>'Electric Utilities'],
        ['ticker'=>'SAP.DE',  'name'=>'SAP SE',                          'sector'=>'Information Technology', 'industry'=>'Application Software'],
        ['ticker'=>'SRT3.DE', 'name'=>'Sartorius AG',                    'sector'=>'Health Care',            'industry'=>'Life Sciences Tools'],
        ['ticker'=>'SIE.DE',  'name'=>'Siemens AG',                      'sector'=>'Industrials',            'industry'=>'Industrial Conglomerates'],
        ['ticker'=>'ENR.DE',  'name'=>'Siemens Energy AG',               'sector'=>'Industrials',            'industry'=>'Heavy Electrical Equipment'],
        ['ticker'=>'SMHN.DE', 'name'=>'Siemens Healthineers AG',         'sector'=>'Health Care',            'industry'=>'Health Care Equipment'],
        ['ticker'=>'SY1.DE',  'name'=>'Symrise AG',                      'sector'=>'Materials',              'industry'=>'Specialty Chemicals'],
        ['ticker'=>'VOW3.DE', 'name'=>'Volkswagen AG',                   'sector'=>'Consumer Discretionary', 'industry'=>'Automobiles'],
        ['ticker'=>'VNA.DE',  'name'=>'Vonovia SE',                      'sector'=>'Real Estate',            'industry'=>'Residential REITs'],
        ['ticker'=>'ZAL.DE',  'name'=>'Zalando SE',                      'sector'=>'Consumer Discretionary', 'industry'=>'Internet & Direct Marketing'],
        ['ticker'=>'PAH3.DE', 'name'=>'Porsche Automobil Holding SE',    'sector'=>'Consumer Discretionary', 'industry'=>'Automobiles'],
        ['ticker'=>'P911.DE', 'name'=>'Dr. Ing. h.c. F. Porsche AG',    'sector'=>'Consumer Discretionary', 'industry'=>'Automobiles'],
    ];
}
