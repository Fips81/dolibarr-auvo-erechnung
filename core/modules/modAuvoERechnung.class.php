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
		$this->numero = 194202; // Bloc 194202-194211 réservé à AuVo Design (wiki.dolibarr.org, List of modules id)
		$this->rights_class = 'auvoerechnung';
		$this->family = "financial";
		$this->module_position = 90;
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Factures électroniques pour Dolibarr (ZUGFeRD / Factur-X EN16931)";
		$this->descriptionlong = "Injecte un XML CrossIndustryInvoice EN16931 dans chaque PDF facture client généré, pour conformité Factur-X.";
		// Libellé localisé pour la liste des modules : l'Allemagne connaît le format sous le nom ZUGFeRD
		// (ZUGFeRD 2.x profil EN16931 COMFORT = Factur-X EN16931, même standard).
		if (!empty($langs) && method_exists($langs, 'getDefaultLang') && $langs->getDefaultLang() == 'de_DE') {
			$this->description = "E-Rechnungen für Dolibarr (ZUGFeRD / Factur-X EN16931)";
			$this->descriptionlong = "Injiziert ein CrossIndustryInvoice-XML (EN16931) in jede erzeugte Kunden-Rechnungs-PDF, für ZUGFeRD-Konformität.";
		}
		$this->version = '5.1.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'bill';
		$this->editor_name = 'auvo-design';
		$this->editor_url = 'https://auvo-design.de';

		// Prérequis vérifiés à l'activation. Dolibarr 19 minimum : le module
		// utilise GETPOSTINT() (introduit en 19.0) ; testé sur Dolibarr 22 / PHP 8.2.
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
				// Contexte des modèles de génération ODT des factures
				// (core/modules/facture/doc/doc_generic_invoice_odt.modules.php :
				// initHooks(array('odtgeneration')) puis executeHooks('afterODTCreation', …)).
				// Permet d'injecter le Factur-X dans le PDF issu d'un modèle ODT
				// converti par LibreOffice (prérequis : MAIN_ODT_AS_PDF=libreoffice +
				// MAIN_ODT_AS_PDFA=1, sinon le PDF est >=1.5 et illisible par FPDI).
				//
				// NB déploiement : ce nouveau contexte n'est lu par Dolibarr qu'au
				// moment où il (re)construit la liste des hooks. Sur une instance déjà
				// active, il faut donc désactiver puis réactiver le module pour que
				// 'odtgeneration' soit pris en compte (remove() ne supprime aucune
				// donnée — cf. remove() ci-dessous —, la réactivation est sûre).
				'odtgeneration',
			),
		);

		$this->dirs = array();
		$this->config_page_url = array('setup.php@auvoerechnung');

		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("auvoerechnung@auvoerechnung");

		// NB : la conversion est active dès que le module l'est (plus de constante
		// AUVOERECHNUNG_ENABLED). L'identifiant légal (auto par profil), l'exigibilité
		// TVA BT-8 (lue depuis TAX_MODE Dolibarr), le cadre BT-23 (par facture) et le
		// schéma d'endpoint (0225 figé) ne sont plus des réglages.
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
			array('AUVOERECHNUNG_CHORUS_ENABLED', 'int', '0', 'Chorus-Pro-Funktionen aktivieren (Reiter, Menü, 2. PDF; nur Frankreich) — opt-in', 1, 'current', 0),
		);

		if (!isset($conf->auvoerechnung) || !isset($conf->auvoerechnung->enabled)) {
			$conf->auvoerechnung = new stdClass();
			$conf->auvoerechnung->enabled = 0;
		}

		$this->rights = array();
		$this->menu = array();

		// Onglet dédié « Chorus Pro » sur la fiche facture (regroupe les champs
		// Chorus, masqués de l'affichage extrafields standard via list=0).
		// NB : le type d'onglet pour les factures est « invoice » (et non
		// « facture ») — c'est ce que passe facture_prepare_head() à
		// complete_head_from_modules(). Avec « facture » l'onglet n'apparaît jamais.
		// La condition (5e champ) est évaluée au RUNTIME : l'onglet n'apparaît que
		// si les fonctionnalités Chorus sont activées (opt-in, sans réactivation).
		// NB : utiliser getDolGlobalInt() et NON $conf->global->XXX — le dol_eval
		// durci de Dolibarr 23 n'évalue plus la syntaxe $conf->global->XXX (la
		// condition tombe à faux et l'onglet disparaît). getDolGlobalInt() passe.
		$this->tabs = array(
			'invoice:+auvoerechnungchorus:AuvoERechnungChorusTab:auvoerechnung@auvoerechnung:getDolGlobalInt("AUVOERECHNUNG_CHORUS_ENABLED"):/auvoerechnung/chorus_tab.php?id=__ID__',
		);
	}

	/**
	 * Activation du module : crée les extrafields Chorus Pro sur les factures
	 * et l'extrafield Leitweg-ID sur les tiers.
	 *
	 * @param string $options Options d'activation
	 * @return int 1 si OK, 0 si KO
	 */
	public function init($options = '')
	{
		// Avant _init() : les constantes reprises de l'ancien module ne doivent
		// pas être écrasées par les valeurs par défaut de $this->const.
		$this->migrateFromPreviousNames();
		$result = $this->_init(array(), $options);
		$this->createChorusExtraFields();
		$this->fixChorusExtraFieldsDisplay();
		$this->createLeitwegIdExtraField();
		$this->createEFormatExtraField();
		$this->createSupplierNumberExtraField();
		return $result;
	}

	/**
	 * Reprise d'une installation sous un ancien nom du module, à la première
	 * activation de AuVo E-Rechnung :
	 *   DoliZUGFeRD 4.0.x  (dossier custom/dolizugferd, constantes DOLIZUGFERD_*)
	 *   AuvoZugferd 3.x    (dossier custom/auvozugferd, constantes AUVOZUGFERD_*)
	 *  - constantes copiées en AUVOERECHNUNG_* (par entité), sauf si la nouvelle
	 *    existe déjà. Le nom le plus récent passe en premier : une valeur
	 *    DoliZUGFeRD l'emporte sur une valeur AuvoZugferd plus ancienne. Les
	 *    caches (*_CACHE) sont ignorés. Les anciennes constantes sont conservées
	 *    (retour arrière possible) ;
	 *  - extrafields lfx* (Leitweg-ID, Chorus Pro) : leur visibilité et leur
	 *    fichier de langue pointaient sur l'ancien module — sans cette reprise
	 *    ils disparaîtraient. Les libellés hérités (AuvoZugferdEf*, DoliZugferdEf*,
	 *    voire LemonFacturXEf* des toutes premières versions) sont remplacés par
	 *    les clés actuelles. Les VALEURS saisies ne sont pas touchées (colonnes de
	 *    llx_societe_extrafields / llx_facture_extrafields inchangées).
	 * Idempotent : sans ancienne installation, ne fait rien.
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
			$sql .= " enabled = '".$this->db->escape('$conf->auvoerechnung->enabled')."'";
			$sql .= " WHERE name = '".$this->db->escape($name)."'";
			$sql .= " AND elementtype IN ('facture', 'societe')";
			$sql .= " AND langs <> 'auvoerechnung@auvoerechnung'";
			$this->db->query($sql);
		}
	}

	/**
	 * Copie les constantes <ancien préfixe>* en AUVOERECHNUNG_* (cf.
	 * migrateFromPreviousNames()).
	 *
	 * @param string $oldPrefix  Préfixe de l'ancien nom, ex. 'DOLIZUGFERD_'
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
	 * Désactivation : ne supprime PAS les extrafields (remove() est appelé à
	 * chaque désactivation, pas qu'à la désinstallation — on ne touche jamais
	 * aux données saisies par l'utilisateur).
	 *
	 * @param string $options Options de désactivation
	 * @return int 1 si OK, 0 si KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}

	/**
	 * Crée (si absents) les extrafields de la fiche facture pour le profil
	 * Chorus Pro : case à cocher de forçage + code service (BT-10), n° engagement
	 * (BT-13), n° marché (BT-12). Idempotent et non bloquant.
	 */
	private function createChorusExtraFields()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		require_once dol_buildpath('/auvoerechnung/core/lib/auvoerechnung.lib.php');

		$extrafields = new ExtraFields($this->db);
		$extrafields->fetch_name_optionals_label('facture');

		$cadres = auvoerechnung_chorus_frameworks();

		// attrname, label (clé de trad), type, taille (varchar) OU param (select), position
		$defs = array(
			array('lfxchorus',     'AuvoERechnungEfChorus',      'boolean', '',                          1010),
			array('lfxcadre',      'AuvoERechnungEfCadre',       'select',  array('options' => $cadres), 1011),
			array('lfxservicecode','AuvoERechnungEfServiceCode', 'varchar', '255',                       1012),
			array('lfxengagement', 'AuvoERechnungEfEngagement',  'varchar', '255',                       1013),
			array('lfxmarche',     'AuvoERechnungEfMarche',      'varchar', '255',                       1014),
		);
		foreach ($defs as $d) {
			if (!empty($extrafields->attributes['facture']['label'][$d[0]])) {
				continue; // déjà présent
			}
			$size  = is_array($d[3]) ? '' : $d[3];   // taille (chaîne) pour varchar
			$param = is_array($d[3]) ? $d[3] : '';   // tableau d'options pour select
			// Signature : (attrname, label, type, pos, size, elementtype, unique,
			// required, default, param, alwayseditable, perms, list, ...). list='0'
			// = masqué de l'affichage standard : ces champs ne s'éditent QUE dans
			// l'onglet « Chorus Pro » dédié.
			$extrafields->addExtraField(
				$d[0], $d[1], $d[2], $d[4], $size, 'facture',
				0, 0, '', $param, 1, '', '0', '', '', '',
				'auvoerechnung@auvoerechnung', '$conf->auvoerechnung->enabled'
			);
		}
	}

	/**
	 * Force printable=0 et list=0 sur les extrafields Chorus.
	 *
	 * Ces champs sont internes (édités uniquement dans l'onglet « Chorus Pro »)
	 * et ne doivent JAMAIS s'imprimer sur le PDF. Or Dolibarr 23 crée les
	 * extrafields avec printable!=0 et son modèle PDF (pdf_sponge →
	 * getExtrafieldsInHtml) imprime dans la NOTE du PDF tout extrafield dont
	 * printable vaut 1 ou 2 → un bloc de libellés Chorus vides apparaissait dans
	 * la description de la facture (signalé sur Dolibarr 23.0.1).
	 *
	 * On force donc printable=0 (et list=0) de façon idempotente : exécuté à
	 * chaque activation, ça corrige aussi les installations déjà créées de
	 * travers. Inoffensif sur Dolibarr ≤ 22 (déjà à 0).
	 */
	private function fixChorusExtraFieldsDisplay()
	{
		require_once dol_buildpath('/auvoerechnung/core/lib/auvoerechnung.lib.php');
		auvoerechnung_fix_chorus_extrafields_display($this->db);
	}

	/**
	 * Crée (si absent) l'extrafield tiers Leitweg-ID (BT-10, acheteurs secteur
	 * public allemand). Attribut statique du client, visible sur la fiche
	 * standard (list='1', contrairement aux champs Chorus masqués) : pas
	 * besoin d'onglet dédié. Idempotent et non bloquant.
	 */
	private function createLeitwegIdExtraField()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

		$extrafields = new ExtraFields($this->db);
		$extrafields->fetch_name_optionals_label('societe');
		if (!empty($extrafields->attributes['societe']['label']['lfxleitwegid'])) {
			return; // déjà présent
		}
		// Signature identique à createChorusExtraFields() : (attrname, label,
		// type, pos, size, elementtype, unique, required, default, param,
		// alwayseditable, perms, list, ...). Taille 46 = longueur max Leitweg-ID.
		$extrafields->addExtraField(
			'lfxleitwegid', 'AuvoERechnungEfLeitwegId', 'varchar', 200, '46', 'societe',
			0, 0, '', '', 1, '', '1', '', '', '',
			'auvoerechnung@auvoerechnung', '$conf->auvoerechnung->enabled'
		);
	}

	/**
	 * Crée (si absent) l'extrafield tiers « E-Rechnungsformat » : choix du
	 * format de facture électronique par client (automatique / ZUGFeRD /
	 * XRechnung), lu par auvoerechnung_wants_xrechnung(). Vide = automatique
	 * (XRechnung dès qu'une Leitweg-ID est renseignée). Idempotent.
	 */
	private function createEFormatExtraField()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

		$extrafields = new ExtraFields($this->db);
		$extrafields->fetch_name_optionals_label('societe');
		if (!empty($extrafields->attributes['societe']['label']['lfxeformat'])) {
			return; // déjà présent
		}
		$param = array('options' => array(
			'auto'      => 'AuvoERechnungEfEFormatAuto',
			'zugferd'   => 'AuvoERechnungEfEFormatZugferd',
			'xrechnung' => 'AuvoERechnungEfEFormatXRechnung',
		));
		$extrafields->addExtraField(
			'lfxeformat', 'AuvoERechnungEfEFormat', 'select', 201, '', 'societe',
			0, 0, '', $param, 1, '', '1', '', '', '',
			'auvoerechnung@auvoerechnung', '$conf->auvoerechnung->enabled'
		);
	}

	/**
	 * Crée (si absent) l'extrafield tiers « Unsere Lieferantennummer beim
	 * Kunden » : n° de fournisseur attribué par le client, émis en BT-29
	 * (exigé par § 5 E-RechV quand l'administration l'a communiqué). Idempotent.
	 */
	private function createSupplierNumberExtraField()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

		$extrafields = new ExtraFields($this->db);
		$extrafields->fetch_name_optionals_label('societe');
		if (!empty($extrafields->attributes['societe']['label']['lfxsupplierno'])) {
			return; // déjà présent
		}
		$extrafields->addExtraField(
			'lfxsupplierno', 'AuvoERechnungEfSupplierNo', 'varchar', 202, '100', 'societe',
			0, 0, '', '', 1, '', '1', '', '', '',
			'auvoerechnung@auvoerechnung', '$conf->auvoerechnung->enabled'
		);
	}
}
