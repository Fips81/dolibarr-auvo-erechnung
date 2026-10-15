<?php
/*
 * Suite de tests unitaires standalone AuVo E-Rechnung — AUCUN Dolibarr requis.
 *
 * Génère le XML Factur-X sur des objets factices (stubs.php) et vérifie pour
 * chaque scénario : validation XSD EN16931, règles métier internes (BR-*),
 * et assertions ciblées (TypeCode, catégories, montants, blocs optionnels).
 *
 * Usage : php tests/unit-tests.php
 * Exit code 0 = tous les tests passent, 1 = au moins un échec.
 *
 * Complémentaire de tests/run-tests.php qui, lui, s'exécute contre un
 * Dolibarr réel avec les fixtures de demo/.
 */

require_once __DIR__.'/stubs.php';
require_once __DIR__.'/../core/lib/auvoerechnung.lib.php';
require_once __DIR__.'/../core/lib/auvoerechnung_rules.php';

$xsdPath = __DIR__.'/../vendor/atgp/factur-x/xsd/factur-x/en16931/Factur-X_1.09.2_EN16931.xsd';

$passed = 0;
$failed = 0;
$failures = [];

function lfx_assert($cond, $label)
{
	global $passed, $failed, $failures, $currentTest;
	if ($cond) {
		$passed++;
		return;
	}
	$failed++;
	$failures[] = "[$currentTest] $label";
}

function lfx_assert_eq($expected, $actual, $label)
{
	global $passed, $failed, $failures, $currentTest;
	$ok = ($expected === $actual)
		|| (is_numeric($expected) && is_numeric($actual) && abs((float) $expected - (float) $actual) < 0.005);
	if ($ok) {
		$passed++;
		return;
	}
	$failed++;
	$failures[] = "[$currentTest] $label : attendu=".var_export($expected, true)." obtenu=".var_export($actual, true);
}

