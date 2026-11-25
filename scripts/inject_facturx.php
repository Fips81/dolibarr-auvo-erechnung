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
 * Standalone script to embed Factur-X XML into a PDF.
 *
 * Usage: php inject_facturx.php <pdf_path> <xml_path>
 *
 * This script runs in a separate PHP process without Dolibarr loaded, to
 * avoid conflicts between FPDF (used by atgp/factur-x) and TCPDF (used by Dolibarr).
 */

// Security: this script must only run from the command line.
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

	// AFRelationship 'Alternative' (7th argument) is required by the Factur-X
	// specification for BASIC/EN16931/EXTENDED profiles. 'Data' is reserved for
	// MINIMUM/BASIC WL and triggers a Mustang/FNFE error.
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

	// Atomic write: replace the original PDF only after the new file has been
	// written completely. This avoids a truncated PDF if the disk is full or
	// the process is interrupted.
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
