<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 * Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de> — fork, renamed and modified as AuVo E-Rechnung
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Batch export of Factur-X XML embedded in PDFs of validated customer invoices
 * (for audits, archiving, or manual submission to a platform).
 *
 * Usage : php scripts/export_facturx_batch.php <dossier_destination> [annee]
 *
 * For each validated invoice (limited to the specified year, if provided),
 * extracts Factur-X XML from the main PDF to <destination>/<ref>.xml and prints
 * a report (OK / NO_PDF / NO_XML).
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	die('CLI only');
}

if ($argc < 2) {
	fwrite(STDERR, "Usage: php export_facturx_batch.php <dest_dir> [year]\n");
	exit(2);
}
$destDir = rtrim($argv[1], '/');
$year = isset($argv[2]) ? (int) $argv[2] : 0;

if (!is_dir($destDir) && !mkdir($destDir, 0750, true)) {
	fwrite(STDERR, "FEHLER: Zielverzeichnis nicht beschreibbar: $destDir\n");
	exit(2);
}

// Locate master.inc.php: two levels above the module or in standard paths.
$candidates = [
	__DIR__.'/../../../master.inc.php',
	'/var/www/dolibarr/htdocs/master.inc.php',
	'/var/www/html/master.inc.php',
];
$loaded = false;
foreach ($candidates as $c) {
	if (file_exists($c)) {
		require_once $c;
		$loaded = true;
		break;
	}
}
if (!$loaded) {
	fwrite(STDERR, "FEHLER: master.inc.php nicht gefunden. Bitte aus einer installierten Dolibarr-Instanz starten.\n");
	exit(2);
}

require_once __DIR__.'/../core/lib/auvoerechnung.lib.php';

$sql = "SELECT rowid, ref, datef, entity, last_main_doc FROM ".MAIN_DB_PREFIX."facture"
	." WHERE fk_statut >= 1 AND entity IN (".getEntity('invoice').")";
if ($year > 0) {
	// Use date bounds instead of YEAR() for MySQL/MariaDB/PostgreSQL portability.
	$sql .= " AND datef >= '".((int) $year)."-01-01' AND datef <= '".((int) $year)."-12-31'";
}
$sql .= " ORDER BY datef ASC, rowid ASC";

$res = $db->query($sql);
if (!$res) {
	fwrite(STDERR, "FEHLER: Abfrage der Rechnungen fehlgeschlagen: ".$db->lasterror()."\n");
	exit(2);
}

$counts = ['OK' => 0, 'NO_PDF' => 0, 'NO_XML' => 0];

echo "=== AuVo E-Rechnung - Stapelexport der ZUGFeRD-XML ===\n";
echo "Ziel: $destDir".($year > 0 ? " | Jahr: $year" : '')."\n\n";

while ($obj = $db->fetch_object($res)) {
	$ref = $obj->ref;
	// Use the invoice's entity, not the current entity: with shared multicompany
	// access, the PDF is stored in that entity's documents directory.
	$pdfPath = auvoerechnung_invoice_pdf_path($ref, (int) $obj->entity, (string) ($obj->last_main_doc ?? ''));

	if ($pdfPath === null) {
		$counts['NO_PDF']++;
		printf("%-20s NO_PDF\n", $ref);
		continue;
	}

	$xml = auvoerechnung_extract_xml_from_pdf($pdfPath);
	if ($xml === null) {
		$counts['NO_XML']++;
		printf("%-20s NO_XML\n", $ref);
		continue;
	}

	$safeRef = dol_sanitizeFileName($ref);
	if (file_put_contents($destDir.'/'.$safeRef.'.xml', $xml) === false) {
		fwrite(STDERR, "FEHLER: Schreiben nicht möglich für $ref\n");
		exit(2);
	}
	$counts['OK']++;
	printf("%-20s OK\n", $ref);
}

echo "\n=== Ergebnis ===\n";
printf("Exportiert: %d | Ohne PDF: %d | Ohne ZUGFeRD-XML: %d\n", $counts['OK'], $counts['NO_PDF'], $counts['NO_XML']);
echo "Tipp: Rechnungen mit NO_XML lassen sich über den Button „ZUGFeRD neu erzeugen\" auf der Rechnungsseite neu erzeugen.\n";
exit(0);