function lfx_xpath($xml)
{
	$dom = new DOMDocument();
	$dom->loadXML($xml);
	$xp = new DOMXPath($dom);
	$xp->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
	$xp->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
	$xp->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');
	$xp->registerNamespace('qdt', 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100');
	return $xp;
}

function lfx_xp_str($xp, $query)
{
	$n = $xp->query($query);
	return ($n && $n->length > 0) ? trim($n->item(0)->textContent) : null;
}

function lfx_check_xsd($xml, $xsdPath)
{
	// Même implémentation que la production (core/lib/auvoerechnung_rules.php) :
	// les tests valident le code réellement exécuté par le hook.
	if (!file_exists($xsdPath)) {
		return 'XSD absent';
	}
	return auvoerechnung_validate_xsd($xml, dirname(__DIR__));
}

/**
 * Réinitialise la config factice avant chaque scénario.
 */
function lfx_reset($conf = [], $bank = ['iban' => 'FR7630006000011234567890189', 'bic' => 'AGRIFRPP'], $dbHandlers = [])
{
	$GLOBALS['lfx_test_conf'] = array_merge([
		'AUVOERECHNUNG_BANK_ACCOUNT' => $bank !== null ? 1 : 0,
		'AUVOERECHNUNG_PAYMENT_MEANS' => '30',
		'MAIN_PDF_FORCE_FONT' => 'pdfahelvetica',
	], $conf);
	$GLOBALS['lfx_test_bank'] = $bank;
	$GLOBALS['lfx_test_db_handlers'] = $dbHandlers;
}

function lfx_std_validate($xml, $xsdPath, $expectBrOk = true)
{
	$xsdErr = lfx_check_xsd($xml, $xsdPath);
	lfx_assert($xsdErr === null, 'XSD valide ('.($xsdErr ?? '').')');
	$violations = auvoerechnung_validate_business_rules($xml);
	if ($expectBrOk) {
		lfx_assert(empty($violations), 'Règles BR respectées ('.implode(' ; ', $violations).')');
		// Tout XML jugé conforme ici est aussi soumis au validateur officiel
		// KoSIT en CI (tests/kosit-validate.sh) : le contrôle interne ne doit
		// jamais être plus permissif que le Schematron officiel.
		static $dumpCount = 0;
		$dumpCount++;
		$label = preg_replace('/[^A-Za-z0-9]+/', '-', explode(' ', $GLOBALS['currentTest'] ?? 'test')[0]);
		lfx_dump(sprintf('std-%03d-%s', $dumpCount, $label), $xml);
	} else {
		lfx_assert(!empty($violations), 'Violations BR attendues');
	}
	return $violations;
}

/**
 * Vrai si au moins une entrée du tableau contient le fragment donné
 * (préfixe de règle « BR-FR-01 », clé de traduction, etc.).
 */
/**
 * Écrit un XML de test dans LFX_DUMP_DIR (si défini), pour validation externe
 * (validateur officiel KoSIT en CI).
 */
function lfx_dump($name, $xml)
{
	$dir = getenv('LFX_DUMP_DIR');
	if ($dir !== false && $dir !== '' && is_dir($dir)) {
		file_put_contents(rtrim($dir, '/').'/'.$name.'.xml', $xml);
	}
}

function lfx_contains($entries, $needle)
{
	foreach ((array) $entries as $e) {
		if (strpos((string) $e, $needle) !== false) {
			return true;
		}
	}
	return false;
}

echo "=== AuVo E-Rechnung - Tests unitaires standalone ===\n\n";

$mysoc = lfx_make_party(['name' => 'DEMO SASU', 'idprof1' => '909458306', 'idprof2' => '90945830600012', 'tva_intra' => 'FR38909458306']);

// ---------------------------------------------------------------------------
$currentTest = 'U01 standard FR 20%';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0001';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00)];
$inv->total_ht = 1000.00;
$inv->total_tva = 200.00;
$inv->total_ttc = 1200.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('380', lfx_xp_str($xp, '//rsm:ExchangedDocument/ram:TypeCode'), 'TypeCode');
lfx_assert_eq('1200.00', lfx_xp_str($xp, '//ram:DuePayableAmount'), 'DuePayableAmount');
lfx_assert_eq('S', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode'), 'CategoryCode');
// BT-29 : SIRET (établissement) dans ram:GlobalID/0009 (le schemeID ne se met PAS
// sur ram:ID en EN16931 — sinon « @schemeID not used » côté Schematron FNFE)
lfx_assert_eq('0009', lfx_xp_str($xp, '//ram:SellerTradeParty/ram:GlobalID/@schemeID'), 'BT-29 schemeID SIRET (0009)');
lfx_assert_eq('90945830600012', lfx_xp_str($xp, '//ram:SellerTradeParty/ram:GlobalID'), 'BT-29 SIRET vendeur');
// BT-30 : SIREN (entité légale) dans SpecifiedLegalOrganization/0002
lfx_assert_eq('0002', lfx_xp_str($xp, '//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID/@schemeID'), 'BT-30 schemeID SIREN (0002)');
lfx_assert_eq('909458306', lfx_xp_str($xp, '//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID'), 'BT-30 SIREN vendeur');
lfx_assert(empty($w), 'aucun warning ('.implode(' ; ', $w).')');
echo "U01 OK\n";

// U01b : source du SIREN — idprof1 prioritaire, fallback dérivé du SIRET
$wb = [];
$socPrio = lfx_make_party(['idprof1' => '111222333', 'idprof2' => '44455566600018', 'tva_intra' => '', 'tva_assuj' => 0]);
$xpb = lfx_xpath(auvoerechnung_build_xml($inv, $socPrio, $wb));
lfx_assert_eq('111222333', lfx_xp_str($xpb, '//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID'), 'BT-30 SIREN = idprof1 (prioritaire sur le SIRET)');
lfx_assert_eq('44455566600018', lfx_xp_str($xpb, '//ram:SellerTradeParty/ram:GlobalID'), 'BT-29 SIRET = idprof2');
$wf = [];
$socFb = lfx_make_party(['idprof1' => '', 'idprof2' => '90945830600012', 'tva_intra' => 'FR38909458306']);
$xpf = lfx_xpath(auvoerechnung_build_xml($inv, $socFb, $wf));
lfx_assert_eq('909458306', lfx_xp_str($xpf, '//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID'), 'BT-30 SIREN dérivé du SIRET quand idprof1 absent');
echo "U01b OK\n";

// U01c : profil Chorus Pro — SIRET-14 dans SpecifiedLegalOrganization (0009) + BT-10/12/13
$wc = [];
$xmlChorus = auvoerechnung_build_xml($inv, $mysoc, $wc, [
	'profile'      => 'choruspro',
	'service_code' => 'SVC12345678',
	'engagement'   => 'EJ2026-42',
	'marche'       => 'MARCHE-7',
]);
lfx_std_validate($xmlChorus, $xsdPath);
$xpc = lfx_xpath($xmlChorus);
lfx_assert_eq('0009', lfx_xp_str($xpc, '//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID/@schemeID'), 'Chorus: BT-30 schemeID SIRET (0009)');
lfx_assert_eq('90945830600012', lfx_xp_str($xpc, '//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID'), 'Chorus: BT-30 SIRET-14 vendeur');
lfx_assert_eq(0, $xpc->query('//ram:SellerTradeParty/ram:GlobalID')->length, 'Chorus: pas de GlobalID BT-29 séparé (SIRET uniquement en SpecifiedLegalOrganization)');
lfx_assert_eq('SVC12345678', lfx_xp_str($xpc, '//ram:ApplicableHeaderTradeAgreement/ram:BuyerReference'), 'Chorus: BT-10 code service');
lfx_assert_eq('EJ2026-42', lfx_xp_str($xpc, '//ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID'), 'Chorus: BT-13 n° engagement');
lfx_assert_eq('MARCHE-7', lfx_xp_str($xpc, '//ram:ContractReferencedDocument/ram:IssuerAssignedID'), 'Chorus: BT-12 n° marché');
echo "U01c OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U02 avoir 381 montants positifs + BG-3';
lfx_reset([], ['iban' => 'FR7630006000011234567890189', 'bic' => 'AGRIFRPP'], [
	'FROM '.MAIN_DB_PREFIX.'facture WHERE rowid = 42' => [(object) ['ref' => 'FA2605-0099', 'datef' => '2026-05-12']],
]);
$inv = new LfxFakeInvoice();
$inv->ref = 'AV2606-0001';
$inv->type = 2; // TYPE_CREDIT_NOTE
$inv->fk_facture_source = 42;
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(-1000.00, 20.0, -200.00, 1.0, 'Avoir sur FA2605-0099')];
$inv->total_ht = -1000.00;
$inv->total_tva = -200.00;
$inv->total_ttc = -1200.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('381', lfx_xp_str($xp, '//rsm:ExchangedDocument/ram:TypeCode'), 'TypeCode 381');
lfx_assert_eq('1200.00', lfx_xp_str($xp, '//ram:GrandTotalAmount'), 'GrandTotal positif');
lfx_assert_eq('1200.00', lfx_xp_str($xp, '//ram:DuePayableAmount'), 'DuePayable positif (BR-CO-16, pas de max(0))');
$creditPrice = lfx_xp_str($xp, '//ram:NetPriceProductTradePrice/ram:ChargeAmount');
lfx_assert($creditPrice !== null, 'prix unitaire présent');
lfx_assert((float) $creditPrice >= 0, 'prix unitaire positif (BR-27)');
lfx_assert_eq('FA2605-0099', lfx_xp_str($xp, '//ram:InvoiceReferencedDocument/ram:IssuerAssignedID'), 'BG-3 facture d\'origine');
lfx_assert_eq('20260512', lfx_xp_str($xp, '//ram:InvoiceReferencedDocument/ram:FormattedIssueDateTime/qdt:DateTimeString'), 'BG-3 date d\'origine');
echo "U02 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U03 remise fixe -> BG-21';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0002';
$inv->thirdparty = lfx_make_party();
$inv->lines = [
	lfx_make_line(1000.00, 20.0, 200.00, 1.0, 'Prestation'),
	lfx_make_line(-100.00, 20.0, -20.00, 1.0, 'Remise commerciale'),
];
$inv->total_ht = 900.00;
$inv->total_tva = 180.00;
$inv->total_ttc = 1080.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq(1, $xp->query('//ram:IncludedSupplyChainTradeLineItem')->length, 'une seule ligne (la remise est sortie des lignes)');
lfx_assert_eq('100.00', lfx_xp_str($xp, '//ram:SpecifiedTradeAllowanceCharge/ram:ActualAmount'), 'BG-21 montant remise');
lfx_assert_eq('false', lfx_xp_str($xp, '//ram:SpecifiedTradeAllowanceCharge/ram:ChargeIndicator/udt:Indicator'), 'ChargeIndicator false');
lfx_assert_eq('1000.00', lfx_xp_str($xp, '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:LineTotalAmount'), 'BT-106');
lfx_assert_eq('100.00', lfx_xp_str($xp, '//ram:AllowanceTotalAmount'), 'BT-107');
lfx_assert_eq('900.00', lfx_xp_str($xp, '//ram:TaxBasisTotalAmount'), 'BT-109');
lfx_assert_eq('180.00', lfx_xp_str($xp, '//ram:TaxTotalAmount'), 'BT-110');
lfx_assert_eq('1080.00', lfx_xp_str($xp, '//ram:DuePayableAmount'), 'BT-115');
echo "U03 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U04 intracom biens (K) + ShipTo';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0003';
$inv->thirdparty = lfx_make_party(['country_code' => 'DE', 'tva_intra' => 'DE129273398', 'idprof2' => '']);
$inv->lines = [lfx_make_line(2000.00, 0.0, 0.00, 1.0, 'Marchandise', 0)];
$inv->total_ht = 2000.00;
$inv->total_tva = 0.00;
$inv->total_ttc = 2000.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('K', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode'), 'catégorie K (biens)');
lfx_assert_eq('VATEX-EU-IC', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:ExemptionReasonCode'), 'BT-121 VATEX-EU-IC');
lfx_assert_eq('DE', lfx_xp_str($xp, '//ram:ShipToTradeParty/ram:PostalTradeAddress/ram:CountryID'), 'BT-80 pays de livraison (BR-IC-12)');
lfx_assert(lfx_xp_str($xp, '//ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString') !== null, 'BT-72 date de livraison (BR-IC-11)');
echo "U04 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U05 intracom services (AE)';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0004';
$inv->thirdparty = lfx_make_party(['country_code' => 'DE', 'tva_intra' => 'DE129273398', 'idprof2' => '']);
$inv->lines = [lfx_make_line(2000.00, 0.0, 0.00, 1.0, 'Prestation de conseil', 1)];
$inv->total_ht = 2000.00;
$inv->total_tva = 0.00;
$inv->total_ttc = 2000.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('AE', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode'), 'catégorie AE (services, art. 196)');
lfx_assert_eq('VATEX-EU-AE', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:ExemptionReasonCode'), 'BT-121 VATEX-EU-AE');
echo "U05 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U06 export hors UE (G)';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0005';
$inv->thirdparty = lfx_make_party(['country_code' => 'US', 'tva_intra' => '', 'idprof2' => '']);
$inv->lines = [lfx_make_line(5000.00, 0.0, 0.00)];
$inv->total_ht = 5000.00;
$inv->total_tva = 0.00;
$inv->total_ttc = 5000.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('G', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode'), 'catégorie G');
lfx_assert_eq('VATEX-EU-G', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:ExemptionReasonCode'), 'BT-121 VATEX-EU-G');
echo "U06 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U07 franchise en base (E + FC)';
lfx_reset();
$mysocFranchise = lfx_make_party(['name' => 'MICRO EI', 'idprof2' => '90945830600012', 'tva_intra' => '', 'tva_assuj' => 0]);
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0006';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(500.00, 0.0, 0.00)];
$inv->total_ht = 500.00;
$inv->total_tva = 0.00;
$inv->total_ttc = 500.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysocFranchise, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('E', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode'), 'catégorie E');
lfx_assert_eq('VATEX-FR-FRANCHISE', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:ExemptionReasonCode'), 'BT-121 VATEX-FR-FRANCHISE');
lfx_assert_eq('FC', lfx_xp_str($xp, '//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID/@schemeID'), 'BT-32 SIREN schemeID FC');
echo "U07 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U08 stress arrondis 50 lignes';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0007';
$inv->thirdparty = lfx_make_party();
$inv->lines = [];
for ($i = 0; $i < 50; $i++) {
	// Chaque ligne : 0.33 HT, TVA ligne arrondie par excès à 0.07 (cas Dolibarr)
	$inv->lines[] = lfx_make_line(0.33, 20.0, 0.07, 1.0, 'Article '.$i);
}
// Totaux facture calculés globalement par Dolibarr : 16.50 HT, 3.30 TVA
$inv->total_ht = 16.50;
$inv->total_tva = 3.30;
$inv->total_ttc = 19.80;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('3.30', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CalculatedAmount'), 'TVA réconciliée (BR-CO-17)');
lfx_assert_eq('3.30', lfx_xp_str($xp, '//ram:TaxTotalAmount'), 'BT-110 réconcilié (BR-CO-14)');
lfx_assert_eq('19.80', lfx_xp_str($xp, '//ram:GrandTotalAmount'), 'BT-112');
lfx_assert(!empty($w), 'warning d\'écart d\'arrondi émis');
echo "U08 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U09 finale avec acompte (BT-113 + BG-3)';
lfx_reset([], ['iban' => 'FR7630006000011234567890189', 'bic' => 'AGRIFRPP'], [
	'societe_remise_except' => [(object) ['fk_facture_source' => 9]],
	'FROM '.MAIN_DB_PREFIX.'facture WHERE rowid = 9' => [(object) ['ref' => 'AC2606-0009', 'datef' => '2026-05-01']],
]);
$inv = new LfxFakeInvoice();
$inv->id = 10;
$inv->ref = 'FA2606-0008';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00)];
$inv->total_ht = 1000.00;
$inv->total_tva = 200.00;
$inv->total_ttc = 1200.00;
$inv->sumDepositsUsed = 240.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('240.00', lfx_xp_str($xp, '//ram:TotalPrepaidAmount'), 'BT-113 acompte imputé');
lfx_assert_eq('960.00', lfx_xp_str($xp, '//ram:DuePayableAmount'), 'BT-115 = 1200 - 240');
lfx_assert_eq('AC2606-0009', lfx_xp_str($xp, '//ram:InvoiceReferencedDocument/ram:IssuerAssignedID'), 'BG-3 facture d\'acompte');
echo "U09 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U10 virement sans IBAN -> PaymentMeans omis (BR-61)';
lfx_reset(['AUVOERECHNUNG_BANK_ACCOUNT' => 0], null);
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0009';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(100.00, 20.0, 20.00)];
$inv->total_ht = 100.00;
$inv->total_tva = 20.00;
$inv->total_ttc = 120.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq(0, $xp->query('//ram:SpecifiedTradeSettlementPaymentMeans')->length, 'bloc PaymentMeans omis');
lfx_assert(!empty($w), 'warning BR-61 émis');
echo "U10 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U11 prélèvement SEPA 59 (ICS/RUM/IBAN débiteur)';
lfx_reset([
	'AUVOERECHNUNG_PAYMENT_MEANS' => '59',
	'PRELEVEMENT_ICS' => 'FR12ZZZ123456',
], ['iban' => 'FR7630006000011234567890189', 'bic' => 'AGRIFRPP'], [
	'societe_rib' => [(object) ['rum' => 'RUM-2026-001', 'iban_prefix' => 'FR7611111111111111111111111']],
]);
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0010';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(100.00, 20.0, 20.00)];
$inv->total_ht = 100.00;
$inv->total_tva = 20.00;
$inv->total_ttc = 120.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('FR12ZZZ123456', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:CreditorReferenceID'), 'BT-90 ICS');
lfx_assert_eq('RUM-2026-001', lfx_xp_str($xp, '//ram:SpecifiedTradePaymentTerms/ram:DirectDebitMandateID'), 'BT-89 RUM');
lfx_assert_eq('FR7611111111111111111111111', lfx_xp_str($xp, '//ram:PayerPartyDebtorFinancialAccount/ram:IBANID'), 'BT-91 IBAN débiteur');
echo "U11 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U12 quantités/prix décimaux';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0011';
$inv->thirdparty = lfx_make_party();
$inv->lines = [
	lfx_make_line(12.50, 20.0, 2.50, 0.125, 'Lot fractionné'),
	lfx_make_line(100.00, 20.0, 20.00, 3.0, 'Tiers d\'heure'),
];
$inv->total_ht = 112.50;
$inv->total_tva = 22.50;
$inv->total_ttc = 135.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('0.125', lfx_xp_str($xp, '(//ram:BilledQuantity)[1]'), 'quantité 4 décimales sans zéros traînants');
lfx_assert_eq('100.00', lfx_xp_str($xp, '(//ram:NetPriceProductTradePrice/ram:ChargeAmount)[1]'), 'prix unitaire 12.50/0.125');
lfx_assert_eq('33.3333', lfx_xp_str($xp, '(//ram:NetPriceProductTradePrice/ram:ChargeAmount)[2]'), 'prix unitaire 100/3 en 4 décimales');
echo "U12 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U13 deux catégories au même taux (K + AE à 0%)';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0012';
$inv->thirdparty = lfx_make_party(['country_code' => 'DE', 'tva_intra' => 'DE129273398', 'idprof2' => '']);
$inv->lines = [
	lfx_make_line(1000.00, 0.0, 0.00, 1.0, 'Marchandise', 0),
	lfx_make_line(500.00, 0.0, 0.00, 1.0, 'Installation', 1),
];
$inv->total_ht = 1500.00;
$inv->total_tva = 0.00;
$inv->total_ttc = 1500.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq(2, $xp->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax')->length, 'deux blocs de ventilation (clé catégorie|taux)');
echo "U13 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U14 BT-8 (depuis TAX_MODE) + BT-10 + BT-13';
lfx_reset([
	'TAX_MODE' => 2,
], ['iban' => 'FR7630006000011234567890189', 'bic' => 'AGRIFRPP'], [
	'element_element' => [(object) ['ref_client' => 'BESTELLUNG-4711']],
]);
$inv = new LfxFakeInvoice();
$inv->id = 11;
$inv->ref = 'FA2606-0013';
$inv->ref_client = 'SERVICE-ACHATS-75';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00)];
$inv->total_ht = 1000.00;
$inv->total_tva = 200.00;
$inv->total_ttc = 1200.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
// BT-23 (BR-FR-08) : cadre socle émis en profil PDP — B1 = dépôt fournisseur de biens
lfx_assert_eq('B1', lfx_xp_str($xp, '//ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID'), 'BT-23 socle B1 en profil PDP (BR-FR-08)');
lfx_assert_eq('72', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:DueDateTypeCode'), 'BT-8 = 72 (TAX_MODE=2 encaissements)');
lfx_assert_eq('SERVICE-ACHATS-75', lfx_xp_str($xp, '//ram:BuyerReference'), 'BT-10 ref client');
lfx_assert_eq('BESTELLUNG-4711', lfx_xp_str($xp, '//ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID'), 'BT-13 = n° de commande de l\'acheteur (réf. client de la commande liée)');
// Commande liée sans réf. client : pas de BT-13 (jamais la réf. interne CO…).
lfx_reset(['TAX_MODE' => 2], ['iban' => 'FR7630006000011234567890189', 'bic' => 'AGRIFRPP'], [
	'element_element' => [(object) ['ref_client' => '']],
]);
$xp = lfx_xpath(auvoerechnung_build_xml($inv, $mysoc, $w));
lfx_assert_eq(0, $xp->query('//ram:BuyerOrderReferencedDocument')->length, 'commande liée sans réf. client : pas de BT-13');
echo "U14 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U15 rectificative (384) + situation (warning)';
lfx_reset([], ['iban' => 'FR7630006000011234567890189', 'bic' => 'AGRIFRPP'], [
	'FROM '.MAIN_DB_PREFIX.'facture WHERE rowid = 7' => [(object) ['ref' => 'FA2605-0007', 'datef' => '2026-05-02']],
]);
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0014';
$inv->type = 1; // TYPE_REPLACEMENT
$inv->fk_facture_source = 7;
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00)];
$inv->total_ht = 1000.00;
$inv->total_tva = 200.00;
$inv->total_ttc = 1200.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('384', lfx_xp_str($xp, '//rsm:ExchangedDocument/ram:TypeCode'), 'TypeCode 384 rectificative');
lfx_assert_eq('FA2605-0007', lfx_xp_str($xp, '//ram:InvoiceReferencedDocument/ram:IssuerAssignedID'), 'BG-3 facture remplacée');

$inv->type = 5; // TYPE_SITUATION
$inv->fk_facture_source = null;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
$xp = lfx_xpath($xml);
lfx_assert_eq('380', lfx_xp_str($xp, '//rsm:ExchangedDocument/ram:TypeCode'), 'situation en 380');
lfx_assert(!empty($w), 'warning situation émis');
echo "U15 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U16 multidevise refusée + BG-14 période';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->multicurrency_code = 'USD';
$err = auvoerechnung_check_supported($inv);
lfx_assert($err !== null, 'multidevise détectée comme non supportée');
$inv->multicurrency_code = 'EUR';
lfx_assert(auvoerechnung_check_supported($inv) === null, 'EUR = devise société acceptée');

$inv2 = new LfxFakeInvoice();
$inv2->ref = 'FA2606-0015';
$inv2->thirdparty = lfx_make_party();
$line = lfx_make_line(1000.00, 20.0, 200.00);
$line->date_start = mktime(0, 0, 0, 5, 1, 2026);
$line->date_end = mktime(0, 0, 0, 5, 31, 2026);
$inv2->lines = [$line];
$inv2->total_ht = 1000.00;
$inv2->total_tva = 200.00;
$inv2->total_ttc = 1200.00;
$w = [];
$xml = auvoerechnung_build_xml($inv2, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('20260501', lfx_xp_str($xp, '//ram:BillingSpecifiedPeriod/ram:StartDateTime/udt:DateTimeString'), 'BG-14 début');
lfx_assert_eq('20260531', lfx_xp_str($xp, '//ram:BillingSpecifiedPeriod/ram:EndDateTime/udt:DateTimeString'), 'BG-14 fin');
echo "U16 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U17 validateur BR détecte les violations';
// XML volontairement faux : prix négatif + total incohérent
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0016';
$inv->thirdparty = lfx_make_party();
$inv->lines = [
	lfx_make_line(-50.00, 20.0, -10.00, 1.0, 'Remise orpheline'),
	lfx_make_line(-25.00, 20.0, -5.00, 1.0, 'Autre remise'),
];
$inv->total_ht = -75.00;
$inv->total_tva = -15.00;
$inv->total_ttc = -90.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w); // 380 entièrement négatif : lignes ré-émises brutes
$violations = auvoerechnung_validate_business_rules($xml);
lfx_assert(!empty($violations), 'violations détectées sur facture entièrement négative');
$hasBr27 = false;
foreach ($violations as $v) {
	if (strpos($v, 'BR-27') === 0) {
		$hasBr27 = true;
	}
}
lfx_assert($hasBr27, 'BR-27 détectée');
lfx_assert(!empty($w), 'warning facture entièrement négative émis');
echo "U17 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U19 franchise avec TVA réelle -> S (pas E contradictoire)';
lfx_reset();
$mysocFranchise2 = lfx_make_party(['name' => 'EX-MICRO', 'idprof2' => '90945830600012', 'tva_intra' => 'FR38909458306', 'tva_assuj' => 0]);
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0017';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00)]; // ancienne facture régénérée après passage en franchise
$inv->total_ht = 1000.00;
$inv->total_tva = 200.00;
$inv->total_ttc = 1200.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysocFranchise2, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('S', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode'), 'ligne avec TVA réelle reste en S (BR-E-05)');
echo "U19 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U20 acheteur étranger : idprof2 non publié en SIREN/SIRET';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0018';
$inv->thirdparty = lfx_make_party(['country_code' => 'DE', 'tva_intra' => 'DE129273398', 'idprof2' => 'HRB 123456789']);
$inv->lines = [lfx_make_line(2000.00, 0.0, 0.00, 1.0, 'Marchandise', 0)];
$inv->total_ht = 2000.00;
$inv->total_tva = 0.00;
$inv->total_ttc = 2000.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq(0, $xp->query('//ram:BuyerTradeParty/ram:SpecifiedLegalOrganization')->length, 'pas de SpecifiedLegalOrganization avec un identifiant local étranger');
lfx_assert_eq('EM', lfx_xp_str($xp, '//ram:BuyerTradeParty/ram:URIUniversalCommunication/ram:URIID/@schemeID'), 'endpoint en repli email (pas de faux SIREN 0225)');
echo "U20 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U21 taxes locales refusées proprement';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->total_localtax1 = 12.50;
lfx_assert(auvoerechnung_check_supported($inv) !== null, 'localtax détectée comme non supportée');
$inv->total_localtax1 = 0.0;
lfx_assert(auvoerechnung_check_supported($inv) === null, 'sans localtax : supporté');
echo "U21 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U18 fonctions de formatage';
lfx_assert_eq('10', auvoerechnung_format_qty(10.0), 'qty entière');
lfx_assert_eq('0.125', auvoerechnung_format_qty(0.125), 'qty 3 décimales');
lfx_assert_eq('2.5', auvoerechnung_format_qty(2.50), 'qty zéros retirés');
lfx_assert_eq('100.00', auvoerechnung_format_unit_price(100.0), 'prix 2 décimales');
lfx_assert_eq('33.3333', auvoerechnung_format_unit_price(100 / 3), 'prix 4 décimales');
lfx_assert_eq('1234.50', auvoerechnung_format_amount(1234.5), 'montant');
lfx_assert_eq('a &amp; b &lt;c&gt;', auvoerechnung_xml_encode('a & b <c>'), 'échappement XML');
lfx_assert_eq('123456789', auvoerechnung_extract_siren('12345678900011'), 'SIREN depuis SIRET');
echo "U18 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U22 BR-FR-01/02 identifiant de facture (BT-1)';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0001-AVEC-UNE-REFERENCE-BEAUCOUP-TROP-LONGUE'; // 51 caractères
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(100.00, 20.0, 20.00)];
$inv->total_ht = 100.00;
$inv->total_tva = 20.00;
$inv->total_ttc = 120.00;
$w = [];
$violations = auvoerechnung_validate_business_rules(auvoerechnung_build_xml($inv, $mysoc, $w));
lfx_assert(lfx_contains($violations, 'BR-FR-01'), 'BR-FR-01 détectée (BT-1 > 35 caractères)');
lfx_assert(!lfx_contains($violations, 'BR-FR-02'), 'pas de BR-FR-02 (charset valide)');
lfx_assert(lfx_contains(auvoerechnung_check_mandatory($inv, $mysoc), 'AuvoERechnungWarnRefTooLong'), 'warning amont BT-1 trop long (check_mandatory)');

$inv->ref = 'FA 2606 (BIS)'; // espace et parenthèses interdits
$w = [];
$violations = auvoerechnung_validate_business_rules(auvoerechnung_build_xml($inv, $mysoc, $w));
lfx_assert(lfx_contains($violations, 'BR-FR-02'), 'BR-FR-02 détectée (espace/parenthèses dans BT-1)');
lfx_assert(lfx_contains(auvoerechnung_check_mandatory($inv, $mysoc), 'AuvoERechnungWarnRefCharset'), 'warning amont charset BT-1 (check_mandatory)');

$inv->ref = 'FA/2606+01_A'; // - + _ / admis par BR-FR-02
$w = [];
$violations = auvoerechnung_validate_business_rules(auvoerechnung_build_xml($inv, $mysoc, $w));
lfx_assert(!lfx_contains($violations, 'BR-FR-01') && !lfx_contains($violations, 'BR-FR-02'), 'référence avec - + _ / acceptée');
echo "U22 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U23 BR-FR-09 cohérence SIREN/SIRET';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0020';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(100.00, 20.0, 20.00)];
$inv->total_ht = 100.00;
$inv->total_tva = 20.00;
$inv->total_ttc = 120.00;
// Vendeur : SIREN saisi (idprof1) sans rapport avec le SIRET (idprof2)
$socBad = lfx_make_party(['name' => 'INCOHERENT SARL', 'idprof1' => '111222333', 'idprof2' => '99988877700012', 'tva_intra' => 'FR00111222333']);
$w = [];
$violations = auvoerechnung_validate_business_rules(auvoerechnung_build_xml($inv, $socBad, $w));
lfx_assert(lfx_contains($violations, 'BR-FR-09'), 'BR-FR-09 détectée (SIRET vendeur ≠ SIREN)');
lfx_assert(lfx_contains(auvoerechnung_check_mandatory($inv, $socBad), 'AuvoERechnungWarnSellerSirenSiretMismatch'), 'warning amont vendeur (check_mandatory)');
// Acheteur : même incohérence côté tiers
$inv->thirdparty = lfx_make_party(['idprof1' => '555666777', 'idprof2' => '12345678900011']);
$w = [];
$violations = auvoerechnung_validate_business_rules(auvoerechnung_build_xml($inv, $mysoc, $w));
lfx_assert(lfx_contains($violations, 'BR-FR-09'), 'BR-FR-09 détectée (SIRET acheteur ≠ SIREN)');
lfx_assert(lfx_contains(auvoerechnung_check_mandatory($inv, $mysoc), 'AuvoERechnungWarnBuyerSirenSiretMismatch'), 'warning amont acheteur (check_mandatory)');
// Cohérent : aucune violation BR-FR-09
$inv->thirdparty = lfx_make_party();
$w = [];
$violations = auvoerechnung_validate_business_rules(auvoerechnung_build_xml($inv, $mysoc, $w));
lfx_assert(!lfx_contains($violations, 'BR-FR-09'), 'pas de BR-FR-09 quand SIREN et SIRET concordent');
echo "U23 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U24 BR-FR-CO-04/CO-05 avoir et rectificative sans facture d\'origine';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'AV2606-0002';
$inv->type = 2; // TYPE_CREDIT_NOTE, sans fk_facture_source
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(-500.00, 20.0, -100.00)];
$inv->total_ht = -500.00;
$inv->total_tva = -100.00;
$inv->total_ttc = -600.00;
$w = [];
$violations = auvoerechnung_validate_business_rules(auvoerechnung_build_xml($inv, $mysoc, $w));
lfx_assert(lfx_contains($violations, 'BR-FR-CO-05'), 'BR-FR-CO-05 : avoir sans référence BT-25 bloqué');
lfx_assert(lfx_contains($w, 'AuvoERechnungWarnCreditNoteNoSource'), 'warning avoir sans origine conservé');

$inv2 = new LfxFakeInvoice();
$inv2->ref = 'FA2606-0021';
$inv2->type = 1; // TYPE_REPLACEMENT, sans fk_facture_source
$inv2->thirdparty = lfx_make_party();
$inv2->lines = [lfx_make_line(500.00, 20.0, 100.00)];
$inv2->total_ht = 500.00;
$inv2->total_tva = 100.00;
$inv2->total_ttc = 600.00;
$w2 = [];
$violations = auvoerechnung_validate_business_rules(auvoerechnung_build_xml($inv2, $mysoc, $w2));
lfx_assert(lfx_contains($violations, 'BR-FR-CO-04'), 'BR-FR-CO-04 : rectificative sans référence BT-25 bloquée');
lfx_assert(lfx_contains($w2, 'AuvoERechnungWarnCorrectiveNoSource'), 'warning rectificative sans origine émis');
echo "U24 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U24bis rectificative 384 + acompte imputé : une seule référence BG-3';
lfx_reset([], ['iban' => 'FR7630006000011234567890189', 'bic' => 'AGRIFRPP'], [
	'societe_remise_except' => [(object) ['fk_facture_source' => 9]],
	'FROM '.MAIN_DB_PREFIX.'facture WHERE rowid = 9' => [(object) ['ref' => 'AC2606-0009', 'datef' => '2026-05-01']],
	'FROM '.MAIN_DB_PREFIX.'facture WHERE rowid = 42' => [(object) ['ref' => 'FA2605-0099', 'datef' => '2026-05-12']],
]);
$inv = new LfxFakeInvoice();
$inv->id = 11;
$inv->ref = 'FA2606-0027';
$inv->type = 1; // TYPE_REPLACEMENT → 384
$inv->fk_facture_source = 42; // facture remplacée
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00)];
$inv->total_ht = 1000.00;
$inv->total_tva = 200.00;
$inv->total_ttc = 1200.00;
$inv->sumDepositsUsed = 240.00; // acompte consommé via remise exceptionnelle
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
$violations = lfx_std_validate($xml, $xsdPath);
lfx_assert(!lfx_contains($violations, 'BR-FR-CO-04'), 'pas de fausse violation BR-FR-CO-04 sur une 384 avec acompte imputé');
$xp = lfx_xpath($xml);
lfx_assert_eq('384', lfx_xp_str($xp, '//rsm:ExchangedDocument/ram:TypeCode'), 'TypeCode 384');
lfx_assert_eq(1, $xp->query('//ram:InvoiceReferencedDocument')->length, 'une et une seule référence BT-25 (BR-FR-CO-04)');
lfx_assert_eq('FA2605-0099', lfx_xp_str($xp, '//ram:InvoiceReferencedDocument/ram:IssuerAssignedID'), 'BG-3 = la facture remplacée (pas l\'acompte)');
lfx_assert_eq('20260512', lfx_xp_str($xp, '//ram:InvoiceReferencedDocument/ram:FormattedIssueDateTime/qdt:DateTimeString'), 'BG-3 date de la facture remplacée (BT-26)');
lfx_assert_eq('240.00', lfx_xp_str($xp, '//ram:TotalPrepaidAmount'), 'BT-113 acompte imputé conservé');
lfx_assert_eq('960.00', lfx_xp_str($xp, '//ram:DuePayableAmount'), 'BT-115 = 1200 - 240');
echo "U24bis OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U25 BT-23 socle (BR-FR-08) : S1/M1/B4 et garde acompte';
lfx_reset();
$bt23Query = '//ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID';
// Services uniquement → S1
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0022';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00, 1.0, 'Prestation', 1)];
$inv->total_ht = 1000.00;
$inv->total_tva = 200.00;
$inv->total_ttc = 1200.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
lfx_assert_eq('S1', lfx_xp_str(lfx_xpath($xml), $bt23Query), 'BT-23 = S1 (services)');
// Biens + services → M1
$inv->lines = [
	lfx_make_line(600.00, 20.0, 120.00, 1.0, 'Marchandise', 0),
	lfx_make_line(400.00, 20.0, 80.00, 1.0, 'Installation', 1),
];
$w = [];
lfx_assert_eq('M1', lfx_xp_str(lfx_xpath(auvoerechnung_build_xml($inv, $mysoc, $w)), $bt23Query), 'BT-23 = M1 (double)');
// Facture définitive après acompte → B4
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00, 1.0, 'Marchandise', 0)];
$inv->sumDepositsUsed = 240.00;
$w = [];
lfx_assert_eq('B4', lfx_xp_str(lfx_xpath(auvoerechnung_build_xml($inv, $mysoc, $w)), $bt23Query), 'BT-23 = B4 (définitive après acompte)');
// Facture d'acompte (386) : jamais de cadre x4 (BR-FR-CO-08)
$inv->type = 3; // TYPE_DEPOSIT
$w = [];
lfx_assert_eq('B1', lfx_xp_str(lfx_xpath(auvoerechnung_build_xml($inv, $mysoc, $w)), $bt23Query), 'BT-23 = B1 sur une facture d\'acompte (garde BR-FR-CO-08)');
echo "U25 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U26 note BAR (BR-FR-07/20/31)';
lfx_reset();
$barQuery = '//ram:IncludedNote[ram:SubjectCode="BAR"]/ram:Content';
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0023';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00)];
$inv->total_ht = 1000.00;
$inv->total_tva = 200.00;
$inv->total_ttc = 1200.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('B2B', lfx_xp_str($xp, $barQuery), 'note BAR = B2B (acheteur assujetti FR)');
lfx_assert_eq(1, $xp->query('//ram:IncludedNote[ram:SubjectCode="BAR"]')->length, 'une seule note BAR (BR-FR-31)');
// Acheteur assujetti UE → B2BINT (e-reporting international)
$inv->thirdparty = lfx_make_party(['country_code' => 'DE', 'tva_intra' => 'DE129273398', 'idprof2' => '']);
$inv->lines = [lfx_make_line(1000.00, 0.0, 0.00, 1.0, 'Prestation', 1)];
$inv->total_tva = 0.00;
$inv->total_ttc = 1000.00;
$inv->total_ht = 1000.00;
$w = [];
lfx_assert_eq('B2BINT', lfx_xp_str(lfx_xpath(auvoerechnung_build_xml($inv, $mysoc, $w)), $barQuery), 'note BAR = B2BINT (acheteur UE)');
// Particulier → B2C (e-reporting B2C)
$inv->thirdparty = lfx_make_party(['typent_code' => 'TE_PRIVATE', 'idprof2' => '', 'tva_intra' => '']);
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00)];
$inv->total_tva = 200.00;
$inv->total_ttc = 1200.00;
$w = [];
lfx_assert_eq('B2C', lfx_xp_str(lfx_xpath(auvoerechnung_build_xml($inv, $mysoc, $w)), $barQuery), 'note BAR = B2C (particulier)');
// Particulier étranger → B2C aussi : choix assumé, la liste fermée BR-FR-20
// (révision juillet 2025) ne comporte pas de valeur « B2C international »
// (cf. docblock de auvoerechnung_resolve_bar_code)
$inv->thirdparty = lfx_make_party(['typent_code' => 'TE_PRIVATE', 'country_code' => 'DE', 'idprof2' => '', 'tva_intra' => '']);
$w = [];
lfx_assert_eq('B2C', lfx_xp_str(lfx_xpath(auvoerechnung_build_xml($inv, $mysoc, $w)), $barQuery), 'note BAR = B2C (particulier étranger — pas de B2CINT dans la liste fermée BR-FR-20)');
// Profil Chorus B2G : pas de note BAR (marquage B2G distinct, chemin inchangé)
$inv->thirdparty = lfx_make_party();
$w = [];
$xmlChorus = auvoerechnung_build_xml($inv, $mysoc, $w, ['profile' => 'choruspro']);
lfx_assert_eq(0, lfx_xpath($xmlChorus)->query('//ram:IncludedNote[ram:SubjectCode="BAR"]')->length, 'pas de note BAR en profil Chorus');
echo "U26 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U27 suffixe endpoint vendeur sanitisé (BR-FR-23)';
lfx_reset(['AUVOERECHNUNG_ENDPOINT_SUFFIX_SELLER' => '_Status!*']);
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0024';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(100.00, 20.0, 20.00)];
$inv->total_ht = 100.00;
$inv->total_tva = 20.00;
$inv->total_ttc = 120.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
lfx_assert_eq('909458306_Status', lfx_xp_str(lfx_xpath($xml), '//ram:SellerTradeParty/ram:URIUniversalCommunication/ram:URIID'), 'suffixe sanitisé : « !* » retirés (BR-FR-23)');
lfx_assert(lfx_contains(auvoerechnung_check_mandatory($inv, $mysoc), 'AuvoERechnungWarnEndpointSuffixCharset'), 'warning charset du suffixe (check_mandatory)');
// Suffixe déjà conforme : conservé tel quel, aucun warning charset
lfx_reset(['AUVOERECHNUNG_ENDPOINT_SUFFIX_SELLER' => '_Status']);
$w = [];
lfx_assert_eq('909458306_Status', lfx_xp_str(lfx_xpath(auvoerechnung_build_xml($inv, $mysoc, $w)), '//ram:SellerTradeParty/ram:URIUniversalCommunication/ram:URIID'), 'suffixe conforme conservé');
lfx_assert(!lfx_contains(auvoerechnung_check_mandatory($inv, $mysoc), 'AuvoERechnungWarnEndpointSuffixCharset'), 'pas de warning sur suffixe conforme');
echo "U27 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U28 BR-FR-04 type de document hors liste FR';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0025';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(100.00, 20.0, 20.00)];
$inv->total_ht = 100.00;
$inv->total_tva = 20.00;
$inv->total_ttc = 120.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
lfx_assert(!lfx_contains(auvoerechnung_validate_business_rules($xml), 'BR-FR-04'), '380 admis par BR-FR-04');
// XML altéré : 751 (facture d'information, UNTDID 1001) est proscrit en France
$xmlBad = str_replace('<ram:TypeCode>380</ram:TypeCode>', '<ram:TypeCode>751</ram:TypeCode>', $xml);
lfx_assert(lfx_contains(auvoerechnung_validate_business_rules($xmlBad), 'BR-FR-04'), 'BR-FR-04 détectée (TypeCode 751 hors liste)');
echo "U28 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U29 BR-FR-16 taux de TVA hors liste FR (warning)';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0026';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(100.00, 19.0, 19.00)]; // 19 % : pas un taux français
$inv->total_ht = 100.00;
$inv->total_tva = 19.00;
$inv->total_ttc = 119.00;
lfx_assert(lfx_contains(auvoerechnung_check_mandatory($inv, $mysoc), 'AuvoERechnungWarnVatRateNotFR'), 'warning BR-FR-16 sur un taux à 19 %');
$inv->lines = [lfx_make_line(100.00, 8.5, 8.50)]; // 8,5 % : taux DOM légitime
$inv->total_tva = 8.50;
$inv->total_ttc = 108.50;
lfx_assert(!lfx_contains(auvoerechnung_check_mandatory($inv, $mysoc), 'AuvoERechnungWarnVatRateNotFR'), 'pas de warning sur le taux DOM 8,5 %');
// Jamais bloquant : le validateur ne porte aucune violation BR-FR-16
$w = [];
lfx_assert(!lfx_contains(auvoerechnung_validate_business_rules(auvoerechnung_build_xml($inv, $mysoc, $w)), 'BR-FR-16'), 'BR-FR-16 non bloquante (warning uniquement)');
echo "U29 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U30 vendeur non français : règles BR-FR non opposables';
lfx_reset();
$mysocDE = lfx_make_party(['name' => 'ACME GMBH', 'country_code' => 'DE', 'idprof1' => '', 'idprof2' => '', 'tva_intra' => 'DE129273398', 'zip' => '10115', 'town' => 'Berlin']);
$inv = new LfxFakeInvoice();
$inv->ref = 'RE 2026 0042'; // espaces : violerait BR-FR-02 pour un vendeur FR
$inv->type = 1; // rectificative sans facture d'origine : violerait BR-FR-CO-04
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(100.00, 20.0, 20.00)];
$inv->total_ht = 100.00;
$inv->total_tva = 20.00;
$inv->total_ttc = 120.00;
$w = [];
$violations = auvoerechnung_validate_business_rules(auvoerechnung_build_xml($inv, $mysocDE, $w));
lfx_assert(!lfx_contains($violations, 'BR-FR'), 'aucune violation BR-FR pour un vendeur DE ('.implode(' ; ', $violations).')');
// Contre-épreuve : même facture avec vendeur FR → le bloc BR-FR s'applique
$w = [];
$violations = auvoerechnung_validate_business_rules(auvoerechnung_build_xml($inv, $mysoc, $w));
lfx_assert(lfx_contains($violations, 'BR-FR-02'), 'contre-épreuve vendeur FR : BR-FR-02 détectée');
lfx_assert(lfx_contains($violations, 'BR-FR-CO-04'), 'contre-épreuve vendeur FR : BR-FR-CO-04 détectée');
echo "U30 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U31 lignes de présentation product_type=9 exclues (titres/sous-totaux)';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2607-0779';
$inv->thirdparty = lfx_make_party();
$inv->lines = [
	// Titre natif Dolibarr (module "Subtotals", special_code 81) : qty = profondeur,
	// total_ht = 0 (jamais stocké, seulement calculé à l'affichage/PDF).
	lfx_make_line(0.00, 0.0, 0.00, 1.0, 'SPECTACLE CLE EN MAIN', 9, ['special_code' => 81]),
	lfx_make_line(1000.00, 20.0, 200.00, 1.0, 'Prestation'),
	// Sous-total ATM Consulting (special_code 104777) : reprend le montant cumulé
	// des lignes précédentes -> compterait en double si non exclu.
	lfx_make_line(1000.00, 20.0, 200.00, 1.0, 'Sous-total spectacle clé en main', 9, ['special_code' => 104777]),
];
$inv->total_ht = 1000.00;
$inv->total_tva = 200.00;
$inv->total_ttc = 1200.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq(1, $xp->query('//ram:IncludedSupplyChainTradeLineItem')->length, 'seule la ligne facturable réelle est exportée (titre et sous-total exclus)');
lfx_assert_eq('1000.00', lfx_xp_str($xp, '//ram:LineTotalAmount'), 'pas de double comptage du sous-total dans BT-106');
lfx_assert_eq('1200.00', lfx_xp_str($xp, '//ram:GrandTotalAmount'), 'GrandTotal = seule ligne réelle');
lfx_assert_eq('1200.00', lfx_xp_str($xp, '//ram:DuePayableAmount'), 'DuePayable = seule ligne réelle');
lfx_assert(empty($w), 'aucun warning de mismatch de totaux ('.implode(' ; ', $w).')');
echo "U31 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U32 vendeur allemand Kleinunternehmer (§ 19 UStG) — profil ZUGFeRD/EN16931';
lfx_reset([], ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001']);
// Conventions Dolibarr DE : idprof1 = Steuernummer, idprof2 = Amtsgericht.
// Un Kleinunternehmer n'a pas d'USt-IdNr : tva_intra est vide.
$deSoc = lfx_make_party([
	'name' => 'MUSTER GMBH', 'country_code' => 'DE', 'zip' => '10115', 'town' => 'Berlin',
	'address' => 'Chausseestr. 1', 'email' => 'rechnung@muster.example',
	'tva_intra' => '', 'idprof1' => '151/815/08154', 'idprof2' => 'Amtsgericht Berlin', 'tva_assuj' => 0,
]);
$deBuyer = lfx_make_party([
	'name' => 'KUNDE AG', 'country_code' => 'DE', 'zip' => '80331', 'town' => 'München',
	'tva_intra' => 'DE123456789', 'idprof1' => '', 'idprof2' => '', 'tva_assuj' => 1,
]);
$inv = new LfxFakeInvoice();
$inv->ref = 'RE2026-0042';
$inv->thirdparty = $deBuyer;
$inv->lines = [lfx_make_line(1000.00, 0.0, 0.00, 1.0, 'Beratungsleistung', 1)];
$inv->total_ht = 1000.00;
$inv->total_tva = 0.00;
$inv->total_ttc = 1000.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $deSoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
// BT-32 : la Steuernummer vient de idprof1 (ProfId1DE), jamais l'Amtsgericht d'idprof2.
lfx_assert_eq('151/815/08154', lfx_xp_str($xp, '//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="FC"]'), 'BT-32 = Steuernummer (idprof1) sous schemeID FC');
lfx_assert_eq(0, $xp->query('//ram:SellerTradeParty/ram:SpecifiedLegalOrganization')->length, 'pas de SIREN/BT-30 pour un vendeur allemand');
// Motif d'exonération § 19 UStG, sans code VATEX (aucun code CEF ne correspond).
lfx_assert_eq('E', lfx_xp_str($xp, '//ram:ApplicableTradeTax/ram:CategoryCode'), 'catégorie TVA E (exonéré)');
lfx_assert(strpos((string) lfx_xp_str($xp, '//ram:ApplicableTradeTax/ram:ExemptionReason'), '§ 19 UStG') !== false, 'motif d\'exonération allemand (§ 19 UStG)');
lfx_assert_eq(0, $xp->query('//ram:ApplicableTradeTax/ram:ExemptionReasonCode')->length, 'pas de code VATEX français sur une facture allemande');
// Aucun élément du socle réforme FR ne doit fuiter dans une facture allemande.
lfx_assert_eq(0, $xp->query('//ram:BusinessProcessSpecifiedDocumentContextParameter')->length, 'BT-23 (cadre XP Z12-012) non émis hors vendeur FR');
lfx_assert_eq(0, $xp->query('//ram:IncludedNote')->length, 'aucune mention légale française (PMD/PMT/AAB/BAR) hors vendeur FR');
lfx_assert(!lfx_contains(auvoerechnung_check_mandatory($inv, $deSoc), 'AuvoERechnungWarnSellerSIRET'), 'pas de warning SIRET pour un vendeur allemand');
echo "U32 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U33 mentions légales configurées explicitement par un vendeur allemand';
lfx_reset([
	'AUVOERECHNUNG_NOTE_PMD' => 'Bei Zahlungsverzug werden Verzugszinsen gemäß § 288 BGB berechnet.',
], ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001']);
$inv = new LfxFakeInvoice();
$inv->ref = 'RE2026-0043';
$inv->thirdparty = $deBuyer;
$inv->lines = [lfx_make_line(1000.00, 0.0, 0.00, 1.0, 'Beratungsleistung', 1)];
$inv->total_ht = 1000.00;
$inv->total_tva = 0.00;
$inv->total_ttc = 1000.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $deSoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq(1, $xp->query('//ram:IncludedNote')->length, 'seule la mention explicitement configurée est émise');
lfx_assert_eq('PMD', lfx_xp_str($xp, '//ram:IncludedNote/ram:SubjectCode'), 'code sujet PMD conservé');
lfx_assert(strpos((string) lfx_xp_str($xp, '//ram:IncludedNote/ram:Content'), '§ 288 BGB') !== false, 'contenu allemand saisi par l\'utilisateur');
echo "U33 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U34 vendeur français : socle réforme FR inchangé (non-régression)';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2026-0044';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00)];
$inv->total_ht = 1000.00;
$inv->total_tva = 200.00;
$inv->total_ttc = 1200.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('B1', lfx_xp_str($xp, '//ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID'), 'BT-23 toujours émis pour un vendeur FR');
lfx_assert_eq(4, $xp->query('//ram:IncludedNote')->length, 'les 4 notes FR (PMD/PMT/AAB/BAR) restent émises');
lfx_assert_eq('B2B', lfx_xp_str($xp, '//ram:IncludedNote[ram:SubjectCode="BAR"]/ram:Content'), 'note BAR toujours émise pour un vendeur FR');
echo "U34 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U35 Leitweg-ID prioritaire en BT-10 pour un acheteur secteur public allemand';
lfx_reset();
$deBuyerLeitweg = lfx_make_party([
	'name' => 'STADT MUSTERSTADT', 'country_code' => 'DE',
	'array_options' => ['options_lfxleitwegid' => '04011000-1234512345-06'],
]);
$deBuyerNoLeitweg = lfx_make_party(['name' => 'KUNDE OHNE LEITWEG', 'country_code' => 'DE']);
$frBuyerStrayLeitweg = lfx_make_party([
	'array_options' => ['options_lfxleitwegid' => '04011000-1234512345-06'],
]);

// (a) Leitweg-ID gagne sur ref_client pour un acheteur allemand.
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2026-0045';
$inv->ref_client = 'PO-9999';
$inv->thirdparty = $deBuyerLeitweg;
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00)];
$inv->total_ht = 1000.00;
$inv->total_tva = 200.00;
$inv->total_ttc = 1200.00;
$w = [];
$xp = lfx_xpath(auvoerechnung_build_xml($inv, $mysoc, $w));
lfx_assert_eq('04011000-1234512345-06', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeAgreement/ram:BuyerReference'), 'BT-10 = Leitweg-ID, prioritaire sur ref_client');

// (b) Leitweg-ID gagne aussi sur le code service Chorus.
$w = [];
$xp = lfx_xpath(auvoerechnung_build_xml($inv, $mysoc, $w, ['profile' => 'choruspro', 'service_code' => 'SVC1']));
lfx_assert_eq('04011000-1234512345-06', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeAgreement/ram:BuyerReference'), 'BT-10 = Leitweg-ID, prioritaire sur le code service Chorus');

// (c) Non-régression : acheteur allemand SANS Leitweg-ID -> ref_client toujours utilisé.
$inv2 = new LfxFakeInvoice();
$inv2->ref = 'FA2026-0046';
$inv2->ref_client = 'PO-8888';
$inv2->thirdparty = $deBuyerNoLeitweg;
$inv2->lines = [lfx_make_line(1000.00, 20.0, 200.00)];
$inv2->total_ht = 1000.00;
$inv2->total_tva = 200.00;
$inv2->total_ttc = 1200.00;
$w = [];
$xp = lfx_xpath(auvoerechnung_build_xml($inv2, $mysoc, $w));
lfx_assert_eq('PO-8888', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeAgreement/ram:BuyerReference'), 'BT-10 = ref_client quand aucune Leitweg-ID (le filtre DE ne casse pas le repli existant)');

// (d) Garde-fou pays : une Leitweg-ID posée par erreur sur un acheteur non-allemand est ignorée.
$inv3 = new LfxFakeInvoice();
$inv3->ref = 'FA2026-0047';
$inv3->ref_client = 'PO-7777';
$inv3->thirdparty = $frBuyerStrayLeitweg;
$inv3->lines = [lfx_make_line(1000.00, 20.0, 200.00)];
$inv3->total_ht = 1000.00;
$inv3->total_tva = 200.00;
$inv3->total_ttc = 1200.00;
$w = [];
$xp = lfx_xpath(auvoerechnung_build_xml($inv3, $mysoc, $w));
lfx_assert_eq('PO-7777', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeAgreement/ram:BuyerReference'), 'BT-10 = ref_client, Leitweg-ID ignorée hors acheteur allemand');
echo "U35 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U36 motifs d\'exonération pour un vendeur allemand assujetti';
$deSeller = lfx_make_party(['name' => 'MUSTER GMBH', 'country_code' => 'DE', 'tva_intra' => 'DE811111111', 'tva_assuj' => 1]);
$frSeller = lfx_make_party(['name' => 'VENDEUR SAS', 'country_code' => 'FR', 'tva_intra' => 'FR38909458306', 'tva_assuj' => 1]);
$buyerDE  = lfx_make_party(['name' => 'KUNDE AG', 'country_code' => 'DE', 'tva_intra' => 'DE123456789']);
$buyerAT  = lfx_make_party(['name' => 'KUNDE GMBH', 'country_code' => 'AT', 'tva_intra' => 'ATU12345678']);
$buyerCH  = lfx_make_party(['name' => 'KUNDE SA', 'country_code' => 'CH', 'tva_intra' => '']);
$goods    = lfx_make_line(100.00, 0.0, 0.00, 1.0, 'Ware', 0);
$service  = lfx_make_line(100.00, 0.0, 0.00, 1.0, 'Beratung', 1);
$inv = new LfxFakeInvoice();

$r = auvoerechnung_resolve_tax_category($goods, $inv, $buyerAT, $deSeller);
lfx_assert_eq('K', $r['code'], 'DE → AT, biens : catégorie K');
lfx_assert(strpos($r['exemption'], '§ 4 Nr. 1b') !== false, 'DE → AT, biens : motif allemand (§ 4 Nr. 1b UStG)');
$r = auvoerechnung_resolve_tax_category($service, $inv, $buyerAT, $deSeller);
lfx_assert_eq('AE', $r['code'], 'DE → AT, services : catégorie AE');
lfx_assert(strpos($r['exemption'], 'Steuerschuldnerschaft') !== false, 'DE → AT, services : motif allemand (Reverse Charge)');
$r = auvoerechnung_resolve_tax_category($goods, $inv, $buyerCH, $deSeller);
lfx_assert_eq('G', $r['code'], 'DE → CH : catégorie G');
lfx_assert(strpos($r['exemption'], 'Ausfuhrlieferung') !== false, 'DE → CH, biens : motif allemand (Ausfuhr)');
// Acheteur du même pays que le vendeur avec n° TVA : pas d'intracommunautaire.
$r = auvoerechnung_resolve_tax_category($goods, $inv, $buyerDE, $deSeller);
lfx_assert_eq('E', $r['code'], 'DE → DE à taux 0 : catégorie E, pas K');
lfx_assert_eq('Umsatzsteuerfrei', $r['exemption'], 'DE → DE à taux 0 : motif allemand');
// Contre-épreuve vendeur FR : comportement inchangé.
$r = auvoerechnung_resolve_tax_category($goods, $inv, $buyerDE, $frSeller);
lfx_assert_eq('K', $r['code'], 'FR → DE, biens : catégorie K');
lfx_assert(strpos($r['exemption'], 'Livraison intracommunautaire') !== false, 'FR → DE : motif français conservé');
echo "U36 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U37 Steuernummer et n° TVA mal saisis (vendeur allemand)';
lfx_reset([], ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001']);
// Lecture de la Steuernummer : idprof1, repli idprof2 (anciennes fiches), repli
// tva_intra sans préfixe pays ; jamais un nom de tribunal ni une USt-IdNr.
lfx_assert_eq('22/180/80394', auvoerechnung_party_steuernummer(lfx_make_party(['idprof1' => '22/180/80394', 'idprof2' => 'Amtsgericht Koblenz'])), 'Steuernummer lue dans idprof1');
lfx_assert_eq('151/815/08154', auvoerechnung_party_steuernummer(lfx_make_party(['idprof1' => '', 'idprof2' => '151/815/08154'])), 'repli idprof2 (anciennes fiches)');
lfx_assert_eq('', auvoerechnung_party_steuernummer(lfx_make_party(['idprof1' => '', 'idprof2' => 'Amtsgericht Koblenz', 'tva_intra' => 'DE811111111'])), 'ni Amtsgericht ni USt-IdNr comme Steuernummer');
lfx_assert(auvoerechnung_is_vat_id('DE 811 111 111') && auvoerechnung_is_vat_id('ATU12345678') && !auvoerechnung_is_vat_id('22/180/80394'), 'reconnaissance du préfixe pays (BR-CO-09)');
// Cas réel : Kleinunternehmer ayant saisi sa Steuernummer dans le champ TVA intra.
$deSoc2 = lfx_make_party([
	'name' => 'MUSTER DESIGN', 'country_code' => 'DE', 'zip' => '56357', 'town' => 'Miehlen',
	'address' => 'Hauptstr. 1', 'email' => 'info@muster.example',
	'tva_intra' => '22/180/80394', 'idprof1' => '22/180/80394', 'idprof2' => '', 'tva_assuj' => 0,
]);
$inv = new LfxFakeInvoice();
$inv->ref = 'RE2026-0099';
$inv->thirdparty = $deBuyer;
$inv->lines = [lfx_make_line(100.00, 0.0, 0.00, 1.0, 'Gestaltung', 1)];
$inv->total_ht = 100.00;
$inv->total_tva = 0.00;
$inv->total_ttc = 100.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $deSoc2, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq(0, $xp->query('//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]')->length, 'Steuernummer non publiée comme USt-IdNr (VA)');
lfx_assert_eq('22/180/80394', lfx_xp_str($xp, '//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="FC"]'), 'Steuernummer publiée en BT-32 (FC)');
lfx_assert(lfx_contains(auvoerechnung_check_mandatory($inv, $deSoc2), 'AuvoERechnungWarnSellerVATInvalid'), 'warning champ TVA intra sans préfixe pays');
// Le validateur détecte un VA sans préfixe (filet de sécurité BR-CO-09).
$xmlBad = str_replace('<ram:ID schemeID="FC">22/180/80394</ram:ID>', '<ram:ID schemeID="VA">22/180/80394</ram:ID>', $xml);
lfx_assert(lfx_contains(auvoerechnung_validate_business_rules($xmlBad), 'BR-CO-09'), 'BR-CO-09 détectée');
lfx_assert(!lfx_contains(auvoerechnung_validate_business_rules($xml), 'BR-CO-09'), 'pas de BR-CO-09 sur le XML corrigé');
echo "U37 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U38 adresses électroniques BT-34/BT-49 : règles FR seulement pour un vendeur FR';
lfx_reset([], ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001']);
$deSeller = lfx_make_party(['name' => 'MUSTER GMBH', 'country_code' => 'DE', 'zip' => '10115', 'town' => 'Berlin', 'address' => 'Str. 1', 'email' => '', 'tva_intra' => 'DE811111111', 'tva_assuj' => 1]);
$buyerNoMail = lfx_make_party(['name' => 'KUNDE GMBH', 'country_code' => 'DE', 'zip' => '56355', 'town' => 'Nastätten', 'address' => 'Weg 4', 'email' => '']);
$buyerFrNoSiren = lfx_make_party(['name' => 'CLIENT SARL', 'country_code' => 'FR', 'zip' => '75001', 'town' => 'Paris', 'address' => '1 rue', 'email' => 'a@b.fr', 'idprof1' => '', 'idprof2' => '', 'tva_assuj' => 1]);
$inv = new LfxFakeInvoice();
$inv->ref = 'RE2026-0100';
$inv->lines = [lfx_make_line(100.00, 19.0, 19.00)];
$inv->thirdparty = $buyerNoMail;
$warn = auvoerechnung_check_mandatory($inv, $deSeller);
lfx_assert(!lfx_contains($warn, 'AuvoERechnungWarnBuyerEndpoint'), 'vendeur DE : pas de warning BT-49 (BR-FR-12) pour un acheteur sans email');
lfx_assert(!lfx_contains($warn, 'AuvoERechnungWarnSellerEndpoint'), 'vendeur DE : pas de warning BT-34 (BR-FR-13) sans email vendeur');
$inv->thirdparty = $buyerFrNoSiren;
lfx_assert(!lfx_contains(auvoerechnung_check_mandatory($inv, $deSeller), 'AuvoERechnungWarnBuyerSIRENRouting'), 'vendeur DE : pas de warning de routage SIREN pour un acheteur FR');
// Contre-épreuve vendeur FR : les warnings restent.
$inv->thirdparty = $buyerNoMail;
$frNoMail = clone $mysoc;
$frNoMail->email = '';
lfx_assert(lfx_contains(auvoerechnung_check_mandatory($inv, $frNoMail), 'AuvoERechnungWarnBuyerEndpoint'), 'vendeur FR : warning BT-49 conservé');
echo "U38 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U39 XRechnung (CIUS KoSIT) : profil xrechnung';
lfx_reset([
	'AUVOERECHNUNG_CONTACT_NAME' => 'Erika Mustermann',
	'AUVOERECHNUNG_CONTACT_PHONE' => '+49 6772 123456',
	'AUVOERECHNUNG_PAYMENT_MEANS' => '58',
], ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001']);
$xrSeller = lfx_make_party([
	'name' => 'MUSTER GMBH', 'country_code' => 'DE', 'zip' => '56357', 'town' => 'Miehlen',
	'address' => 'Hauptstr. 1', 'email' => 'rechnung@muster.example', 'phone' => '06772 99',
	'tva_intra' => 'DE811111111', 'idprof1' => '22/180/80394', 'idprof2' => 'Amtsgericht Koblenz', 'tva_assuj' => 1,
]);
$xrBuyer = lfx_make_party([
	'name' => 'Bundesamt für Beispiele', 'country_code' => 'DE', 'zip' => '53113', 'town' => 'Bonn',
	'address' => 'Amtsweg 1', 'email' => '', 'tva_intra' => '', 'idprof1' => '', 'idprof2' => '',
	'array_options' => ['options_lfxleitwegid' => '991-33333TEST-33'],
]);
// Choix du format
lfx_assert(auvoerechnung_wants_xrechnung($xrBuyer), 'XRechnung automatique dès qu\'une Leitweg-ID est renseignée');
lfx_assert(!auvoerechnung_wants_xrechnung(lfx_make_party(['country_code' => 'DE'])), 'pas de XRechnung sans Leitweg-ID ni choix explicite');
lfx_assert(auvoerechnung_wants_xrechnung(lfx_make_party(['array_options' => ['options_lfxeformat' => 'xrechnung']])), 'XRechnung forcée sur la fiche tiers');
lfx_assert(!auvoerechnung_wants_xrechnung(lfx_make_party(['array_options' => ['options_lfxeformat' => 'zugferd', 'options_lfxleitwegid' => '991-1-11']])), 'ZUGFeRD forcé malgré une Leitweg-ID');

$inv = new LfxFakeInvoice();
$inv->ref = 'RE2026-0200';
$inv->ref_client = 'BEST-4711';
$inv->thirdparty = $xrBuyer;
$inv->date_lim_reglement = mktime(0, 0, 0, 7, 1, 2026);
$inv->lines = [lfx_make_line(1000.00, 19.0, 190.00, 1.0, 'Beratung', 1)];
$inv->total_ht = 1000.00;
$inv->total_tva = 190.00;
$inv->total_ttc = 1190.00;
lfx_assert(empty(auvoerechnung_check_xrechnung($inv, $xrSeller)), 'contrôle amont XRechnung OK ('.implode(' ; ', auvoerechnung_check_xrechnung($inv, $xrSeller)).')');
$w = [];
$xml = auvoerechnung_build_xml($inv, $xrSeller, $w, ['profile' => 'xrechnung']);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0', lfx_xp_str($xp, '//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID'), 'BT-24 = XRechnung 3.0 (BR-DE-21)');
lfx_assert_eq('urn:fdc:peppol.eu:2017:poacc:billing:01:1.0', lfx_xp_str($xp, '//ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID'), 'BT-23 Peppol billing (PEPPOL-EN16931-R001)');
lfx_assert_eq('991-33333TEST-33', lfx_xp_str($xp, '//ram:BuyerReference'), 'BT-10 = Leitweg-ID');
lfx_assert_eq('991-33333TEST-33', lfx_xp_str($xp, '//ram:BuyerTradeParty/ram:URIUniversalCommunication/ram:URIID[@schemeID="0204"]'), 'BT-49 = Leitweg-ID, schéma 0204');
lfx_assert_eq('BEST-4711', lfx_xp_str($xp, '//ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID'), 'référence client en BT-13 quand BT-10 porte la Leitweg-ID');
lfx_assert_eq('Erika Mustermann', lfx_xp_str($xp, '//ram:SellerTradeParty/ram:DefinedTradeContact/ram:PersonName'), 'BT-41 contact');
lfx_assert_eq('+49 6772 123456', lfx_xp_str($xp, '//ram:SellerTradeParty/ram:DefinedTradeContact/ram:TelephoneUniversalCommunication/ram:CompleteNumber'), 'BT-42 téléphone');
lfx_assert_eq('rechnung@muster.example', lfx_xp_str($xp, '//ram:SellerTradeParty/ram:DefinedTradeContact/ram:EmailURIUniversalCommunication/ram:URIID'), 'BT-43 email (repli société)');
lfx_assert_eq('rechnung@muster.example', lfx_xp_str($xp, '//ram:SellerTradeParty/ram:URIUniversalCommunication/ram:URIID[@schemeID="EM"]'), 'BT-34 email société');
lfx_assert_eq('DE811111111', lfx_xp_str($xp, '//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]'), 'BT-31');
lfx_assert_eq('58', lfx_xp_str($xp, '//ram:SpecifiedTradeSettlementPaymentMeans/ram:TypeCode'), 'BT-81 SEPA-Überweisung');
// Le ZUGFeRD EN16931 n'est pas modifié par le profil XRechnung.
$xpZ = lfx_xpath(auvoerechnung_build_xml($inv, $xrSeller, $w));
lfx_assert_eq('urn:cen.eu:en16931:2017', lfx_xp_str($xpZ, '//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID'), 'profil ZUGFeRD inchangé (BT-24 EN16931)');
lfx_assert_eq(0, $xpZ->query('//ram:DefinedTradeContact')->length, 'pas de contact BG-6 ajouté au ZUGFeRD');
lfx_dump('xr-01-standard-19', $xml);

// Kleinunternehmer (§ 19 UStG), Steuernummer seule (BT-32), facture d'avoir (381)
$ku = lfx_make_party(['name' => 'KLEIN DESIGN', 'country_code' => 'DE', 'zip' => '56357', 'town' => 'Miehlen', 'address' => 'Weg 2',
	'email' => 'info@klein.example', 'tva_intra' => '', 'idprof1' => '22/180/80394', 'idprof2' => '', 'tva_assuj' => 0]);
$inv2 = clone $inv;
$inv2->ref = 'RE2026-0201';
$inv2->lines = [lfx_make_line(500.00, 0.0, 0.00, 1.0, 'Gestaltung', 1)];
$inv2->total_ht = 500.00;
$inv2->total_tva = 0.00;
$inv2->total_ttc = 500.00;
$w = [];
$xml2 = auvoerechnung_build_xml($inv2, $ku, $w, ['profile' => 'xrechnung']);
lfx_std_validate($xml2, $xsdPath);
lfx_assert_eq('22/180/80394', lfx_xp_str(lfx_xpath($xml2), '//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="FC"]'), 'Kleinunternehmer : BT-32 (BR-DE-16)');
lfx_dump('xr-02-kleinunternehmer', $xml2);
lfx_assert_eq('22/180/80394', lfx_xp_str(lfx_xpath($xml2), '//ram:SellerTradeParty/ram:ID'), 'Kleinunternehmer : Steuernummer aussi en BT-29 (BR-CO-26)');
// Registre du commerce renseigné : BT-30 au lieu de BT-29
$kuHrb = clone $ku;
$kuHrb->idprof3 = 'HRB 12345';
$xpH = lfx_xpath(auvoerechnung_build_xml($inv2, $kuHrb, $w, ['profile' => 'xrechnung']));
lfx_assert_eq('HRB 12345', lfx_xp_str($xpH, '//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID'), 'Handelsregister en BT-30');
lfx_assert_eq(0, $xpH->query('//ram:SellerTradeParty/ram:ID')->length, 'pas de BT-29 quand BT-30 est présent');
// La règle interne BR-CO-26 ne se contente plus de la Steuernummer (FC) seule.
$xmlFcOnly = preg_replace('#<ram:ID>22/180/80394</ram:ID>\s*#', '', $xml2, 1);
lfx_assert(lfx_contains(auvoerechnung_validate_business_rules($xmlFcOnly), 'BR-CO-26'), 'BR-CO-26 détectée avec BT-32 seul');

// Client privé/entreprise sans Leitweg-ID, format forcé : BT-10 = ref_client, BT-49 = email
$b2b = lfx_make_party(['name' => 'KUNDE GMBH', 'country_code' => 'DE', 'zip' => '56355', 'town' => 'Nastätten', 'address' => 'Brückwiese 4',
	'email' => 'buchhaltung@kunde.example', 'tva_intra' => 'DE123456789', 'idprof1' => '', 'idprof2' => '',
	'array_options' => ['options_lfxeformat' => 'xrechnung']]);
$inv3 = clone $inv;
$inv3->ref = 'RE2026-0202';
$inv3->thirdparty = $b2b;
$w = [];
$xml3 = auvoerechnung_build_xml($inv3, $xrSeller, $w, ['profile' => 'xrechnung']);
lfx_std_validate($xml3, $xsdPath);
$xp3 = lfx_xpath($xml3);
lfx_assert_eq('BEST-4711', lfx_xp_str($xp3, '//ram:BuyerReference'), 'sans Leitweg-ID : BT-10 = référence client');
lfx_assert_eq('buchhaltung@kunde.example', lfx_xp_str($xp3, '//ram:BuyerTradeParty/ram:URIUniversalCommunication/ram:URIID[@schemeID="EM"]'), 'sans Leitweg-ID : BT-49 = email');
lfx_dump('xr-03-b2b-email', $xml3);

// Données manquantes : contrôle amont et règles BR-DE
lfx_reset(['AUVOERECHNUNG_PAYMENT_MEANS' => '58'], null);
$bare = lfx_make_party(['name' => 'OHNE DATEN', 'country_code' => 'DE', 'zip' => '', 'town' => '', 'email' => '', 'phone' => '', 'tva_intra' => 'DE811111111', 'idprof1' => '', 'idprof2' => '']);
$inv4 = clone $inv;
$inv4->ref_client = '';
$inv4->thirdparty = lfx_make_party(['name' => 'X', 'country_code' => 'DE', 'zip' => '', 'town' => '', 'email' => '']);
$probs = auvoerechnung_check_xrechnung($inv4, $bare);
foreach (['AuvoERechnungXrMissingBuyerRef', 'AuvoERechnungXrMissingContactName', 'AuvoERechnungXrMissingContactPhone', 'AuvoERechnungXrMissingContactEmail', 'AuvoERechnungXrMissingSellerEmail', 'AuvoERechnungXrMissingBuyerAddress', 'AuvoERechnungXrMissingSellerZipTown', 'AuvoERechnungXrMissingBuyerZipTown', 'AuvoERechnungXrMissingIban'] as $k) {
	lfx_assert(lfx_contains($probs, $k), 'contrôle amont : '.$k);
}
$w = [];
$v = auvoerechnung_validate_business_rules(auvoerechnung_build_xml($inv4, $bare, $w, ['profile' => 'xrechnung']));
foreach (['BR-DE-15', 'PEPPOL-EN16931-R020', 'PEPPOL-EN16931-R010', 'BR-DE-2', 'BR-DE-1'] as $rule) {
	lfx_assert(lfx_contains($v, $rule), 'règle '.$rule.' détectée');
}
// Les règles BR-DE ne s'appliquent pas à un ZUGFeRD EN16931.
lfx_assert(!lfx_contains(auvoerechnung_validate_business_rules(auvoerechnung_build_xml($inv4, $bare, $w)), 'BR-DE-'), 'pas de BR-DE sur un ZUGFeRD EN16931');
echo "U39 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U40 Lieferantennummer (BT-29) et chemin du fichier XRechnung';
lfx_reset([
	'AUVOERECHNUNG_CONTACT_NAME' => 'Erika Mustermann',
	'AUVOERECHNUNG_CONTACT_PHONE' => '+49 6772 123456',
	'AUVOERECHNUNG_PAYMENT_MEANS' => '58',
], ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001']);
$kuSeller = lfx_make_party(['name' => 'KLEIN DESIGN', 'country_code' => 'DE', 'zip' => '56357', 'town' => 'Miehlen', 'address' => 'Weg 2',
	'email' => 'info@klein.example', 'tva_intra' => '', 'idprof1' => '22/180/80394', 'idprof2' => '', 'tva_assuj' => 0]);
$amt = lfx_make_party(['name' => 'Bundesamt für Beispiele', 'country_code' => 'DE', 'zip' => '53113', 'town' => 'Bonn', 'address' => 'Amtsweg 1',
	'email' => '', 'tva_intra' => '', 'idprof1' => '', 'idprof2' => '',
	'array_options' => ['options_lfxleitwegid' => '991-33333TEST-33', 'options_lfxsupplierno' => ' K-100234 ']]);
lfx_assert_eq('K-100234', auvoerechnung_party_supplier_number($amt), 'Lieferantennummer lue sur la fiche client (espaces retirés)');
$inv = new LfxFakeInvoice();
$inv->ref = 'RE2026-0300';
$inv->thirdparty = $amt;
$inv->lines = [lfx_make_line(400.00, 0.0, 0.00, 1.0, 'Gestaltung', 1)];
$inv->total_ht = 400.00;
$inv->total_tva = 0.00;
$inv->total_ttc = 400.00;
foreach (['xrechnung' => ['profile' => 'xrechnung'], 'zugferd' => []] as $label => $opt) {
	$w = [];
	$xml = auvoerechnung_build_xml($inv, $kuSeller, $w, $opt);
	lfx_std_validate($xml, $xsdPath);
	$xp = lfx_xpath($xml);
	lfx_assert_eq(1, $xp->query('//ram:SellerTradeParty/ram:ID')->length, $label.' : un seul BT-29');
	lfx_assert_eq('K-100234', lfx_xp_str($xp, '//ram:SellerTradeParty/ram:ID'), $label.' : BT-29 = Lieferantennummer (remplace le repli Steuernummer)');
	lfx_assert_eq('22/180/80394', lfx_xp_str($xp, '//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="FC"]'), $label.' : Steuernummer toujours en BT-32');
	lfx_dump('xr-04-lieferantennummer-'.$label, $xml);
}
// Sans Lieferantennummer : repli Steuernummer en BT-29 (BR-CO-26) inchangé.
$amt2 = clone $amt;
$amt2->array_options = ['options_lfxleitwegid' => '991-33333TEST-33'];
$inv->thirdparty = $amt2;
lfx_assert_eq('22/180/80394', lfx_xp_str(lfx_xpath(auvoerechnung_build_xml($inv, $kuSeller, $w, ['profile' => 'xrechnung'])), '//ram:SellerTradeParty/ram:ID'), 'sans Lieferantennummer : repli Steuernummer');
// Chemin du fichier : nom fixe dans le dossier de la facture
$GLOBALS['conf'] = $GLOBALS['conf'] ?? new stdClass();
$GLOBALS['conf']->facture = (object) ['multidir_output' => [1 => '/data/facture'], 'dir_output' => '/data/facture'];
lfx_assert_eq('/data/facture/RE2026-0300/RE2026-0300-XRECHNUNG.xml', auvoerechnung_xrechnung_path('RE2026-0300', 1), 'chemin {ref}/{ref}-XRECHNUNG.xml');
echo "U40 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U41 Skonto (BT-20, format KoSIT BR-DE-18)';
lfx_reset([
	'AUVOERECHNUNG_CONTACT_NAME' => 'Erika Mustermann',
	'AUVOERECHNUNG_CONTACT_PHONE' => '+49 6772 123456',
	'AUVOERECHNUNG_PAYMENT_MEANS' => '58',
	'AUVOERECHNUNG_SKONTO' => json_encode([
		'7D' => [['days' => 7, 'percent' => 2]],
		'SK2' => [['days' => 14, 'percent' => 2], ['days' => 7, 'percent' => 3.5], ['days' => 0, 'percent' => 5], ['days' => 10, 'percent' => 150]],
	]),
], ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001']);
lfx_assert_eq([['days' => 7, 'percent' => 3.5], ['days' => 14, 'percent' => 2.0]], auvoerechnung_get_skonto_terms('SK2'), 'paliers triés, entrées invalides ignorées');
lfx_assert_eq([], auvoerechnung_get_skonto_terms('30D'), 'pas de Skonto sans configuration');
lfx_assert_eq("#SKONTO#TAGE=7#PROZENT=3.50#\n#SKONTO#TAGE=14#PROZENT=2.00#\n", auvoerechnung_skonto_lines(auvoerechnung_get_skonto_terms('SK2')), 'syntaxe KoSIT, saut de ligne final');
lfx_assert_eq(['days' => 7, 'percent' => 2.0], auvoerechnung_guess_skonto('7 Tage 2% Skonto 14 Tage netto'), 'suggestion « 7 Tage 2% Skonto »');
lfx_assert_eq(['days' => 10, 'percent' => 2.5], auvoerechnung_guess_skonto('2,5 % Skonto bei Zahlung innerhalb von 10 Tagen'), 'suggestion « 2,5 % Skonto … 10 Tagen »');
lfx_assert_eq(null, auvoerechnung_guess_skonto('Zahlbar innerhalb 30 Tagen'), 'pas de suggestion sans Skonto');

$seller = lfx_make_party([
	'name' => 'MUSTER GMBH', 'country_code' => 'DE', 'zip' => '56357', 'town' => 'Miehlen',
	'address' => 'Hauptstr. 1', 'email' => 'rechnung@muster.example', 'phone' => '06772 99',
	'tva_intra' => 'DE811111111', 'idprof1' => '22/180/80394', 'tva_assuj' => 1,
]);
$inv = new LfxFakeInvoice();
$inv->ref = 'RE2026-0400';
$inv->ref_client = 'BEST-4712';
$inv->cond_reglement_code = '7D';
$inv->cond_reglement_doc = "Zahlbar innerhalb 14 Tagen.\n#Bei Zahlung innerhalb 7 Tagen 2 % Skonto.";
$inv->thirdparty = lfx_make_party(['name' => 'Bundesamt für Beispiele', 'country_code' => 'DE', 'zip' => '53113', 'town' => 'Bonn',
	'address' => 'Amtsweg 1', 'email' => '', 'tva_intra' => '', 'idprof1' => '',
	'array_options' => ['options_lfxleitwegid' => '991-33333TEST-33']]);
$inv->date_lim_reglement = mktime(0, 0, 0, 6, 15, 2026);
$inv->lines = [lfx_make_line(1000.00, 19.0, 190.00, 1.0, 'Beratung', 1)];
$inv->total_ht = 1000.00;
$inv->total_tva = 190.00;
$inv->total_ttc = 1190.00;
foreach (['xrechnung' => ['profile' => 'xrechnung'], 'zugferd' => []] as $label => $opt) {
	$w = [];
	$xml = auvoerechnung_build_xml($inv, $seller, $w, $opt);
	lfx_std_validate($xml, $xsdPath);
	lfx_assert_eq("Zahlbar innerhalb 14 Tagen. #Bei Zahlung innerhalb 7 Tagen 2 % Skonto.\n#SKONTO#TAGE=7#PROZENT=2.00#\n",
		lfx_xpath($xml)->query('//ram:SpecifiedTradePaymentTerms/ram:Description')->item(0)->textContent ?? null,
		$label.' : BT-20 = texte de la condition + ligne Skonto');
	lfx_dump('xr-05-skonto-'.$label, $xml);
}
// Deux paliers
$inv2 = clone $inv;
$inv2->cond_reglement_code = 'SK2';
$inv2->cond_reglement_doc = '';
$w = [];
$xml2 = auvoerechnung_build_xml($inv2, $seller, $w, ['profile' => 'xrechnung']);
lfx_std_validate($xml2, $xsdPath);
lfx_assert_eq("#SKONTO#TAGE=7#PROZENT=3.50#\n#SKONTO#TAGE=14#PROZENT=2.00#\n", lfx_xpath($xml2)->query('//ram:SpecifiedTradePaymentTerms/ram:Description')->item(0)->textContent ?? null, 'deux paliers sans texte');
lfx_dump('xr-06-skonto-2-stufen', $xml2);
// Sans Skonto configuré : pas de BT-20 (comportement antérieur inchangé)
$inv3 = clone $inv;
$inv3->cond_reglement_code = '30D';
lfx_assert_eq(0, lfx_xpath(auvoerechnung_build_xml($inv3, $seller, $w, ['profile' => 'xrechnung']))->query('//ram:SpecifiedTradePaymentTerms/ram:Description')->length, 'pas de BT-20 sans Skonto');
// Avoir : pas d'escompte
lfx_assert_eq('', auvoerechnung_build_payment_terms_description($inv, true), 'pas de Skonto sur un avoir');
// Règle interne BR-DE-18 : syntaxe et saut de ligne final
$bad = str_replace('#SKONTO#TAGE=7#PROZENT=3.50#', '#SKONTO#TAGE=7#PROZENT=3.5#', $xml2);
lfx_assert(lfx_contains(auvoerechnung_validate_business_rules($bad), 'BR-DE-18'), 'BR-DE-18 : pourcentage sans deux décimales');
$noNl = str_replace("#SKONTO#TAGE=14#PROZENT=2.00#\n</ram:Description>", '#SKONTO#TAGE=14#PROZENT=2.00#</ram:Description>', $xml2);
lfx_assert($noNl !== $xml2 && lfx_contains(auvoerechnung_validate_business_rules($noNl), 'BR-DE-18'), 'BR-DE-18 : saut de ligne final manquant');
echo "U41 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U42 acompte 326, Leitweg-ID facture, pièces jointes BG-24, facture de test';
lfx_reset([
	'AUVOERECHNUNG_CONTACT_NAME' => 'Erika Mustermann',
	'AUVOERECHNUNG_CONTACT_PHONE' => '+49 6772 123456',
	'AUVOERECHNUNG_PAYMENT_MEANS' => '58',
], ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001']);
$seller = lfx_make_party([
	'name' => 'MUSTER GMBH', 'country_code' => 'DE', 'zip' => '56357', 'town' => 'Miehlen',
	'address' => 'Hauptstr. 1', 'email' => 'rechnung@muster.example', 'phone' => '06772 99',
	'tva_intra' => 'DE811111111', 'idprof1' => '22/180/80394', 'tva_assuj' => 1,
]);
$amt = lfx_make_party(['name' => 'Bundesamt für Beispiele', 'country_code' => 'DE', 'zip' => '53113', 'town' => 'Bonn',
	'address' => 'Amtsweg 1', 'email' => '', 'tva_intra' => '', 'idprof1' => '',
	'array_options' => ['options_lfxleitwegid' => '991-33333TEST-33']]);
$inv = new LfxFakeInvoice();
$inv->ref = 'RE2026-0500';
$inv->ref_client = 'BEST-1';
$inv->thirdparty = $amt;
$inv->lines = [lfx_make_line(1000.00, 19.0, 190.00, 1.0, 'Abschlag Planung', 1)];
$inv->total_ht = 1000.00;
$inv->total_tva = 190.00;
$inv->total_ttc = 1190.00;

// Facture d'acompte : 326 en XRechnung (BR-DE-17), 386 en ZUGFeRD
$dep = clone $inv;
$dep->type = 3;
$w = [];
$xmlDep = auvoerechnung_build_xml($dep, $seller, $w, ['profile' => 'xrechnung']);
lfx_std_validate($xmlDep, $xsdPath);
lfx_assert_eq('326', lfx_xp_str(lfx_xpath($xmlDep), '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode'), 'acompte XRechnung : 326');
lfx_assert_eq('386', lfx_xp_str(lfx_xpath(auvoerechnung_build_xml($dep, $seller, $w)), '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode'), 'acompte ZUGFeRD : 386 inchangé');
lfx_dump('xr-07-abschlag-326', $xmlDep);

// Leitweg-ID propre à la facture
$inv2 = clone $inv;
$inv2->array_options = ['options_lfxleitwegid' => '991-12345-67'];
lfx_assert_eq('991-12345-67', auvoerechnung_invoice_leitwegid($inv2), 'Leitweg-ID de la facture prioritaire');
lfx_assert_eq('991-33333TEST-33', auvoerechnung_invoice_leitwegid($inv), 'repli sur la Leitweg-ID du tiers');
$xp2 = lfx_xpath(auvoerechnung_build_xml($inv2, $seller, $w, ['profile' => 'xrechnung']));
lfx_assert_eq('991-12345-67', lfx_xp_str($xp2, '//ram:BuyerReference'), 'BT-10 = Leitweg-ID de la facture');
lfx_assert_eq('991-12345-67', lfx_xp_str($xp2, '//ram:BuyerTradeParty/ram:URIUniversalCommunication/ram:URIID[@schemeID="0204"]'), 'BT-49 = Leitweg-ID de la facture');
$plain = lfx_make_party(['country_code' => 'DE']);
$inv3 = clone $inv;
$inv3->thirdparty = $plain;
$inv3->array_options = ['options_lfxleitwegid' => '991-12345-67'];
lfx_assert(auvoerechnung_wants_xrechnung($plain, $inv3), 'XRechnung dès qu\'une Leitweg-ID est saisie sur la facture');
lfx_assert(!auvoerechnung_wants_xrechnung($plain, $inv), 'pas de XRechnung sans Leitweg-ID');

// Pièces jointes BG-24
$tmpDir = sys_get_temp_dir().'/lfx-att-'.getmypid();
@mkdir($tmpDir.'/RE2026-0500', 0777, true);
$GLOBALS['conf'] = $GLOBALS['conf'] ?? new stdClass();
$GLOBALS['conf']->facture = (object) ['multidir_output' => [1 => $tmpDir], 'dir_output' => $tmpDir];
$pdfBytes = "%PDF-1.4\n% test\n%%EOF\n";
file_put_contents($tmpDir.'/RE2026-0500/RE2026-0500.pdf', $pdfBytes);
file_put_contents($tmpDir.'/RE2026-0500/RE2026-0500-XRECHNUNG.xml', '<x/>');
file_put_contents($tmpDir.'/RE2026-0500/Stundennachweis.csv', "Datum;Stunden\n01.06.2026;8\n");
file_put_contents($tmpDir.'/RE2026-0500/notiz.txt', 'intern');
$inv->entity = 1;
$aw = [];
lfx_assert_eq([], auvoerechnung_collect_attachments($inv, $aw), 'aucune pièce jointe par défaut');
lfx_reset([
	'AUVOERECHNUNG_CONTACT_NAME' => 'Erika Mustermann',
	'AUVOERECHNUNG_CONTACT_PHONE' => '+49 6772 123456',
	'AUVOERECHNUNG_PAYMENT_MEANS' => '58',
	'AUVOERECHNUNG_XR_EMBED_PDF' => 1,
	'AUVOERECHNUNG_XR_EMBED_FILES' => 1,
], ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001']);
$aw = [];
$atts = auvoerechnung_collect_attachments($inv, $aw);
lfx_assert_eq(['RE2026-0500.pdf', 'Stundennachweis.csv'], array_column($atts, 'name'), 'PDF de la facture + justificatif, sans XRechnung ni format refusé');
lfx_assert(lfx_contains($aw, 'notiz.txt') || lfx_contains($aw, 'AuvoERechnungWarnAttachType'), 'avertissement pour le format refusé');
$w = [];
$xmlAtt = auvoerechnung_build_xml($inv, $seller, $w, ['profile' => 'xrechnung', 'attachments' => $atts]);
lfx_std_validate($xmlAtt, $xsdPath);
$xpA = lfx_xpath($xmlAtt);
lfx_assert_eq(2, $xpA->query('//ram:ApplicableHeaderTradeAgreement/ram:AdditionalReferencedDocument')->length, 'deux BG-24');
lfx_assert_eq($pdfBytes, base64_decode(lfx_xp_str($xpA, '//ram:AttachmentBinaryObject[@filename="RE2026-0500.pdf"]')), 'contenu du PDF intact');
lfx_assert_eq('application/pdf', $xpA->query('//ram:AttachmentBinaryObject')->item(0)->getAttribute('mimeCode'), 'mimeCode PDF');
lfx_assert_eq('916', lfx_xp_str($xpA, '//ram:AdditionalReferencedDocument/ram:TypeCode'), 'TypeCode 916');
lfx_dump('xr-08-anlagen', $xmlAtt);
array_map('unlink', glob($tmpDir.'/RE2026-0500/*'));
@rmdir($tmpDir.'/RE2026-0500');
@rmdir($tmpDir);

// Facture de test de la page de configuration
$sampleInv = new LfxFakeInvoice();
$sampleBuyer = new stdClass();
auvoerechnung_sample_invoice($sampleInv, $sampleBuyer, $seller);
foreach (['zugferd' => [], 'xrechnung' => ['profile' => 'xrechnung']] as $label => $opt) {
	$w = [];
	$xmlS = auvoerechnung_build_xml($sampleInv, $seller, $w, $opt);
	lfx_std_validate($xmlS, $xsdPath);
	lfx_dump('xr-09-testrechnung-'.$label, $xmlS);
}
lfx_assert(empty(auvoerechnung_check_xrechnung($sampleInv, $seller)), 'facture de test complète pour XRechnung');
$ku = clone $seller;
$ku->tva_assuj = 0;
$ku->tva_intra = '';
$sampleKu = new LfxFakeInvoice();
auvoerechnung_sample_invoice($sampleKu, new stdClass(), $ku);
lfx_assert_eq(0.0, (float) $sampleKu->total_tva, 'Kleinunternehmer : facture de test sans TVA');
lfx_std_validate(auvoerechnung_build_xml($sampleKu, $ku, $w, ['profile' => 'xrechnung']), $xsdPath);
echo "U42 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U43 modèle sémantique EN16931 (indépendant de la syntaxe)';
lfx_reset([
	'AUVOERECHNUNG_CONTACT_NAME' => 'Erika Mustermann',
	'AUVOERECHNUNG_CONTACT_PHONE' => '+49 6772 123456',
	'AUVOERECHNUNG_PAYMENT_MEANS' => '58',
], ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001']);
$seller = lfx_make_party([
	'name' => 'MUSTER GMBH', 'country_code' => 'DE', 'zip' => '56357', 'town' => 'Miehlen',
	'address' => 'Hauptstr. 1', 'email' => 'rechnung@muster.example', 'phone' => '06772 99',
	'tva_intra' => 'DE811111111', 'idprof1' => '22/180/80394', 'tva_assuj' => 1,
]);
$inv = new LfxFakeInvoice();
$inv->ref = 'RE2026-0600';
$inv->ref_client = 'BEST-9';
$inv->thirdparty = lfx_make_party(['name' => 'Bundesamt für Beispiele', 'country_code' => 'DE', 'zip' => '53113', 'town' => 'Bonn',
	'address' => 'Amtsweg 1', 'email' => '', 'tva_intra' => '', 'idprof1' => '',
	'array_options' => ['options_lfxleitwegid' => '991-33333TEST-33']]);
$inv->lines = [
	lfx_make_line(1000.00, 19.0, 190.00, 8.0, 'Beratung', 1),
	lfx_make_line(-100.00, 19.0, -19.00, 1.0, 'Nachlass', 1),
];
$inv->total_ht = 900.00;
$inv->total_tva = 171.00;
$inv->total_ttc = 1071.00;
$w = [];
$m = auvoerechnung_build_model($inv, $seller, $w, ['profile' => 'xrechnung']);
lfx_assert_eq('RE2026-0600', $m['document']['id'], 'BT-1');
lfx_assert_eq('380', $m['document']['type_code'], 'BT-3');
lfx_assert_eq('991-33333TEST-33', $m['document']['buyer_reference'], 'BT-10 Leitweg-ID');
lfx_assert_eq('BEST-9', $m['document']['order_reference'], 'BT-13');
lfx_assert_eq(['scheme' => '0204', 'id' => '991-33333TEST-33'], $m['buyer']['endpoint'], 'BT-49');
lfx_assert_eq('DE811111111', $m['seller']['vat_id'], 'BT-31');
lfx_assert_eq('Erika Mustermann', $m['seller']['contact']['name'] ?? null, 'BG-6');
lfx_assert_eq(1, count($m['lines']), 'une ligne, la remise passe en BG-20');
lfx_assert_eq(125.0, $m['lines'][0]['net_price'], 'BT-146');
lfx_assert_eq(100.0, $m['allowances'][0]['amount'], 'BT-92');
lfx_assert_eq(['line_total' => 1000.0, 'allowance_total' => 100.0, 'tax_basis' => 900.0, 'tax_total' => 171.0, 'grand_total' => 1071.0, 'prepaid' => 0.0, 'due_payable' => 1071.0], $m['totals'], 'BG-22');
lfx_assert_eq('S', $m['vat_breakdown'][0]['category'], 'BG-23 catégorie');
lfx_assert_eq('58', $m['payment']['means_code'], 'BT-81');
// La sérialisation CII est une fonction pure du modèle
lfx_assert_eq(auvoerechnung_build_xml($inv, $seller, $w, ['profile' => 'xrechnung']), auvoerechnung_model_to_cii($m), 'build_xml = model_to_cii(build_model)');
echo "U43 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U44 XRechnung UBL 2.1 (Invoice et CreditNote)';
function lfx_ubl_xpath($xml)
{
	$dom = new DOMDocument();
	$dom->loadXML($xml);
	$xp = new DOMXPath($dom);
	$xp->registerNamespace('inv', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
	$xp->registerNamespace('cn', 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2');
	$xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
	$xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
	return $xp;
}
function lfx_ubl_check($res, $label)
{
	global $xsdPath;
	lfx_assert_eq('ubl', $res['syntax'], $label.' : syntaxe UBL');
	lfx_assert(auvoerechnung_validate_xsd($res['xml'], dirname(__DIR__)) === null, $label.' : XSD UBL 2.1 ('.auvoerechnung_validate_xsd($res['xml'], dirname(__DIR__)).')');
	// Règles métier sur la version CII du même modèle (même contenu)
	lfx_std_validate($res['cii'], $xsdPath);
	lfx_dump('ubl-'.$label, $res['xml']);
	return lfx_ubl_xpath($res['xml']);
}
lfx_reset([
	'AUVOERECHNUNG_CONTACT_NAME' => 'Erika Mustermann',
	'AUVOERECHNUNG_CONTACT_PHONE' => '+49 6772 123456',
	'AUVOERECHNUNG_PAYMENT_MEANS' => '58',
	'AUVOERECHNUNG_SKONTO' => json_encode(['7D' => [['days' => 7, 'percent' => 2]]]),
], ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001']);
$seller = lfx_make_party([
	'name' => 'MUSTER GMBH', 'country_code' => 'DE', 'zip' => '56357', 'town' => 'Miehlen',
	'address' => 'Hauptstr. 1', 'email' => 'rechnung@muster.example', 'phone' => '06772 99',
	'tva_intra' => 'DE811111111', 'idprof1' => '22/180/80394', 'idprof3' => 'HRB 12345', 'tva_assuj' => 1,
]);
$amt = lfx_make_party(['name' => 'Bundesamt für Beispiele', 'country_code' => 'DE', 'zip' => '53113', 'town' => 'Bonn',
	'address' => 'Amtsweg 1', 'email' => '', 'tva_intra' => '', 'idprof1' => '',
	'array_options' => ['options_lfxleitwegid' => '991-33333TEST-33', 'options_lfxsupplierno' => 'K-100234']]);

// Choix de la syntaxe
lfx_assert_eq('cii', auvoerechnung_xrechnung_syntax($amt), 'CII par défaut');
lfx_assert_eq('ubl', auvoerechnung_xrechnung_syntax(lfx_make_party(['array_options' => ['options_lfxeformat' => 'xrechnung_ubl']])), 'UBL choisi sur le tiers');
lfx_assert(auvoerechnung_wants_xrechnung(lfx_make_party(['array_options' => ['options_lfxeformat' => 'xrechnung_ubl']])), 'xrechnung_ubl = XRechnung voulue');
$GLOBALS['lfx_test_conf']['AUVOERECHNUNG_XRECHNUNG_SYNTAX'] = 'ubl';
lfx_assert_eq('ubl', auvoerechnung_xrechnung_syntax($amt), 'UBL par réglage du module');
lfx_assert_eq('cii', auvoerechnung_xrechnung_syntax(lfx_make_party(['array_options' => ['options_lfxeformat' => 'xrechnung_cii']])), 'CII choisi sur le tiers malgré le réglage');

// Facture standard : remise, Skonto, deux taux
$inv = new LfxFakeInvoice();
$inv->ref = 'RE2026-0700';
$inv->ref_client = 'BEST-4711';
$inv->cond_reglement_code = '7D';
$inv->cond_reglement_doc = 'Zahlbar innerhalb 14 Tagen.';
$inv->thirdparty = $amt;
$inv->date_lim_reglement = mktime(0, 0, 0, 6, 15, 2026);
$inv->lines = [
	lfx_make_line(760.00, 19.0, 144.40, 8.0, 'Beratung', 1, ['date_start' => mktime(0, 0, 0, 5, 1, 2026), 'date_end' => mktime(0, 0, 0, 5, 31, 2026)]),
	lfx_make_line(120.00, 7.0, 8.40, 1.0, 'Fachbuch', 0),
	lfx_make_line(-60.00, 19.0, -11.40, 1.0, 'Nachlass', 1),
];
$inv->total_ht = 820.00;
$inv->total_tva = 141.40;
$inv->total_ttc = 961.40;
$w = [];
$res = auvoerechnung_build_xrechnung($inv, $seller, $w);
$xp = lfx_ubl_check($res, 'standard');
lfx_assert_eq(auvoerechnung_build_xml($inv, $seller, $w, ['profile' => 'xrechnung']), $res['cii'], 'CII de build_xrechnung = build_xml(xrechnung)');
lfx_assert_eq('urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0', lfx_xp_str($xp, '/inv:Invoice/cbc:CustomizationID'), 'BT-24');
lfx_assert_eq('urn:fdc:peppol.eu:2017:poacc:billing:01:1.0', lfx_xp_str($xp, '/inv:Invoice/cbc:ProfileID'), 'BT-23');
lfx_assert_eq('2026-06-01', lfx_xp_str($xp, '/inv:Invoice/cbc:IssueDate'), 'BT-2 au format AAAA-MM-JJ');
lfx_assert_eq('2026-06-15', lfx_xp_str($xp, '/inv:Invoice/cbc:DueDate'), 'BT-9');
lfx_assert_eq('380', lfx_xp_str($xp, '/inv:Invoice/cbc:InvoiceTypeCode'), 'BT-3');
lfx_assert_eq('991-33333TEST-33', lfx_xp_str($xp, '/inv:Invoice/cbc:BuyerReference'), 'BT-10');
lfx_assert_eq('BEST-4711', lfx_xp_str($xp, '/inv:Invoice/cac:OrderReference/cbc:ID'), 'BT-13');
lfx_assert_eq('2026-05-01', lfx_xp_str($xp, '/inv:Invoice/cac:InvoicePeriod/cbc:StartDate'), 'BT-73');
lfx_assert_eq('991-33333TEST-33', lfx_xp_str($xp, '//cac:AccountingCustomerParty//cbc:EndpointID[@schemeID="0204"]'), 'BT-49');
lfx_assert_eq('rechnung@muster.example', lfx_xp_str($xp, '//cac:AccountingSupplierParty//cbc:EndpointID[@schemeID="EM"]'), 'BT-34');
lfx_assert_eq('K-100234', lfx_xp_str($xp, '//cac:AccountingSupplierParty//cac:PartyIdentification/cbc:ID'), 'BT-29');
lfx_assert_eq('DE811111111', lfx_xp_str($xp, '//cac:AccountingSupplierParty//cac:PartyTaxScheme[cac:TaxScheme/cbc:ID="VAT"]/cbc:CompanyID'), 'BT-31');
lfx_assert_eq('HRB 12345', lfx_xp_str($xp, '//cac:AccountingSupplierParty//cac:PartyLegalEntity/cbc:CompanyID'), 'BT-30');
lfx_assert_eq('MUSTER GMBH', lfx_xp_str($xp, '//cac:AccountingSupplierParty//cac:PartyLegalEntity/cbc:RegistrationName'), 'BT-27');
lfx_assert_eq('Erika Mustermann', lfx_xp_str($xp, '//cac:AccountingSupplierParty//cac:Contact/cbc:Name'), 'BT-41');
lfx_assert_eq('DE02120300000000202051', lfx_xp_str($xp, '//cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID'), 'BT-84');
lfx_assert_eq("Zahlbar innerhalb 14 Tagen.\n#SKONTO#TAGE=7#PROZENT=2.00#\n", $xp->query('//cac:PaymentTerms/cbc:Note')->item(0)->textContent ?? null, 'BT-20 Skonto');
lfx_assert_eq('60.00', lfx_xp_str($xp, '/inv:Invoice/cac:AllowanceCharge/cbc:Amount'), 'BT-92');
lfx_assert_eq(2, $xp->query('//cac:TaxTotal/cac:TaxSubtotal')->length, 'deux taux');
lfx_assert_eq('141.40', lfx_xp_str($xp, '//cac:TaxTotal/cbc:TaxAmount'), 'BT-110');
lfx_assert_eq('961.40', lfx_xp_str($xp, '//cac:LegalMonetaryTotal/cbc:PayableAmount'), 'BT-115');
lfx_assert_eq('EUR', $xp->query('//cac:LegalMonetaryTotal/cbc:PayableAmount')->item(0)->getAttribute('currencyID'), 'currencyID');
lfx_assert_eq(2, $xp->query('/inv:Invoice/cac:InvoiceLine')->length, 'deux lignes (remise en BG-20)');
lfx_assert_eq('95.00', lfx_xp_str($xp, '/inv:Invoice/cac:InvoiceLine[1]/cac:Price/cbc:PriceAmount'), 'BT-146');

// Avoir : CreditNote, BT-9 dans PaymentMeans, BG-3
lfx_reset([
	'AUVOERECHNUNG_CONTACT_NAME' => 'Erika Mustermann',
	'AUVOERECHNUNG_CONTACT_PHONE' => '+49 6772 123456',
	'AUVOERECHNUNG_PAYMENT_MEANS' => '58',
	'AUVOERECHNUNG_XRECHNUNG_SYNTAX' => 'ubl',
], ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001'], [
	'FROM '.MAIN_DB_PREFIX.'facture WHERE rowid = 77' => [(object) ['ref' => 'RE2026-0700', 'datef' => '2026-06-01']],
]);
$cn = new LfxFakeInvoice();
$cn->ref = 'GS2026-0001';
$cn->type = 2;
$cn->fk_facture_source = 77;
$cn->thirdparty = $amt;
$cn->date_lim_reglement = mktime(0, 0, 0, 6, 30, 2026);
$cn->lines = [lfx_make_line(-100.00, 19.0, -19.00, 1.0, 'Gutschrift Beratung', 1)];
$cn->total_ht = -100.00;
$cn->total_tva = -19.00;
$cn->total_ttc = -119.00;
$res = auvoerechnung_build_xrechnung($cn, $seller, $w);
$xp = lfx_ubl_check($res, 'gutschrift');
lfx_assert_eq('381', lfx_xp_str($xp, '/cn:CreditNote/cbc:CreditNoteTypeCode'), 'CreditNote 381');
lfx_assert_eq('1.0000', lfx_xp_str($xp, '/cn:CreditNote/cac:CreditNoteLine/cbc:CreditedQuantity'), 'CreditedQuantity positive');
lfx_assert_eq('119.00', lfx_xp_str($xp, '//cac:LegalMonetaryTotal/cbc:PayableAmount'), 'montants positifs');
lfx_assert_eq('2026-06-30', lfx_xp_str($xp, '//cac:PaymentMeans/cbc:PaymentDueDate'), 'BT-9 de l\'avoir');
lfx_assert_eq('RE2026-0700', lfx_xp_str($xp, '//cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID'), 'BG-3');

// Acompte (326) d'un Kleinunternehmer : catégorie E, Steuernummer (FC), pièce jointe
$ku = lfx_make_party(['name' => 'KLEIN DESIGN', 'country_code' => 'DE', 'zip' => '56357', 'town' => 'Miehlen', 'address' => 'Weg 2',
	'email' => 'info@klein.example', 'tva_intra' => '', 'idprof1' => '22/180/80394', 'idprof2' => '', 'tva_assuj' => 0]);
$dep = new LfxFakeInvoice();
$dep->ref = 'RE2026-0701';
$dep->type = 3;
$dep->thirdparty = $amt;
$dep->lines = [lfx_make_line(500.00, 0.0, 0.00, 1.0, 'Abschlag Gestaltung', 1)];
$dep->total_ht = 500.00;
$dep->total_ttc = 500.00;
$res = auvoerechnung_build_xrechnung($dep, $ku, $w, ['attachments' => [['id' => 'RE2026-0701-A1', 'name' => 'Stundennachweis.csv', 'mime' => 'text/csv', 'content' => "Datum;Stunden\n", 'description' => 'Stunden']]]);
$xp = lfx_ubl_check($res, 'abschlag-kleinunternehmer');
lfx_assert_eq('326', lfx_xp_str($xp, '/inv:Invoice/cbc:InvoiceTypeCode'), 'acompte 326');
lfx_assert_eq('E', lfx_xp_str($xp, '//cac:TaxSubtotal/cac:TaxCategory/cbc:ID'), 'catégorie E');
lfx_assert(lfx_xp_str($xp, '//cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReason') !== null, 'BT-120');
lfx_assert_eq('22/180/80394', lfx_xp_str($xp, '//cac:AccountingSupplierParty//cac:PartyTaxScheme[cac:TaxScheme/cbc:ID="FC"]/cbc:CompanyID'), 'BT-32 Steuernummer');
lfx_assert_eq("Datum;Stunden\n", base64_decode(lfx_xp_str($xp, '//cac:AdditionalDocumentReference/cac:Attachment/cbc:EmbeddedDocumentBinaryObject')), 'BG-24');
echo "U44 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U45 période sur la facture, lieu de livraison BG-13, facture sans e-facture';
lfx_reset([
	'AUVOERECHNUNG_CONTACT_NAME' => 'Erika Mustermann',
	'AUVOERECHNUNG_CONTACT_PHONE' => '+49 6772 123456',
	'AUVOERECHNUNG_PAYMENT_MEANS' => '58',
], ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001']);
$seller = lfx_make_party([
	'name' => 'MUSTER GMBH', 'country_code' => 'DE', 'zip' => '56357', 'town' => 'Miehlen',
	'address' => 'Hauptstr. 1', 'email' => 'rechnung@muster.example', 'phone' => '06772 99',
	'tva_intra' => 'DE811111111', 'tva_assuj' => 1,
]);
$inv = new LfxFakeInvoice();
$inv->ref = 'RE2026-0800';
$inv->thirdparty = lfx_make_party(['name' => 'Bundesamt für Beispiele', 'country_code' => 'DE', 'zip' => '53113', 'town' => 'Bonn',
	'address' => 'Amtsweg 1', 'email' => '', 'tva_intra' => '', 'array_options' => ['options_lfxleitwegid' => '991-33333TEST-33']]);
$inv->lines = [lfx_make_line(1000.00, 19.0, 190.00, 1.0, 'Wartung', 1, ['date_start' => mktime(0, 0, 0, 5, 3, 2026), 'date_end' => mktime(0, 0, 0, 5, 9, 2026)])];
$inv->total_ht = 1000.00;
$inv->total_tva = 190.00;
$inv->total_ttc = 1190.00;

// Période de la facture : remplace celle des lignes
$inv->array_options = ['options_lfxperiodstart' => mktime(0, 0, 0, 4, 1, 2026), 'options_lfxperiodend' => mktime(0, 0, 0, 6, 30, 2026)];
$w = [];
$m = auvoerechnung_build_model($inv, $seller, $w, ['profile' => 'xrechnung']);
lfx_assert_eq('20260401', $m['document']['period_start'], 'BT-73 de la facture');
lfx_assert_eq('20260630', $m['document']['period_end'], 'BT-74 de la facture');
$inv->array_options = [];
$m = auvoerechnung_build_model($inv, $seller, $w, ['profile' => 'xrechnung']);
lfx_assert_eq('20260503', $m['document']['period_start'], 'sans saisie : période des lignes');
$inv->array_options = ['options_lfxperiodstart' => mktime(0, 0, 0, 6, 30, 2026), 'options_lfxperiodend' => mktime(0, 0, 0, 4, 1, 2026)];
$w = [];
auvoerechnung_build_model($inv, $seller, $w, ['profile' => 'xrechnung']);
lfx_assert(lfx_contains($w, 'AuvoERechnungWarnPeriodOrder'), 'BR-29 : fin avant début signalée');
$inv->array_options = [];

// Lieu de livraison (contact de livraison), en CII et en UBL
$w = [];
$m = auvoerechnung_build_model($inv, $seller, $w, ['profile' => 'xrechnung']);
lfx_assert_eq(null, $m['delivery']['ship_to'], 'pas de BG-13 sans contact de livraison');
$m['delivery']['ship_to'] = ['name' => 'Außenstelle Köln', 'address' => ['postcode' => '50667', 'line1' => 'Domplatz 2', 'city' => 'Köln', 'country' => 'DE']];
$cii = auvoerechnung_model_to_cii($m);
lfx_std_validate($cii, $xsdPath);
$xp = lfx_xpath($cii);
lfx_assert_eq('Außenstelle Köln', lfx_xp_str($xp, '//ram:ShipToTradeParty/ram:Name'), 'BT-70 CII');
lfx_assert_eq('Köln', lfx_xp_str($xp, '//ram:ShipToTradeParty/ram:PostalTradeAddress/ram:CityName'), 'BT-77 CII');
lfx_dump('xr-10-lieferort-cii', $cii);
$ubl = auvoerechnung_model_to_ubl($m);
lfx_assert(auvoerechnung_validate_xsd($ubl, dirname(__DIR__)) === null, 'XSD UBL avec BG-13');
$xpu = lfx_ubl_xpath($ubl);
lfx_assert_eq('Außenstelle Köln', lfx_xp_str($xpu, '//cac:Delivery/cac:DeliveryParty/cac:PartyName/cbc:Name'), 'BT-70 UBL');
lfx_assert_eq('50667', lfx_xp_str($xpu, '//cac:Delivery/cac:DeliveryLocation/cac:Address/cbc:PostalZone'), 'BT-78 UBL');
lfx_dump('ubl-lieferort', $ubl);

// Case « Keine E-Rechnung »
lfx_assert(!auvoerechnung_einvoice_disabled($inv), 'e-facture active par défaut');
$inv->array_options = ['options_lfxnoeinvoice' => '1'];
lfx_assert(auvoerechnung_einvoice_disabled($inv), 'e-facture désactivée sur la facture');
echo "U45 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U46 retours de test (BT-8, HTML, BR-FR-16, police, G mixte, prix unitaire)';
lfx_reset([
	'AUVOERECHNUNG_CONTACT_NAME' => 'Erika Mustermann',
	'AUVOERECHNUNG_CONTACT_PHONE' => '+49 6772 123456',
	'AUVOERECHNUNG_PAYMENT_MEANS' => '58',
], ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001']);
$seller = lfx_make_party([
	'name' => 'MUSTER GMBH', 'country_code' => 'DE', 'zip' => '56357', 'town' => 'Miehlen',
	'address' => 'Hauptstr. 1', 'email' => 'rechnung@muster.example', 'phone' => '06772 99',
	'tva_intra' => 'DE811111111', 'tva_assuj' => 1,
]);
$buyerDE = lfx_make_party(['name' => 'Kunde GmbH', 'country_code' => 'DE', 'zip' => '10115', 'town' => 'Berlin',
	'address' => 'Weg 2', 'email' => 'ap@kunde.example', 'tva_intra' => 'DE999999999']);

// BT-8 : Dolibarr met TAX_MODE_SELL_SERVICE = payment en mémoire quand rien
// n'est enregistré ; un vendeur allemand ne doit pas en déduire « Ist » (72).
$GLOBALS['lfx_test_conf']['TAX_MODE_SELL_SERVICE'] = 'payment';
lfx_assert_eq('', auvoerechnung_resolve_vat_due_date_code($seller), 'BT-8 : vendeur DE, défaut Dolibarr ignoré');
lfx_assert_eq('72', auvoerechnung_resolve_vat_due_date_code(lfx_make_party()), 'BT-8 : vendeur FR suit le régime Dolibarr');
$GLOBALS['lfx_test_conf']['AUVOERECHNUNG_VAT_DUE_DATE'] = '5';
lfx_assert_eq('5', auvoerechnung_resolve_vat_due_date_code($seller), 'BT-8 : Soll-Versteuerung choisie');
$GLOBALS['lfx_test_conf']['AUVOERECHNUNG_VAT_DUE_DATE'] = 'none';
lfx_assert_eq('', auvoerechnung_resolve_vat_due_date_code(lfx_make_party()), 'BT-8 : aucune indication');
unset($GLOBALS['lfx_test_conf']['AUVOERECHNUNG_VAT_DUE_DATE'], $GLOBALS['lfx_test_conf']['TAX_MODE_SELL_SERVICE']);

// Description WYSIWYG (HTML) → texte, sans double encodage des entités ;
// prix unitaire réel (9,99 × 0,333) au lieu de la reconstitution 10,00
$inv = new LfxFakeInvoice();
$inv->ref = 'RE2026-0900';
$inv->thirdparty = $buyerDE;
$inv->lines = [
	lfx_make_line(119.00, 19.0, 22.61, 1.0, '<p>Montage&nbsp;vor Ort &amp; Anfahrt<br />Zeile&nbsp;2</p>', 1),
	lfx_make_line(3.33, 19.0, 0.63, 0.333, 'Kabel', 0, ['subprice' => 9.99]),
	lfx_make_line(90.00, 7.0, 6.30, 1.0, 'Fachbuch', 0, ['subprice' => 100.00, 'remise_percent' => 10]),
];
$inv->total_ht = 212.33;
$inv->total_tva = 29.54;
$inv->total_ttc = 241.87;
$w = [];
$xml = auvoerechnung_build_xml($inv, $seller, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq("Montage vor Ort & Anfahrt\nZeile 2", lfx_xp_str($xp, '(//ram:SpecifiedTradeProduct/ram:Name)[1]'), 'BT-153 sans balises ni entités');
lfx_assert(strpos($xml, '&amp;nbsp;') === false && strpos($xml, '&amp;amp;') === false, 'pas de double encodage');
lfx_assert_eq('9.99', lfx_xp_str($xp, '(//ram:NetPriceProductTradePrice/ram:ChargeAmount)[2]'), 'BT-146 = prix saisi');
lfx_assert_eq('90.00', lfx_xp_str($xp, '(//ram:NetPriceProductTradePrice/ram:ChargeAmount)[3]'), 'BT-146 = prix après remise');
lfx_assert_eq(10.0, auvoerechnung_line_unit_price((object) ['subprice' => 10.0], 1.0, 3.0, 29.99), 'prix modifié : reconstitution');
lfx_assert_eq('', auvoerechnung_html_to_text('<p>&nbsp;</p>'), 'description vide');

// BR-FR-16 : la liste des taux français ne concerne pas un vendeur allemand
$warns = auvoerechnung_check_mandatory($inv, $seller);
lfx_assert(!lfx_contains($warns, 'AuvoERechnungWarnVatRateNotFR'), 'BR-FR-16 : pas d\'avertissement pour 19 %/7 % en DE');
$frInv = clone $inv;
$frInv->thirdparty = lfx_make_party(['name' => 'Client SAS']);
lfx_assert(lfx_contains(auvoerechnung_check_mandatory($frInv, lfx_make_party()), 'AuvoERechnungWarnVatRateNotFR'), 'BR-FR-16 : toujours actif pour un vendeur FR');

// Police PDF de base non embarquée (helvetica) : avertissement PDF/A-3
lfx_assert(auvoerechnung_pdf_font_embedded('helvetica') === false, 'helvetica non embarquée');
lfx_assert(auvoerechnung_pdf_font_embedded('pdfahelvetica') === true, 'pdfahelvetica embarquée');
$GLOBALS['lfx_test_conf']['MAIN_PDF_FORCE_FONT'] = 'helvetica';
lfx_assert(lfx_contains(auvoerechnung_check_mandatory($inv, $seller), 'AuvoERechnungWarnForceFontNotEmbedded'), 'avertissement police non embarquée');
$GLOBALS['lfx_test_conf']['MAIN_PDF_FORCE_FONT'] = 'pdfahelvetica';
lfx_assert(!lfx_contains(auvoerechnung_check_mandatory($inv, $seller), 'AuvoERechnungWarnForceFontNotEmbedded'), 'pas d\'avertissement avec pdfahelvetica');

// Pays tiers : prestation (§ 3a) et marchandise (export) dans une seule
// ventilation G (BR-G-08), avec les deux motifs
$inv = new LfxFakeInvoice();
$inv->ref = 'RE2026-0901';
$inv->thirdparty = lfx_make_party(['name' => 'ACME Inc', 'country_code' => 'US', 'zip' => '10001', 'town' => 'New York',
	'address' => '1 Main St', 'email' => 'ap@acme.example', 'tva_intra' => '']);
$inv->lines = [lfx_make_line(500.00, 0, 0, 1.0, 'Beratung', 1), lfx_make_line(300.00, 0, 0, 1.0, 'Ware', 0)];
$inv->total_ht = 800.00;
$inv->total_tva = 0;
$inv->total_ttc = 800.00;
$w = [];
$xml = auvoerechnung_build_xml($inv, $seller, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq(1, $xp->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax')->length, 'une seule ventilation G');
$reason = (string) lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:ExemptionReason');
lfx_assert(strpos($reason, '§ 3a Abs. 2 UStG') !== false && strpos($reason, '§ 4 Nr. 1a') !== false, 'les deux motifs : '.$reason);
echo "U46 OK\n";

// ---------------------------------------------------------------------------
echo "\n=== Résultat ===\n";
echo "Passed : $passed\n";
echo "Failed : $failed\n";
if (!empty($failures)) {
	echo "\nDétail des échecs :\n";
	foreach ($failures as $f) {
		echo "  - $f\n";
	}
}
exit($failed > 0 ? 1 : 0);
