<?php
/*
 * Minimal Dolibarr stubs for running the AuVo E-Rechnung library without
 * Dolibarr (standalone unit tests + CI). Each stub is defined only when the
 * corresponding real Dolibarr function/class does not exist.
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
if (!function_exists('dolibarr_del_const')) {
	function dolibarr_del_const($db, $name, $entity = 1)
	{
		unset($GLOBALS['lfx_test_conf'][$name]);
		return 1;
	}
}
if (!function_exists('dol_now')) {
	function dol_now()
	{
		return time();
	}
}
if (!function_exists('dol_sanitizeFileName')) {
	function dol_sanitizeFileName($str)
	{
		return preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $str);
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
 * Mock database: maps an SQL fragment to result rows.
 * $GLOBALS['lfx_test_db_handlers'] = ['SQL fragment' => [obj, ...]].
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
 * Mock invoice: only the properties read by the library.
 */
class LfxFakeInvoice
{
	public $db;
	public $id = 0;
	public $ref = 'TEST';
	public $ref_client = '';
	public $cond_reglement_id = 0;
	public $cond_reglement_code = '';
	public $cond_reglement_doc = '';
	public $array_options = [];
	public $entity = 1;
	public $socid = 0;
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
 * Create a mock invoice line.
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
 * Create a mock customer/company.
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
