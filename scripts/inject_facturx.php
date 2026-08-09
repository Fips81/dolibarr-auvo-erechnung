<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 * Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de> — renamed/modified for private use as AuvoZugferd
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Script standalone pour injecter le XML Factur-X dans un PDF
 *
 * Usage: php inject_facturx.php <pdf_path> <xml_path>
 *
 * Ce script s'exécute dans un process PHP séparé, sans Dolibarr chargé,
 * pour éviter les conflits entre FPDF (utilisé par atgp/factur-x) et
 * TCPDF (utilisé par Dolibarr).
 */

// Sécurité : ce script ne doit être exécuté qu'en ligne de commande
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	die('Access denied');
}

if ($argc < 3) {
	fwrite(STDERR, "Usage: php inject_facturx.php <pdf_path> <xml_path>\n");
	exit(1);
}

$pdfPath = $argv[1];
$xmlPath = $argv[2];

if (!file_exists($pdfPath)) {
	fwrite(STDERR, "PDF not found: $pdfPath\n");
	exit(1);
}
if (!file_exists($xmlPath)) {
	fwrite(STDERR, "XML not found: $xmlPath\n");
	exit(1);
}

require_once __DIR__.'/../vendor/autoload.php';

try {
	$pdfContent = file_get_contents($pdfPath);
	$xmlContent = file_get_contents($xmlPath);
	if ($pdfContent === false || $xmlContent === false) {
		fwrite(STDERR, "Error: unable to read input files\n");
		exit(1);
	}

	// AFRelationship 'Alternative' (7e argument) : imposé par la spec Factur-X
	// pour les profils BASIC/EN16931/EXTENDED ('Data' est réservé à
	// MINIMUM/BASIC WL et déclenche une erreur Mustang/FNFE).
	$writer = new \Atgp\FacturX\Writer();
	$facturxPdf = $writer->generate(
		$pdfContent,
		$xmlContent,
		'en16931',
		false,
		[],
		false,
		'Alternative'
	);

	// Écriture atomique : le PDF original n'est remplacé qu'une fois le
	// nouveau fichier intégralement écrit (évite un PDF tronqué si disque
	// plein ou process interrompu).
	$tmpPath = $pdfPath.'.facturx.tmp';
	if (file_put_contents($tmpPath, $facturxPdf) !== strlen($facturxPdf)) {
		@unlink($tmpPath);
		fwrite(STDERR, "Error: unable to write temporary output file\n");
		exit(1);
	}
	if (!rename($tmpPath, $pdfPath)) {
		@unlink($tmpPath);
		fwrite(STDERR, "Error: unable to replace original PDF\n");
		exit(1);
	}

	echo "OK\n";
	exit(0);
} catch (\Throwable $e) {
	fwrite(STDERR, "Error: ".$e->getMessage()."\n");
	exit(1);
}
