<?php
/*
 * AuVo E-Rechnung business test suite.
 *
 * Uses the ten Factur-X fixtures (F001-F010) created by demo/fixtures.php.
 * For each case, generates XML with auvoerechnung_build_xml() and checks:
 *  - Expected document TypeCode (380/381/386)
 *  - Present CategoryCode values (S/K/G/O/E)
 *  - UN/ECE unitCode (C62, HUR, DAY...)
 *  - Presence of ExemptionReason and buyer URIUniversalCommunication (+ endpoint schemeID)
 *  - Presence of TotalPrepaidAmount and DuePayableAmount value
 *  - Factur-X EN16931 XSD validation
 *
 * Usage:
 *   php tests/run-tests.php
 *   Exit code 0 = all tests pass; 1 = at least one test fails
 *
 * Prerequisite: demo Dolibarr with fixtures.php already run (IDs 1 through 10 = F001..F010).
 */

// Safety: CLI execution only.
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	die('CLI only');
}

// Locate master.inc.php: two levels above the module, or use DOL_DOCUMENT_ROOT.
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
	fwrite(STDERR, "ERROR: master.inc.php introuvable. Lancer depuis un Dolibarr installé.\n");
	exit(2);
}

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once __DIR__.'/../core/lib/auvoerechnung.lib.php';
require_once __DIR__.'/../core/lib/auvoerechnung_rules.php';

$modulePath = dirname(__DIR__);

$cases = [
	1  => ['ref' => 'F001 standard FR 20%',    'typeCode' => '380', 'mainCategory' => 'S',  'unitCode' => 'C62', 'buyerUri' => true,  'buyerUriScheme' => '0225', 'exemption' => false, 'prepaid' => null, 'due' => 1200.00],
	2  => ['ref' => 'F002 multi-TVA 20% + 5,5%','typeCode' => '380','mainCategory' => 'S',  'unitCode' => 'C62', 'buyerUri' => true,  'buyerUriScheme' => '0225', 'exemption' => false, 'prepaid' => null, 'due' => 1727.50],
	3  => ['ref' => 'F003 TVA 0% franchise',   'typeCode' => '380', 'mainCategory' => 'E',  'unitCode' => 'C62', 'buyerUri' => true,  'buyerUriScheme' => '0225', 'exemption' => true,  'prepaid' => null, 'due' => 500.00],
	// Credit note: since v3.0.0, amounts are emitted as POSITIVE (BR-27), and
	// DuePayableAmount = GrandTotal without clamping (BR-CO-16) → 1200.00.
	4  => ['ref' => 'F004 avoir',              'typeCode' => '381', 'mainCategory' => 'S',  'unitCode' => 'C62', 'buyerUri' => true,  'buyerUriScheme' => '0225', 'exemption' => false, 'prepaid' => null, 'due' => 1200.00],
	5  => ['ref' => 'F005 ligne en heures',    'typeCode' => '380', 'mainCategory' => 'S',  'unitCode' => 'HUR', 'buyerUri' => true,  'buyerUriScheme' => '0225', 'exemption' => false, 'prepaid' => null, 'due' => 960.00],
	6  => ['ref' => 'F006 ligne en jours',     'typeCode' => '380', 'mainCategory' => 'S',  'unitCode' => 'DAY', 'buyerUri' => true,  'buyerUriScheme' => '0225', 'exemption' => false, 'prepaid' => null, 'due' => 2700.00],
	7  => ['ref' => 'F007 buyer sans email',   'typeCode' => '380', 'mainCategory' => 'S',  'unitCode' => 'C62', 'buyerUri' => true,  'buyerUriScheme' => '0225', 'exemption' => false, 'prepaid' => null, 'due' => 600.00],
	8  => ['ref' => 'F008 UE autoliquidation', 'typeCode' => '380', 'mainCategory' => 'K',  'unitCode' => 'C62', 'buyerUri' => true,  'buyerUriScheme' => 'EM',   'exemption' => true,  'prepaid' => null, 'due' => 2000.00],
	9  => ['ref' => 'F009 acompte (deposit)',  'typeCode' => '386', 'mainCategory' => 'S',  'unitCode' => 'C62', 'buyerUri' => true,  'buyerUriScheme' => '0225', 'exemption' => false, 'prepaid' => null, 'due' => 240.00],
	10 => ['ref' => 'F010 finale avec acompte','typeCode' => '380', 'mainCategory' => 'S',  'unitCode' => 'C62', 'buyerUri' => true,  'buyerUriScheme' => '0225', 'exemption' => false, 'prepaid' => 240.00, 'due' => 960.00],
];

$totalPassed = 0;
$totalFailed = 0;
$failures = [];

