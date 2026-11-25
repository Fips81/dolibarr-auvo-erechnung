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
 * Module descriptor for AuVo E-Rechnung
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modAuvoERechnung extends DolibarrModules
{
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;
		$this->numero = 194202; // ID block 194202-194211 reserved for AuVo Design (wiki.dolibarr.org, List of modules id).
		$this->rights_class = 'auvoerechnung';
		$this->family = "financial";
		$this->module_position = 90;
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		// Module list label: Module194202Desc from the language files; this text
		// is only a fallback.
		$this->description = "E-invoices for Dolibarr: ZUGFeRD / Factur-X (EN16931) and XRechnung (CII/UBL)";
		$this->descriptionlong = "Embeds an EN16931 CrossIndustryInvoice XML in every customer invoice PDF (ZUGFeRD / Factur-X) and creates XRechnung files in CII or UBL.";
		$this->version = '5.3.1';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'bill';
		$this->editor_name = 'auvo-design';
		$this->editor_url = 'https://auvo-design.de';

		// Requirements checked during activation. Minimum Dolibarr 19: the module
		// uses GETPOSTINT() (introduced in 19.0); tested with Dolibarr 22 / PHP 8.2.
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(19, 0);

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'theme' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'hooks' => array(
				'pdfgeneration',
				'invoicecard',
				// Context for invoice ODT generation models.
				// (core/modules/facture/doc/doc_generic_invoice_odt.modules.php:
				// initHooks(array('odtgeneration')) followed by executeHooks('afterODTCreation', ...)).
				// Allows Factur-X embedding in PDFs generated from ODT models
				// converted by LibreOffice (requires MAIN_ODT_AS_PDF=libreoffice and
				// MAIN_ODT_AS_PDFA=1; otherwise the PDF is >=1.5 and unreadable by FPDI).
				//
				// Deployment note: Dolibarr reads this new context only when it
				// rebuilds the hook list. On an already-active instance, disable and
				// re-enable the module to register 'odtgeneration'. This is safe because
				// remove() below does not delete user data.
				'odtgeneration',
				// "Send by email" form: attach XRechnung automatically
				// (getFormMail hook; re-enabling the module is required).
				'formmail',
			),
		);

		$this->dirs = array();
		$this->config_page_url = array('setup.php@auvoerechnung');

		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("auvoerechnung@auvoerechnung");

		// Conversion is active whenever the module is enabled (there is no longer
		// an AUVOERECHNUNG_ENABLED constant). Legal ID (automatic by profile), VAT
		// due date BT-8 (from Dolibarr TAX_MODE), invoice process BT-23 (per invoice),
		// and endpoint scheme (fixed at 0225) are no longer settings.
		$this->const = array(
			array('AUVOERECHNUNG_BANK_ACCOUNT', 'int', '0', 'ID des Bankkontos für IBAN/BIC', 1, 'current', 0),
			array('AUVOERECHNUNG_PAYMENT_MEANS', 'chaine', '30', 'Zahlungsart-Code UNTDID 4461 (30=Überweisung, 58=SEPA-Überweisung, 59=SEPA-Lastschrift, 49=Lastschrift)', 1, 'current', 0),
			array('AUVOERECHNUNG_STRICT_MODE', 'int', '0', 'Fehlermodus: 0 = best-effort, 1 = strikt (blockierend)', 1, 'current', 0),
			array('AUVOERECHNUNG_BR_CHECK', 'int', '1', 'Interne Prüfung der EN16931-Geschäftsregeln (BR-*) vor der Einbettung', 1, 'current', 0),
			array('AUVOERECHNUNG_INJECTION_MODE', 'chaine', 'auto', 'Einbettungsmodus der XML: auto (Subprozess wenn exec verfügbar, sonst In-Process) | inprocess (ohne exec, gehärtetes Hosting) | subprocess (nur exec, bisheriges Verhalten)', 1, 'current', 0),
			array('AUVOERECHNUNG_PHP_CLI_PATH', 'chaine', '', 'Manueller Pfad zum PHP-CLI-Programm (nur im Subprozess-Modus; leer = automatische Erkennung)', 1, 'current', 0),
			array('AUVOERECHNUNG_VERAPDF_PATH', 'chaine', '', 'Pfad zum veraPDF-Programm für die PDF/A-3-Nachprüfung (optional)', 1, 'current', 0),
			array('AUVOERECHNUNG_ENDPOINT_SUFFIX_SELLER', 'chaine', '', 'Suffix zur SIREN des Verkäufers in der elektronischen Adresse BT-34 (z. B. _Status) — nur Frankreich, von manchen Plattformen verlangt; leer = bloße SIREN', 1, 'current', 0),
			array('AUVOERECHNUNG_NOTE_PMD', 'chaine', '', 'Hinweis Verzugszinsen (PMD); leer = französischer Standardtext nur bei Verkäufern in Frankreich (BR-FR-05)', 1, 'current', 0),
			array('AUVOERECHNUNG_NOTE_PMT', 'chaine', '', 'Hinweis Mahnpauschale (PMT); leer = französischer Standardtext nur bei Verkäufern in Frankreich', 1, 'current', 0),
			array('AUVOERECHNUNG_NOTE_AAB', 'chaine', '', 'Hinweis Skonto (AAB); leer = französischer Standardtext nur bei Verkäufern in Frankreich', 1, 'current', 0),
			array('AUVOERECHNUNG_NOTES_IN_FOOTER', 'int', '0', 'Pflichthinweise zusätzlich in die Rechnungsfußzeile übernehmen (INVOICE_FREE_TEXT)', 1, 'current', 0),
			array('AUVOERECHNUNG_NOTES_OVERWRITE', 'int', '0', 'Übernahme: bestehenden Dolibarr-Fußtext überschreiben (1) statt die Hinweise anzuhängen (0)', 1, 'current', 0),
			array('AUVOERECHNUNG_XRECHNUNG_VERSION', 'chaine', '3.0', 'XRechnung-Version für BT-24 (KoSIT, z. B. 3.0)', 1, 'current', 0),
			array('AUVOERECHNUNG_CONTACT_NAME', 'chaine', '', 'XRechnung: Ansprechpartner (BT-41); leer = Ersteller der Rechnung', 1, 'current', 0),
			array('AUVOERECHNUNG_CONTACT_PHONE', 'chaine', '', 'XRechnung: Telefon des Ansprechpartners (BT-42); leer = Ersteller, sonst Firma', 1, 'current', 0),
			array('AUVOERECHNUNG_CONTACT_EMAIL', 'chaine', '', 'XRechnung: E-Mail des Ansprechpartners (BT-43); leer = Ersteller, sonst Firma', 1, 'current', 0),
			array('AUVOERECHNUNG_UPDATE_CHECK', 'int', '1', 'Auf der Einstellungsseite bei GitHub nach einer neuen Version fragen (einmal pro Tag)', 1, 'current', 0),
			array('AUVOERECHNUNG_VAT_DUE_DATE', 'chaine', 'auto', 'BT-8 Fälligkeit der USt: auto (Dolibarr-Steuermodus nur für Verkäufer in Frankreich), none, 5 (Soll), 72 (Ist)', 1, 'current', 0),
			array('AUVOERECHNUNG_XRECHNUNG_SYNTAX', 'chaine', 'cii', 'XRechnung: Syntax CII oder UBL (Standard; je Kunde über das Feld E-Rechnungsformat abweichend)', 1, 'current', 0),
			array('AUVOERECHNUNG_XR_EMBED_PDF', 'int', '0', 'XRechnung: PDF der Rechnung als Anlage einbetten (BG-24)', 1, 'current', 0),
			array('AUVOERECHNUNG_XR_EMBED_FILES', 'int', '0', 'XRechnung: hochgeladene Dokumente der Rechnung als Anlagen einbetten (BG-24)', 1, 'current', 0),
			array('AUVOERECHNUNG_MAIL_ATTACH_XR', 'int', '1', 'XRechnung beim Mailversand der Rechnung automatisch anhängen', 1, 'current', 0),
			array('AUVOERECHNUNG_OZGRE_EMAIL', 'chaine', '', 'XRechnung: persönliche Einreichungsadresse des OZG-RE-Kontos (E-Mail-Kanal); leer = kein Versand', 1, 'current', 0),
			array('AUVOERECHNUNG_SKONTO_TERMS', 'chaine', '', 'Skonto je Zahlungsbedingung (JSON nach ID der Zahlungsbedingung, BT-20 #SKONTO#); Einstellung auf der Modulseite', 1, 'current', 0),
			array('AUVOERECHNUNG_CHORUS_ENABLED', 'int', '0', 'Chorus-Pro-Funktionen aktivieren (Reiter, Menü, 2. PDF; nur Frankreich) — opt-in', 1, 'current', 0),
		);

		// Module permissions (in addition to core invoice permissions, which are
		// still required): view/download, (re)generate, and send to OZG-RE. Automatic
		// generation during PDF creation does not depend on a permission.
		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero.sprintf('%02d', $r + 1);
		$this->rights[$r][1] = 'E-Rechnungen prüfen und herunterladen';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'einvoice';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero.sprintf('%02d', $r + 1);
		$this->rights[$r][1] = 'E-Rechnungen neu erzeugen (ZUGFeRD-PDF, XRechnung, Chorus Pro)';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'einvoice';
		$this->rights[$r][5] = 'write';
		$r++;
		$this->rights[$r][0] = $this->numero.sprintf('%02d', $r + 1);
		$this->rights[$r][1] = 'XRechnung an die OZG-RE senden';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'einvoice';
		$this->rights[$r][5] = 'send';
		$r++;
		$this->menu = array();

		// Dedicated "Chorus Pro" tab on the invoice card (groups Chorus fields,
		// hidden from the standard extrafields display with list=0).
		// The invoice tab type is "invoice" (not "facture"), which facture_prepare_head()
		// passes to complete_head_from_modules(). With "facture", the tab never
		// appears. The condition (5th field) is evaluated at runtime, so the tab
		// appears only when Chorus features are enabled (opt-in, no reactivation).
		// Use getDolGlobalInt(), not $conf->global->XXX: Dolibarr 23's hardened
		// dol_eval no longer evaluates that syntax, causing the condition to fail
		// and the tab to disappear. getDolGlobalInt() works.
		$this->tabs = array(
			'invoice:+auvoerechnungchorus:AuvoERechnungChorusTab:auvoerechnung@auvoerechnung:getDolGlobalInt("AUVOERECHNUNG_CHORUS_ENABLED"):/auvoerechnung/chorus_tab.php?id=__ID__',
		);
	}

	/**
	 * Module activation: creates Chorus Pro invoice extrafields and the
	 * Leitweg-ID third-party extrafield.
	 *
	 * @param string $options Activation options
	 * @return int 1 on success, 0 on failure
	 */
	public function init($options = '')
	{
		// Before _init(): do not overwrite constants migrated from the old module
		// with defaults from $this->const.
		$this->migrateFromPreviousNames();
		$result = $this->_init(array(), $options);
		$this->createChorusExtraFields();
		$this->fixChorusExtraFieldsDisplay();
		$this->createLeitwegIdExtraField();
		$this->createEFormatExtraField();
		$this->createSupplierNumberExtraField();
		$this->createInvoiceExtraFields();
		$this->updateExtraFieldConditions();
		$this->grantRightsFromInvoiceRights();
		// Migrate Skonto 5.2.x from code-indexed to payment-term-ID-indexed.
		dol_include_once('/auvoerechnung/core/lib/auvoerechnung.lib.php');
		auvoerechnung_migrate_skonto_keys($this->db);
		return $result;
	}

	/**
	 * Migrates an installation from an old module name on the first activation
	 * of AuVo E-Rechnung:
	 *   DoliZUGFeRD 4.0.x  (dossier custom/dolizugferd, constantes DOLIZUGFERD_*)
	 *   AuvoZugferd 3.x    (dossier custom/auvozugferd, constantes AUVOZUGFERD_*)
	 *  - Copy constants to AUVOERECHNUNG_* (per entity), unless the new constant
	 *    already exists. Check the newest name first, so DoliZUGFeRD values take
	 *    precedence over older AuvoZugferd values. Ignore cache constants (*_CACHE)
	 *    and retain old constants to allow rollback.
	 *  - For lfx* extrafields (Leitweg-ID, Chorus Pro), update visibility and the
	 *    language file that point to the old module; otherwise the fields would
	 *    disappear. Replace legacy labels (AuvoZugferdEf*, DoliZugferdEf*, and
	 *    early LemonFacturXEf*) with current keys. Do not alter entered values
	 *    (llx_societe_extrafields / llx_facture_extrafields columns stay unchanged).
	 * Idempotent: does nothing when there is no older installation.
	 */
	private function migrateFromPreviousNames()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		foreach (array('DOLIZUGFERD_', 'AUVOZUGFERD_') as $oldPrefix) {
			$this->migrateConstants($oldPrefix);
		}

		$labels = array(
			'lfxchorus'      => 'AuvoERechnungEfChorus',
			'lfxcadre'       => 'AuvoERechnungEfCadre',
			'lfxservicecode' => 'AuvoERechnungEfServiceCode',
			'lfxengagement'  => 'AuvoERechnungEfEngagement',
			'lfxmarche'      => 'AuvoERechnungEfMarche',
			'lfxleitwegid'   => 'AuvoERechnungEfLeitwegId',
			'lfxeformat'     => 'AuvoERechnungEfEFormat',
			'lfxsupplierno'  => 'AuvoERechnungEfSupplierNo',
		);
		foreach ($labels as $name => $label) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."extrafields SET";
			$sql .= " label = '".$this->db->escape($label)."',";
			$sql .= " langs = 'auvoerechnung@auvoerechnung',";
			$sql .= " enabled = '".$this->db->escape('isModEnabled("auvoerechnung")')."'";
			$sql .= " WHERE name = '".$this->db->escape($name)."'";
			$sql .= " AND elementtype IN ('facture', 'societe')";
			$sql .= " AND langs <> 'auvoerechnung@auvoerechnung'";
			$this->db->query($sql);
		}
	}

	/**
	 * Own rights exist since 5.2.0. So that nobody loses a function with the
	 * update, users and groups get them once from the invoice rights they
	 * already had: read invoices (11) -> read, create invoices (12) -> write,
	 * and send (12, plus "send by email" (15) with advanced permissions enabled).
	 * Runs only once: rights taken away later are not given back.
	 *
	 * @return void
	 */
	private function grantRightsFromInvoiceRights()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		if (getDolGlobalInt('AUVOERECHNUNG_RIGHTS_GRANTED')) {
			return;
		}
		$base = (int) $this->numero * 100;
		$map = array(
			$base + 1 => array(11),
			$base + 2 => array(12),
			$base + 3 => getDolGlobalString('MAIN_USE_ADVANCED_PERMS') ? array(12, 15) : array(12),
		);
		foreach ($map as $newId => $needed) {
			foreach (array('user_rights' => 'fk_user', 'usergroup_rights' => 'fk_usergroup') as $table => $col) {
				$sql = "INSERT INTO ".MAIN_DB_PREFIX.$table." (entity, ".$col.", fk_id)";
				$sql .= " SELECT DISTINCT o.entity, o.".$col.", ".((int) $newId)." FROM ".MAIN_DB_PREFIX.$table." as o";
				$sql .= " WHERE o.fk_id = ".((int) $needed[0]);
				foreach (array_slice($needed, 1) as $other) {
					$sql .= " AND EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX.$table." as x WHERE x.entity = o.entity AND x.".$col." = o.".$col." AND x.fk_id = ".((int) $other).")";
				}
				$sql .= " AND NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX.$table." as n WHERE n.entity = o.entity AND n.".$col." = o.".$col." AND n.fk_id = ".((int) $newId).")";
				$this->db->query($sql);
			}
		}
		dolibarr_set_const($this->db, 'AUVOERECHNUNG_RIGHTS_GRANTED', '1', 'chaine', 0, 'Eigene Rechte einmalig aus den Rechnungsrechten übernommen (5.2.0)', 0);
	}

	/**
	 * Extrafields created before 5.2.0 are shown under the condition
	 * '$conf->auvoerechnung->enabled'; addExtraField() does not touch existing
	 * fields, so switch them to isModEnabled(), which Dolibarr recommends
	 * since the module objects in $conf are deprecated.
	 *
	 * @return void
	 */
	private function updateExtraFieldConditions()
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."extrafields";
		$sql .= " SET enabled = '".$this->db->escape('isModEnabled("auvoerechnung")')."'";
		$sql .= " WHERE name LIKE 'lfx%'";
		$sql .= " AND elementtype IN ('facture', 'societe')";
		$sql .= " AND enabled = '".$this->db->escape('$conf->auvoerechnung->enabled')."'";
		$this->db->query($sql);
	}

	/**
	 * Copies constants from <old prefix>* to AUVOERECHNUNG_* (see
	 * migrateFromPreviousNames()).
	 *
	 * @param string $oldPrefix  Prefix from the old name, e.g. 'DOLIZUGFERD_'
	 */
	private function migrateConstants($oldPrefix)
	{
		$sql = "SELECT name, entity, value, type, visible, note FROM ".MAIN_DB_PREFIX."const";
		$sql .= " WHERE name LIKE '".$this->db->escape($oldPrefix)."%'";
		$resql = $this->db->query($sql);
		if ($resql) {
			$rows = array();
			while ($obj = $this->db->fetch_object($resql)) {
				$rows[] = $obj;
			}
			foreach ($rows as $obj) {
				if (substr($obj->name, -6) === '_CACHE') {
					continue;
				}
				$newName = 'AUVOERECHNUNG_'.substr($obj->name, strlen($oldPrefix));
				$check = $this->db->query("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."const WHERE name = '".$this->db->escape($newName)."' AND entity = ".((int) $obj->entity));
				$exists = $check ? (int) $this->db->fetch_object($check)->nb : 1;
				if ($exists === 0) {
					dolibarr_set_const($this->db, $newName, $obj->value, $obj->type, (int) $obj->visible, $obj->note, (int) $obj->entity);
				}
			}
		}
	}

	/**
	 * Deactivation: do NOT delete extrafields. remove() runs on every deactivation,
	 * not only on uninstall; never delete user-entered data.
	 *
	 * @param string $options Deactivation options
	 * @return int 1 on success, 0 on failure
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}

	/**
	 * Creates missing invoice extrafields for the Chorus Pro profile: force
	 * checkbox, service code (BT-10), commitment number (BT-13), and contract
	 * number (BT-12). Idempotent and non-blocking.
	 */
	private function createChorusExtraFields()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		require_once dol_buildpath('/auvoerechnung/core/lib/auvoerechnung.lib.php');

		$extrafields = new ExtraFields($this->db);
		$extrafields->fetch_name_optionals_label('facture');

		$cadres = auvoerechnung_chorus_frameworks();

		// attrname, label (translation key), type, size (varchar) OR param (select), position.
		$defs = array(
			array('lfxchorus',     'AuvoERechnungEfChorus',      'boolean', '',                          1010),
			array('lfxcadre',      'AuvoERechnungEfCadre',       'select',  array('options' => $cadres), 1011),
			array('lfxservicecode','AuvoERechnungEfServiceCode', 'varchar', '255',                       1012),
			array('lfxengagement', 'AuvoERechnungEfEngagement',  'varchar', '255',                       1013),
			array('lfxmarche',     'AuvoERechnungEfMarche',      'varchar', '255',                       1014),
		);
		foreach ($defs as $d) {
			if (!empty($extrafields->attributes['facture']['label'][$d[0]])) {
				continue; // already present
			}
			$size  = is_array($d[3]) ? '' : $d[3];   // size (string) for varchar
			$param = is_array($d[3]) ? $d[3] : '';   // option array for select
			// Signature : (attrname, label, type, pos, size, elementtype, unique,
			// required, default, param, alwayseditable, perms, list, ...). list='0'
			// Hide from the standard display: edit these fields ONLY in the
			// dedicated "Chorus Pro" tab.
			$extrafields->addExtraField(
				$d[0], $d[1], $d[2], $d[4], $size, 'facture',
				0, 0, '', $param, 1, '', '0', '', '', '',
				'auvoerechnung@auvoerechnung', 'isModEnabled("auvoerechnung")'
			);
		}
	}

	/**
	 * Set printable=0 and list=0 for Chorus extrafields.
	 *
	 * These are internal fields (edited only in the "Chorus Pro" tab) and must
	 * NEVER be printed on the PDF. Dolibarr 23 creates extrafields with
	 * printable!=0, and its PDF model (pdf_sponge -> getExtrafieldsInHtml) prints
	 * every extrafield with printable=1 or 2 in the PDF note. This caused empty
	 * Chorus labels to appear in the invoice description (reported on Dolibarr 23.0.1).
	 *
	 * Force printable=0 (and list=0) idempotently. Running this on every
	 * activation also fixes existing installations where these fields were
	 * created incorrectly. Harmless on Dolibarr <= 22, where they are already 0.
	 */
	private function fixChorusExtraFieldsDisplay()
	{
		require_once dol_buildpath('/auvoerechnung/core/lib/auvoerechnung.lib.php');
		auvoerechnung_fix_chorus_extrafields_display($this->db);
	}

	/**
	 * Creates the Leitweg-ID third-party extrafield (BT-10, German public-sector
	 * buyers) if missing. This static customer attribute is visible on the standard
	 * card (list='1', unlike hidden Chorus fields), so no dedicated tab is needed.
	 * Idempotent and non-blocking.
	 */
	private function createLeitwegIdExtraField()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

		$extrafields = new ExtraFields($this->db);
		$extrafields->fetch_name_optionals_label('societe');
		if (!empty($extrafields->attributes['societe']['label']['lfxleitwegid'])) {
			return; // already present
		}
		// Same signature as createChorusExtraFields(): (attrname, label, type,
		// pos, size, elementtype, unique, required, default, param, alwayseditable,
		// perms, list, ...). Size 46 is the maximum Leitweg-ID length.
		$extrafields->addExtraField(
			'lfxleitwegid', 'AuvoERechnungEfLeitwegId', 'varchar', 200, '46', 'societe',
			0, 0, '', '', 1, '', '1', '', '', '',
			'auvoerechnung@auvoerechnung', 'isModEnabled("auvoerechnung")'
		);
	}

	/**
	 * Creates the "E-invoice format" third-party extrafield if missing: select
	 * the electronic invoice format per customer (automatic / ZUGFeRD / XRechnung),
	 * read by auvoerechnung_wants_xrechnung(). Empty means automatic (XRechnung
	 * whenever a Leitweg-ID is provided). Idempotent.
	 */
	private function createEFormatExtraField()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

		$extrafields = new ExtraFields($this->db);
		$extrafields->fetch_name_optionals_label('societe');
		$param = array('options' => array(
			'auto'          => 'AuvoERechnungEfEFormatAuto',
			'zugferd'       => 'AuvoERechnungEfEFormatZugferd',
			'xrechnung'     => 'AuvoERechnungEfEFormatXRechnung',
			'xrechnung_cii' => 'AuvoERechnungEfEFormatXRechnungCii',
			'xrechnung_ubl' => 'AuvoERechnungEfEFormatXRechnungUbl',
		));
		if (!empty($extrafields->attributes['societe']['label']['lfxeformat'])) {
			// Already exists: add any missing choices (CII/UBL syntaxes added in
			// 5.2.0) without changing entered values.
			$sql = "UPDATE ".MAIN_DB_PREFIX."extrafields SET param = '".$this->db->escape(serialize($param))."'";
			$sql .= " WHERE name = 'lfxeformat' AND elementtype = 'societe'";
			$this->db->query($sql);
			return;
		}
		$extrafields->addExtraField(
			'lfxeformat', 'AuvoERechnungEfEFormat', 'select', 201, '', 'societe',
			0, 0, '', $param, 1, '', '1', '', '', '',
			'auvoerechnung@auvoerechnung', 'isModEnabled("auvoerechnung")'
		);
	}

	/**
	 * Creates the "Our supplier number at the customer" third-party extrafield
	 * if missing. This customer-assigned supplier number is emitted in BT-29
	 * (required by § 5 E-RechV when provided by the administration). Idempotent.
	 */
	private function createSupplierNumberExtraField()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

		$extrafields = new ExtraFields($this->db);
		$extrafields->fetch_name_optionals_label('societe');
		if (!empty($extrafields->attributes['societe']['label']['lfxsupplierno'])) {
			return; // already present
		}
		$extrafields->addExtraField(
			'lfxsupplierno', 'AuvoERechnungEfSupplierNo', 'varchar', 202, '100', 'societe',
			0, 0, '', '', 1, '', '1', '', '', '',
			'auvoerechnung@auvoerechnung', 'isModEnabled("auvoerechnung")'
		);
	}

	/**
	 * Creates missing invoice-card extrafields. They are visible but never printed
	 * on the PDF. Idempotent:
	 *  - "Different Leitweg-ID": takes precedence over the third party's ID
	 *    (an administration with multiple departments under one third party);
	 *  - "Service period from/to": BG-14 period for the entire invoice;
	 *  - "No e-invoice": regular PDF, with no automatic XRechnung.
	 */
	private function createInvoiceExtraFields()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

		$extrafields = new ExtraFields($this->db);
		$extrafields->fetch_name_optionals_label('facture');
		// attrname, label, type, position, size.
		$defs = array(
			array('lfxleitwegid',   'AuvoERechnungEfInvoiceLeitwegId', 'varchar', 1000, '46'),
			array('lfxperiodstart', 'AuvoERechnungEfPeriodStart',      'date',    1001, ''),
			array('lfxperiodend',   'AuvoERechnungEfPeriodEnd',        'date',    1002, ''),
			array('lfxnoeinvoice',  'AuvoERechnungEfNoEInvoice',       'boolean', 1003, ''),
		);
		foreach ($defs as $d) {
			if (!empty($extrafields->attributes['facture']['label'][$d[0]])) {
				continue; // already present
			}
			$extrafields->addExtraField(
				$d[0], $d[1], $d[2], $d[3], $d[4], 'facture',
				0, 0, '', '', 1, '', '1', '', '', '',
				'auvoerechnung@auvoerechnung', 'isModEnabled("auvoerechnung")', 0, 0
			);
		}
	}
}
