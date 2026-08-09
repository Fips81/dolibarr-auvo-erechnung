<?php
/*
 * Stubs Dolibarr minimaux pour exécuter la lib AuvoZugferd hors Dolibarr
 * (tests unitaires standalone + CI). Chaque stub n'est défini que si la
 * fonction/classe Dolibarr réelle n'existe pas.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	die('CLI only');
}

if (!defined('MAIN_DB_PREFIX')) {
	define('MAIN_DB_PREFIX', 'llx_');
}
if (!defined('DOL_DOCUMENT_ROOT')) {
	define('DOL_DOCUMENT_ROOT', __DIR__.'/stubs-root');
}

if (!function_exists('getDolGlobalString')) {
	function getDolGlobalString($key, $default = '')
	{
		$conf = $GLOBALS['lfx_test_conf'] ?? [];
		return isset($conf[$key]) && (string) $conf[$key] !== '' ? (string) $conf[$key] : (string) $default;
	}
}
if (!function_exists('getDolGlobalInt')) {
	function getDolGlobalInt($key, $default = 0)
	{
		$conf = $GLOBALS['lfx_test_conf'] ?? [];
		return isset($conf[$key]) && (string) $conf[$key] !== '' ? (int) $conf[$key] : (int) $default;
	}
}
if (!function_exists('dolibarr_set_const')) {
	function dolibarr_set_const($db, $name, $value, $type = 'chaine', $visible = 0, $note = '', $entity = 1)
	{
		$GLOBALS['lfx_test_conf'][$name] = $value;
		return 1;
	}
}
if (!function_exists('dol_syslog')) {
	function dol_syslog($msg, $level = 0)
	{
	}
}
if (!function_exists('getEntity')) {
	function getEntity($element, $shared = 1)
	{
		return '1';
	}
}

/**
 * Base de données factice : associe un fragment de SQL à des lignes de
 * résultat. $GLOBALS['lfx_test_db_handlers'] = ['fragment SQL' => [obj, ...]].
 */
class LfxFakeDbResult
{
	public $rows;
	public $i = 0;

	public function __construct(array $rows)
	{
		$this->rows = $rows;
	}
}

class LfxFakeDb
{
	public function query($sql)
	{
		foreach (($GLOBALS['lfx_test_db_handlers'] ?? []) as $needle => $rows) {
			if (strpos($sql, $needle) !== false) {
				return new LfxFakeDbResult($rows);
			}
		}
		return new LfxFakeDbResult([]);
	}

	public function fetch_object($res)
	{
		if (!($res instanceof LfxFakeDbResult)) {
			return false;
		}
		return $res->rows[$res->i++] ?? false;
	}

	public function escape($s)
	{
		return addslashes((string) $s);
	}
}

/**
 * Facture factice : uniquement les propriétés lues par la lib.
 */
class LfxFakeInvoice
{
	public $db;
	public $id = 0;
	public $ref = 'TEST';
	public $ref_client = '';
	public $type = 0;
	public $date;
	public $date_lim_reglement = null;
	public $delivery_date = null;
	public $date_livraison = null;
	public $fk_facture_source = null;
	public $multicurrency_code = '';
	public $total_ht = 0.0;
	public $total_tva = 0.0;
	public $total_ttc = 0.0;
	public $total_localtax1 = 0.0;
	public $total_localtax2 = 0.0;
	public $lines = [];
	public $thirdparty;
	public $sumDepositsUsed = 0.0;

	public function __construct()
	{
		$this->db = new LfxFakeDb();
		$this->date = mktime(0, 0, 0, 6, 1, 2026);
	}

	public function getSumDepositsUsed()
	{
		return $this->sumDepositsUsed;
	}
}

/**
 * Crée une ligne de facture factice.
 */
function lfx_make_line($totalHt, $vatRate, $totalTva, $qty = 1.0, $desc = 'Prestation', $productType = 0, $extra = [])
{
	$line = new stdClass();
	$line->desc = $desc;
	$line->qty = $qty;
	$line->subprice = ($qty != 0) ? $totalHt / $qty : $totalHt;
	$line->total_ht = $totalHt;
	$line->total_tva = $totalTva;
	$line->tva_tx = $vatRate;
	$line->product_type = $productType;
	$line->fk_unit = 0;
	$line->date_start = null;
	$line->date_end = null;
	foreach ($extra as $k => $v) {
		$line->$k = $v;
	}
	return $line;
}

/**
 * Crée un tiers/société factice.
 */
function lfx_make_party($overrides = [])
{
	$p = new stdClass();
	$p->id = 100;
	$p->name = 'ACME SAS';
	$p->address = '1 rue de la Paix';
	$p->zip = '75002';
	$p->town = 'Paris';
	$p->country_code = 'FR';
	$p->email = 'contact@acme.example';
	$p->tva_intra = 'FR12345678901';
	$p->idprof2 = '12345678900011';
	$p->tva_assuj = 1;
	foreach ($overrides as $k => $v) {
		$p->$k = $v;
	}
	return $p;
}