function assertEquals($expected, $actual, $label, &$pass, &$fail, &$failures, $id)
{
	if ($expected === $actual || (is_numeric($expected) && is_numeric($actual) && abs($expected - $actual) < 0.01)) {
		$pass++;
		return;
	}
	$fail++;
	$failures[] = sprintf("F%03d %s : attendu=%s obtenu=%s", $id, $label, var_export($expected, true), var_export($actual, true));
}

function getXmlSingleMatch($pattern, $xml)
{
	return preg_match($pattern, $xml, $m) ? $m[1] : null;
}

echo "=== AuVo E-Rechnung - Suite de tests métier ===\n\n";

foreach ($cases as $id => $c) {
	$pass = 0;
	$fail = 0;
	$caseFailures = [];

	$f = new Facture($db);
	if ($f->fetch($id) <= 0) {
		echo "F".str_pad($id,3,'0',STR_PAD_LEFT)." SKIP (fixture absente, lancer demo/fixtures.php)\n";
		continue;
	}
	$f->fetch_thirdparty();
	$xml = auvoerechnung_build_xml($f, $mysoc);

	// Extract values.
	$typeCode = getXmlSingleMatch('#<ram:TypeCode>([^<]+)</ram:TypeCode>#', $xml);
	preg_match_all('#<ram:CategoryCode>([^<]+)</ram:CategoryCode>#', $xml, $mCat);
	$categories = array_unique($mCat[1] ?? []);
	preg_match_all('#unitCode="([A-Z0-9]+)"#', $xml, $mUnit);
	$units = array_unique($mUnit[1] ?? []);
	// Accepted sign: since 3.0.0, DuePayableAmount is no longer clamped to zero.
	$prepaid = getXmlSingleMatch('#<ram:TotalPrepaidAmount>(-?[0-9.]+)</ram:TotalPrepaidAmount>#', $xml);
	$due = getXmlSingleMatch('#<ram:DuePayableAmount>(-?[0-9.]+)</ram:DuePayableAmount>#', $xml);
	preg_match_all('#<ram:ExemptionReason>([^<]+)</ram:ExemptionReason>#', $xml, $mEx);
	$hasExemption = !empty($mEx[1]);
	$buyerBlock = '';
	if (preg_match('#<ram:BuyerTradeParty>.+?</ram:BuyerTradeParty>#s', $xml, $mB)) {
		$buyerBlock = $mB[0];
	}
	$buyerHasUri = strpos($buyerBlock, '<ram:URIUniversalCommunication>') !== false;
	$buyerUriScheme = $buyerHasUri ? getXmlSingleMatch('#<ram:URIID schemeID="([^"]+)"#', $buyerBlock) : null;

	// Assertions.
	assertEquals($c['typeCode'], $typeCode, 'TypeCode', $pass, $fail, $caseFailures, $id);
	assertEquals(true, in_array($c['mainCategory'], $categories, true), 'CategoryCode contient '.$c['mainCategory'], $pass, $fail, $caseFailures, $id);
	assertEquals(true, in_array($c['unitCode'], $units, true), 'unitCode contient '.$c['unitCode'], $pass, $fail, $caseFailures, $id);
	assertEquals($c['buyerUri'], $buyerHasUri, 'BuyerTradeParty URI présent', $pass, $fail, $caseFailures, $id);
	assertEquals($c['buyerUriScheme'], $buyerUriScheme, 'BuyerTradeParty URIID schemeID', $pass, $fail, $caseFailures, $id);
	assertEquals($c['exemption'], $hasExemption, 'ExemptionReason présent', $pass, $fail, $caseFailures, $id);
	assertEquals($c['prepaid'] === null ? null : (float) $c['prepaid'], $prepaid === null ? null : (float) $prepaid, 'TotalPrepaidAmount', $pass, $fail, $caseFailures, $id);
	assertEquals((float) $c['due'], $due === null ? null : (float) $due, 'DuePayableAmount', $pass, $fail, $caseFailures, $id);

	// XSD validation: same implementation as production (auvoerechnung_rules.php).
	$xsdError = auvoerechnung_validate_xsd($xml, $modulePath);
	if ($xsdError !== null) {
		$caseFailures[] = sprintf("F%03d XSD validation KO : %s", $id, $xsdError);
		$fail++;
	} else {
		$pass++;
	}

	$totalPassed += $pass;
	$totalFailed += $fail;
	$failures = array_merge($failures, $caseFailures);

	$status = ($fail === 0) ? 'PASS' : 'FAIL';
	printf("F%03d %-35s %s (%d/%d)\n", $id, $c['ref'], $status, $pass, $pass + $fail);
}

echo "\n=== Résultat ===\n";
echo "Passed : $totalPassed\n";
echo "Failed : $totalFailed\n";
if (!empty($failures)) {
	echo "\nDétail des échecs :\n";
	foreach ($failures as $f) {
		echo "  - $f\n";
	}
}
exit($totalFailed > 0 ? 1 : 0);
