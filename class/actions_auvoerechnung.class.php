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
 * Hooks AuVo E-Rechnung :
 *  - afterPDFCreation (contexte pdfgeneration) : injecte le XML Factur-X EN16931
 *    dans les PDF factures clients générés par les modèles TCPDF natifs
 *    (sponge / crabe / octopus)
 *  - afterODTCreation (contexte odtgeneration) : même injection pour les factures
 *    générées via un modèle ODT, une fois le .odt converti en PDF/A par LibreOffice
 *    (prérequis MAIN_ODT_AS_PDF=libreoffice + MAIN_ODT_AS_PDFA=1)
 *  - addMoreActionsButtons / doActions (contexte invoicecard) : boutons
 *    "Vérifier Factur-X" et "Régénérer Factur-X" sur la fiche facture
 */

class ActionsAuvoERechnung
{
	public $db;
	public $error = '';
	public $errors = [];
	public $resPrint = '';
	public $results = [];

	/** @var string|null Fichier XML temporaire à nettoyer en sortie de hook */
	private $xmlTmpFile = null;

	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Hook afterPDFCreation — contexte pdfgeneration
	 */
	public function afterPDFCreation($parameters, &$object, &$action, $hookmanager)
	{
		global $mysoc, $langs;


		$invoice = $parameters['object'] ?? null;
		if (!is_object($invoice) || !($invoice instanceof Facture)) {
			return 0;
		}

		// Ne pas injecter de Factur-X sur un brouillon. Dolibarr régénère le PDF
		// d'une facture en brouillon à chaque ajout/modif de ligne : on rejouerait
		// toute la chaîne (XML + validation XSD/BR + sous-process + veraPDF) à
		// chaque ligne, pour un document non définitif, avec un toast par ligne.
		// Le Factur-X n'a de sens que sur une facture validée ; à la validation,
		// le statut passe à VALIDATED AVANT le generateDocument(), donc l'injection
		// a bien lieu à ce moment. (Contribution de @daGrumpf-bxp, PR #10.)
		if ((int) ($invoice->status ?? $invoice->statut ?? 0) === Facture::STATUS_DRAFT) {
			return 0;
		}

		$file = $parameters['file'] ?? '';
		if (empty($file) || !file_exists($file)) {
			return 0;
		}

		$modulePath = dirname(__DIR__);
		require_once $modulePath.'/core/lib/auvoerechnung.lib.php';
		require_once $modulePath.'/core/lib/auvoerechnung_rules.php';
		if (is_object($langs)) {
			$langs->loadLangs(['auvoerechnung@auvoerechnung']);
		}

		$strict = (int) getDolGlobalInt('AUVOERECHNUNG_STRICT_MODE', 0);

		if (empty($invoice->thirdparty) || !is_object($invoice->thirdparty)) {
			$invoice->fetch_thirdparty();
		}
		if (empty($invoice->thirdparty) || !is_object($invoice->thirdparty)) {
			return $this->handleNonFatal('AuVo E-Rechnung: '.auvoerechnung_trans('AuvoERechnungErrNoThirdparty'), $strict);
		}
		if (empty($mysoc) || !is_object($mysoc) || empty($mysoc->name)) {
			return $this->handleNonFatal('AuVo E-Rechnung: '.auvoerechnung_trans('AuvoERechnungErrNoMysoc'), $strict);
		}
		if (empty($invoice->lines)) {
			$invoice->fetch_lines();
		}

		// Périmètre supporté (multidevise, date manquante...) : on refuse
		// proprement plutôt que d'émettre un XML divergent du PDF visible.
		$unsupported = auvoerechnung_check_supported($invoice);
		if ($unsupported !== null) {
			return $this->handleNonFatal('AuVo E-Rechnung: '.$unsupported, $strict, auvoerechnung_trans('AuvoERechnungHintPdfKept'));
		}

		// Vérifier les infos obligatoires (on continue même si incomplet en best-effort).
		// Les warnings ne sont PAS affichés individuellement : ils sont consolidés
		// dans le message final (vert si aucun, orange avec la liste si présents).
		$warnings = auvoerechnung_check_mandatory($invoice, $mysoc);

		$buildWarnings = [];
		$xml = auvoerechnung_build_xml($invoice, $mysoc, $buildWarnings);
		$warnings = array_merge($warnings, $buildWarnings);

		foreach ($warnings as $w) {
			dol_syslog('AuVo E-Rechnung WARNING: '.$w, LOG_WARNING);
		}

		// Validation interne avant injection : well-formed + XSD EN16931
		$validationError = $this->validateXml($xml, $modulePath);
		if ($validationError !== null) {
			return $this->handleNonFatal('AuVo E-Rechnung: '.auvoerechnung_trans('AuvoERechnungErrInvalidXml').' : '.$validationError, $strict, auvoerechnung_trans('AuvoERechnungHintPdfKept'));
		}

		// Contrôle des règles métier EN16931 (sous-ensemble Schematron) :
		// bloquant en mode strict, consolidé dans les warnings sinon.
		if (getDolGlobalInt('AUVOERECHNUNG_BR_CHECK', 1)) {
			$brViolations = auvoerechnung_validate_business_rules($xml);
			if (!empty($brViolations)) {
				if ($strict) {
					return $this->handleNonFatal('AuVo E-Rechnung: '.auvoerechnung_trans('AuvoERechnungErrBrViolations').' — '.implode(' ; ', $brViolations), $strict);
				}
				foreach ($brViolations as $v) {
					dol_syslog('AuVo E-Rechnung BR: '.$v, LOG_WARNING);
					$warnings[] = auvoerechnung_trans('AuvoERechnungWarnBrPrefix').' '.$v;
				}
			}
		}

		// Écrire le XML dans un fichier temporaire pour le subprocess d'injection.
		// On écrit dans DOL_DATA_ROOT/facturx/temp/ (toujours dans l'open_basedir
		// Dolibarr) au lieu de sys_get_temp_dir() qui peut tomber hors open_basedir
		// sur Windows (sys temp = C:\WINDOWS\TEMP).
		$xmlTempDir = DOL_DATA_ROOT.'/facturx/temp';
		dol_mkdir($xmlTempDir);
		$this->xmlTmpFile = tempnam($xmlTempDir, 'facturx_');
		if ($this->xmlTmpFile === false) {
			$this->xmlTmpFile = null;
			return $this->handleNonFatal('AuVo E-Rechnung: '.auvoerechnung_trans('AuvoERechnungErrTempDir', $xmlTempDir), $strict);
		}
		if (file_put_contents($this->xmlTmpFile, $xml) === false) {
			@unlink($this->xmlTmpFile);
			$this->xmlTmpFile = null;
			return $this->handleNonFatal('AuVo E-Rechnung: '.auvoerechnung_trans('AuvoERechnungErrTempWrite', $xmlTempDir), $strict);
		}

		try {
			// Injection du XML dans le PDF. Par défaut en IN-PROCESS (sans exec,
			// donc compatible avec les hébergements mutualisés où exec() est
			// désactivé) avec repli automatique sur le sous-process si besoin.
			// Le comportement est réglable via AUVOERECHNUNG_INJECTION_MODE
			// (auto | inprocess | subprocess).
			$inj = $this->injectFacturx($file, $this->xmlTmpFile, $modulePath);
			if (!$inj['ok']) {
				return $this->handleNonFatal('AuVo E-Rechnung: '.auvoerechnung_trans_raw('AuvoERechnungErrInjectFailed').' : '.dol_trunc($inj['error'], 300), $strict);
			}

			// Post-validation PDF/A-3 optionnelle via veraPDF (non bloquante)
			$veraWarning = $this->runVeraPdf($file);
			if ($veraWarning !== null) {
				$warnings[] = $veraWarning;
			}

			// Fonctionnalités Chorus Pro (opt-in via AUVOERECHNUNG_CHORUS_ENABLED).
			if (getDolGlobalInt('AUVOERECHNUNG_CHORUS_ENABLED')) {
				// Activé : génère le PDF Chorus EN PLUS du standard si la facture
				// relève du public. N'altère jamais le PDF principal.
				if (auvoerechnung_is_chorus_invoice($invoice)) {
					$chorus = $this->generateChorusPdf($invoice, $file, $mysoc, $modulePath);
					if (!$chorus['ok']) {
						$warnings[] = $chorus['msg'];
					}
				}
			} elseif (auvoerechnung_is_public_sector_siret($invoice)) {
				// Désactivé mais acheteur public détecté : on informe (sans rien
				// générer), pour suggérer d'activer Chorus Pro si besoin.
				$warnings[] = auvoerechnung_trans('AuvoERechnungChorusSuggested');
			}

			dol_syslog('AuVo E-Rechnung: Factur-X PDF generated for '.$invoice->ref, LOG_INFO);
			$this->reportSuccess($invoice->ref, $warnings);
			return 0;
		} finally {
			if ($this->xmlTmpFile !== null) {
				@unlink($this->xmlTmpFile);
				$this->xmlTmpFile = null;
			}
		}
	}

	/**
	 * Hook afterODTCreation — contexte odtgeneration.
	 *
	 * Injecte le XML Factur-X EN16931 dans le PDF produit à partir d'un MODÈLE ODT
	 * de facture. Le modèle ODT (core/modules/facture/doc/doc_generic_invoice_odt.modules.php)
	 * appelle ce hook APRÈS avoir, le cas échéant, converti le .odt en .pdf via
	 * LibreOffice (exportAsAttachedPDF), donc le PDF est déjà sur le disque ici.
	 *
	 * Le module n'injecte que sur le hook afterPDFCreation des modèles TCPDF natifs
	 * (sponge/crabe/octopus). Les modèles ODT ne tirent que les hooks ODT : sans ce
	 * handler, aucune injection Factur-X n'avait lieu sur une facture générée en ODT.
	 *
	 * IMPORTANT — code 100 % défensif et best-effort : il n'existe PAS de LibreOffice
	 * sur l'infra de développement, ce chemin n'est donc validé qu'en condition réelle
	 * chez l'utilisateur. Toute condition non réunie (conversion PDF désactivée, PDF
	 * introuvable, PDF illisible par FPDI faute de PDF/A) dégrade proprement : on laisse
	 * le PDF ODT classique en place et on émet un AVERTISSEMENT clair, jamais de fatale,
	 * jamais de PDF corrompu (l'injection elle-même est atomique).
	 *
	 * Anti-boucle : ce handler agit uniquement sur le PDF DÉJÀ produit, il ne
	 * redéclenche aucune génération de document (pas de generateDocument()).
	 * Idempotent : une 2e génération ODT réécrase le {ref}.pdf source AVANT ce hook,
	 * l'injection repart donc d'un PDF propre (pas de double-injection cumulative).
	 *
	 * @param array       $parameters  array('odfHandler'=>.., 'file'=>{ref}.odt, 'object'=>Facture, 'outputlangs'=>.., 'substitutionarray'=>..)
	 * @param CommonObject $object     Objet courant (le modèle de doc, non utilisé ici)
	 * @param string      $action      Action courante (non modifiée)
	 * @param HookManager $hookmanager
	 * @return int  0 (toujours non bloquant, sauf -1 explicite en mode strict via handleNonFatal)
	 */
	public function afterODTCreation($parameters, &$object, &$action, $hookmanager)
	{
		global $mysoc, $langs;

		// 1) Périmètre : uniquement les FACTURES. En contexte odtgeneration, le
		// modèle passe l'objet métier dans $parameters['object'] (= le $facture).
		$invoice = $parameters['object'] ?? null;
		if (!is_object($invoice) || !($invoice instanceof Facture)) {
			return 0;
		}

		// Ne jamais injecter sur un brouillon (même raison qu'afterPDFCreation :
		// Dolibarr régénère le document à chaque modif de ligne d'une facture en
		// brouillon ; le Factur-X n'a de sens qu'à la validation). À la validation,
		// le statut passe à VALIDATED AVANT le generateDocument(), l'injection a
		// donc bien lieu à ce moment.
		if ((int) ($invoice->status ?? $invoice->statut ?? 0) === Facture::STATUS_DRAFT) {
			return 0;
		}

		$modulePath = dirname(__DIR__);
		require_once $modulePath.'/core/lib/auvoerechnung.lib.php';
		require_once $modulePath.'/core/lib/auvoerechnung_rules.php';
		if (is_object($langs)) {
			$langs->loadLangs(['auvoerechnung@auvoerechnung']);
		}

		$strict = (int) getDolGlobalInt('AUVOERECHNUNG_STRICT_MODE', 0);

		// 2) Dériver le chemin du PDF produit depuis $parameters['file'] (= le fichier
		// ODF source : .odt le plus souvent, mais aussi .ods/.odx selon le modèle).
		// soffice --convert-to pdf écrit le PDF dans le MÊME dossier en remplaçant la
		// DERNIÈRE extension du fichier source par .pdf (ex {ref}.odt → {ref}.pdf,
		// {ref}_{tpl}.ods → {ref}_{tpl}.pdf). On strippe donc la dernière extension,
		// pas seulement .od(x|t). On NE travaille JAMAIS sur le .odt
		// (MAIN_ODT_AS_PDF_DEL_SOURCE peut l'avoir supprimé) : la cible est le .pdf.
		$odtFile = (string) ($parameters['file'] ?? '');
		if ($odtFile === '') {
			return 0;
		}
		$pdf = preg_replace('/\.[^.\/]+$/', '', $odtFile).'.pdf';

		// 3) Garde best-effort sur la conversion PDF/A. Sans MAIN_ODT_AS_PDF=libreoffice,
		// aucun PDF n'est produit (il n'y a qu'un .odt) → rien à injecter. Sans
		// MAIN_ODT_AS_PDFA, LibreOffice produit un PDF >=1.5 (xref compressé) que le
		// FPDI gratuit refuse ("compression technique not supported") : l'injection
		// échouerait avec une erreur cryptique. On anticipe ces deux cas avec un
		// message dédié et explicite plutôt qu'un échec d'injection opaque.
		$odtAsPdf = strtolower(trim(getDolGlobalString('MAIN_ODT_AS_PDF', '')));
		$odtAsPdfa = (int) getDolGlobalInt('MAIN_ODT_AS_PDFA', 0);
		if ($odtAsPdf !== 'libreoffice') {
			// Conversion ODT→PDF non activée, OU convertisseur qui n'honore pas le
			// PDF/A : seul MAIN_ODT_AS_PDF=libreoffice produit un PDF/A injectable (via
			// le filtre SelectPdfVersion). Sans lui : soit il n'y a qu'un .odt, soit le
			// PDF est >=1.5 illisible par FPDI. On informe sans bloquer.
			return $this->handleNonFatal(
				'AuVo E-Rechnung: '.auvoerechnung_trans('AuvoERechnungOdtErrNoPdfConv'),
				$strict,
				auvoerechnung_trans('AuvoERechnungOdtHintEnable')
			);
		}
		if ($odtAsPdfa < 1) {
			// PDF produit mais probablement >=1.5 : on prévient (best-effort) plutôt
			// que de partir dans une injection vouée à un échec FPDI illisible.
			return $this->handleNonFatal(
				'AuVo E-Rechnung: '.auvoerechnung_trans('AuvoERechnungOdtErrNoPdfa'),
				$strict,
				auvoerechnung_trans('AuvoERechnungOdtHintEnablePdfa')
			);
		}

		// Le PDF dérivé doit exister sur le disque. S'il manque (échec silencieux de
		// la conversion LibreOffice, soffice absent...), on n'invente rien : warning
		// best-effort, on laisse le .odt en place.
		if (!file_exists($pdf)) {
			return $this->handleNonFatal(
				'AuVo E-Rechnung: '.auvoerechnung_trans('AuvoERechnungOdtErrPdfMissing', basename($pdf)),
				$strict,
				auvoerechnung_trans('AuvoERechnungOdtHintEnablePdfa')
			);
		}

		// 4) Préparation de la facture + périmètre supporté (mêmes garde-fous
		// qu'afterPDFCreation : multidevise, taxes locales, date manquante...).
		if (empty($invoice->thirdparty) || !is_object($invoice->thirdparty)) {
			$invoice->fetch_thirdparty();
		}
		if (empty($invoice->thirdparty) || !is_object($invoice->thirdparty)) {
			return $this->handleNonFatal('AuVo E-Rechnung: '.auvoerechnung_trans('AuvoERechnungErrNoThirdparty'), $strict);
		}
		if (empty($mysoc) || !is_object($mysoc) || empty($mysoc->name)) {
			return $this->handleNonFatal('AuVo E-Rechnung: '.auvoerechnung_trans('AuvoERechnungErrNoMysoc'), $strict);
		}
		if (empty($invoice->lines)) {
			$invoice->fetch_lines();
		}

		$unsupported = auvoerechnung_check_supported($invoice);
		if ($unsupported !== null) {
			return $this->handleNonFatal('AuVo E-Rechnung: '.$unsupported, $strict, auvoerechnung_trans('AuvoERechnungHintPdfKept'));
		}

		// 5) Construction + validation du XML (réutilise intégralement le pipeline,
		// profil PDP par défaut — appel sans $options, comme le PDF EN16931 standard).
		$warnings = auvoerechnung_check_mandatory($invoice, $mysoc);

		$buildWarnings = [];
		$xml = auvoerechnung_build_xml($invoice, $mysoc, $buildWarnings);
		$warnings = array_merge($warnings, $buildWarnings);

		foreach ($warnings as $w) {
			dol_syslog('AuVo E-Rechnung WARNING (ODT): '.$w, LOG_WARNING);
		}

		$validationError = $this->validateXml($xml, $modulePath);
		if ($validationError !== null) {
			return $this->handleNonFatal('AuVo E-Rechnung: '.auvoerechnung_trans('AuvoERechnungErrInvalidXml').' : '.$validationError, $strict, auvoerechnung_trans('AuvoERechnungHintPdfKept'));
		}

		if (getDolGlobalInt('AUVOERECHNUNG_BR_CHECK', 1)) {
			$brViolations = auvoerechnung_validate_business_rules($xml);
			if (!empty($brViolations)) {
				if ($strict) {
					return $this->handleNonFatal('AuVo E-Rechnung: '.auvoerechnung_trans('AuvoERechnungErrBrViolations').' — '.implode(' ; ', $brViolations), $strict);
				}
				foreach ($brViolations as $v) {
					dol_syslog('AuVo E-Rechnung BR (ODT): '.$v, LOG_WARNING);
					$warnings[] = auvoerechnung_trans('AuvoERechnungWarnBrPrefix').' '.$v;
				}
			}
		}

		// 6) Écriture du XML temporaire (même dossier que le reste : toujours dans
		// l'open_basedir Dolibarr).
		$xmlTempDir = DOL_DATA_ROOT.'/facturx/temp';
		dol_mkdir($xmlTempDir);
		$this->xmlTmpFile = tempnam($xmlTempDir, 'facturx_');
		if ($this->xmlTmpFile === false) {
			$this->xmlTmpFile = null;
			return $this->handleNonFatal('AuVo E-Rechnung: '.auvoerechnung_trans('AuvoERechnungErrTempDir', $xmlTempDir), $strict);
		}
		if (file_put_contents($this->xmlTmpFile, $xml) === false) {
			@unlink($this->xmlTmpFile);
			$this->xmlTmpFile = null;
			return $this->handleNonFatal('AuVo E-Rechnung: '.auvoerechnung_trans('AuvoERechnungErrTempWrite', $xmlTempDir), $strict);
		}

		try {
			// 7) Injection (réutilise EXACTEMENT injectFacturx : mode auto =
			// sous-process si exec() dispo, sinon in-process avec garde-fou
			// detectInProcessPdfConflict). Aucune spécificité ODT à coder : le
			// conflit FPDF/tcpdi est géré à l'identique dans les deux contextes.
			$inj = $this->injectFacturx($pdf, $this->xmlTmpFile, $modulePath);
			if (!$inj['ok']) {
				return $this->handleNonFatal(
					'AuVo E-Rechnung: '.auvoerechnung_trans_raw('AuvoERechnungErrInjectFailed').' : '.dol_trunc($inj['error'], 300),
					$strict,
					auvoerechnung_trans('AuvoERechnungOdtHintEnablePdfa')
				);
			}

			// Post-validation PDF/A-3 optionnelle via veraPDF (non bloquante).
			// NB : LibreOffice produit déjà un PDF/A-1b/2b/3b selon MAIN_ODT_AS_PDFA ;
			// veraPDF peut signaler un écart de niveau PDF/A — on le garde non bloquant.
			$veraWarning = $this->runVeraPdf($pdf);
			if ($veraWarning !== null) {
				$warnings[] = $veraWarning;
			}

			// Chorus Pro (opt-in) : même logique que le flux TCPDF, le 2e PDF est
			// copié depuis ce {ref}.pdf déjà injecté.
			if (getDolGlobalInt('AUVOERECHNUNG_CHORUS_ENABLED')) {
				if (auvoerechnung_is_chorus_invoice($invoice)) {
					$chorus = $this->generateChorusPdf($invoice, $pdf, $mysoc, $modulePath);
					if (!$chorus['ok']) {
						$warnings[] = $chorus['msg'];
					}
				}
			} elseif (auvoerechnung_is_public_sector_siret($invoice)) {
				$warnings[] = auvoerechnung_trans('AuvoERechnungChorusSuggested');
			}

			dol_syslog('AuVo E-Rechnung: Factur-X PDF (ODT) generated for '.$invoice->ref, LOG_INFO);
			$this->reportSuccess($invoice->ref, $warnings);
			return 0;
		} finally {
			if ($this->xmlTmpFile !== null) {
				@unlink($this->xmlTmpFile);
				$this->xmlTmpFile = null;
			}
		}
	}

	/**
	 * Génère un SECOND PDF Factur-X au profil Chorus Pro (B2G) à côté du PDF
	 * principal : copie `{ref}-CHORUS.pdf` dans le même répertoire documents, avec
	 * un XML où l'identifiant légal porte le SIRET-14 (clé de routage Chorus Pro)
	 * et les champs BT-10/12/13 (code service, marché, engagement).
	 *
	 * Non bloquant : un échec ici ne touche jamais le PDF EN16931 standard déjà
	 * généré. Retourne un message d'avertissement à afficher, ou null si OK.
	 *
	 * @param Facture $invoice
	 * @param string  $mainPdf     Chemin du PDF principal (source de la copie)
	 * @param Societe $mysoc
	 * @param string  $modulePath
	 * @return array{ok:bool,msg:string}
	 */
	protected function generateChorusPdf($invoice, $mainPdf, $mysoc, $modulePath)
	{
		$options = auvoerechnung_chorus_options($invoice);

		$cw = [];
		$chorusXml = auvoerechnung_build_xml($invoice, $mysoc, $cw, $options);

		// Le PDF Chorus doit être aussi valide que le principal : on bloque la
		// copie si le XML profil Chorus ne passe pas le XSD.
		$xsdError = $this->validateXml($chorusXml, $modulePath);
		if ($xsdError !== null) {
			dol_syslog('AuVo E-Rechnung Chorus: invalid XML for '.$invoice->ref.': '.$xsdError, LOG_ERR);
			return array('ok' => false, 'msg' => auvoerechnung_trans('AuvoERechnungChorusErr'));
		}

		// Copie {ref}-CHORUS.pdf dans le même dossier que le PDF principal.
		$chorusPdf = preg_replace('/\.pdf$/i', '', $mainPdf).'-CHORUS.pdf';
		if (!@copy($mainPdf, $chorusPdf)) {
			dol_syslog('AuVo E-Rechnung Chorus: unable to copy PDF to '.$chorusPdf, LOG_ERR);
			return array('ok' => false, 'msg' => auvoerechnung_trans('AuvoERechnungChorusErr'));
		}

		$xmlTempDir = DOL_DATA_ROOT.'/facturx/temp';
		dol_mkdir($xmlTempDir);
		$tmp = tempnam($xmlTempDir, 'facturxchorus_');
		if ($tmp === false || file_put_contents($tmp, $chorusXml) === false) {
			if ($tmp !== false) { @unlink($tmp); }
			@unlink($chorusPdf);
			dol_syslog('AuVo E-Rechnung Chorus: unable to write temporary XML', LOG_ERR);
			return array('ok' => false, 'msg' => auvoerechnung_trans('AuvoERechnungChorusErr'));
		}

		try {
			// Même chemin d'injection que le PDF principal (in-process + repli).
			$inj = $this->injectFacturx($chorusPdf, $tmp, $modulePath);
			if (!$inj['ok']) {
				@unlink($chorusPdf);
				dol_syslog('AuVo E-Rechnung Chorus: embedding failed: '.dol_trunc($inj['error'], 300), LOG_ERR);
				return array('ok' => false, 'msg' => auvoerechnung_trans('AuvoERechnungChorusErr'));
			}
		} finally {
			@unlink($tmp);
		}

		dol_syslog('AuVo E-Rechnung: Chorus PDF generated for '.$invoice->ref.' ('.basename($chorusPdf).')', LOG_INFO);
		return array('ok' => true, 'msg' => auvoerechnung_trans('AuvoERechnungChorusGenerated', basename($chorusPdf)));
	}

	/**
	 * Génère le PDF Chorus à la demande (action du menu déroulant), depuis le
	 * PDF principal existant. Affiche le résultat en message.
	 */
	protected function generateChorusOnDemand($invoice)
	{
		global $mysoc, $langs;

		$modulePath = dirname(__DIR__);
		require_once $modulePath.'/core/lib/auvoerechnung.lib.php';
		require_once $modulePath.'/core/lib/auvoerechnung_rules.php';

		if (empty($invoice->thirdparty) || !is_object($invoice->thirdparty)) {
			$invoice->fetch_thirdparty();
		}
		if (empty($invoice->lines)) {
			$invoice->fetch_lines();
		}

		$mainPdf = $this->getInvoicePdfPath($invoice);
		if ($mainPdf === null || !file_exists($mainPdf)) {
			setEventMessages($langs->trans('AuvoERechnungMsgNoPdf'), null, 'warnings');
			return;
		}
		$res = $this->generateChorusPdf($invoice, $mainPdf, $mysoc, $modulePath);
		setEventMessages($res['msg'], null, $res['ok'] ? 'mesgs' : 'warnings');
	}

	/**
	 * Rend un menu déroulant « bouton + liste » autonome (élément <details>,
	 * sans dépendance JS framework). $items = [['href'=>..,'label'=>..], ...].
	 *
	 * @param string $label
	 * @param array  $items
	 * @return string
	 */
	protected function renderDropdown($label, array $items)
	{
		$html  = '<div class="inline-block valignmiddle lfx-dd-wrap" style="position:relative;">';
		$html .= '<details class="lfx-dropdown">';
		$html .= '<summary class="butAction" style="list-style:none;cursor:pointer;">'.dol_escape_htmltag($label).' <span style="font-size:.75em;">&#9662;</span></summary>';
		$html .= '<div class="lfx-dropdown-menu" style="position:absolute;right:0;top:100%;z-index:1000;background:#fff;border:1px solid #bbb;border-radius:5px;box-shadow:0 3px 12px rgba(0,0,0,.18);min-width:215px;margin-top:3px;overflow:hidden;">';
		foreach ($items as $it) {
			$html .= '<a href="'.dol_escape_htmltag($it['href']).'" style="display:block;padding:9px 16px;white-space:nowrap;color:#444;text-decoration:none;border-bottom:1px solid #f1f1f1;">'.dol_escape_htmltag($it['label']).'</a>';
		}
		$html .= '</div></details>';
		$html .= '<style>.lfx-dropdown summary::-webkit-details-marker{display:none;}.lfx-dropdown-menu a:hover{background:#f5f5f5;}.lfx-dropdown-menu a:last-child{border-bottom:none;}</style>';
		$html .= '</div>';
		return $html;
	}

	/**
	 * Hook addMoreActionsButtons — contexte invoicecard.
	 * Ajoute les boutons "Vérifier Factur-X" / "Régénérer Factur-X" sur la fiche facture.
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;

		$contexts = explode(':', $parameters['context'] ?? '');
		if (!in_array('invoicecard', $contexts, true)) {
			return 0;
		}
		if (!is_object($object) || !($object instanceof Facture)) {
			return 0;
		}
		$status = (int) ($object->status ?? $object->statut ?? 0);
		if ($status < 1) {
			return 0; // brouillon : pas encore de PDF définitif
		}
		if (is_object($langs)) {
			$langs->loadLangs(['auvoerechnung@auvoerechnung']);
		}

		// Menu déroulant unique « Factur-X ▾ » regroupant les actions du module
		// (et l'envoi via SUPER PDP si ce module compagnon est actif).
		$id = (int) $object->id;
		$url = $_SERVER['PHP_SELF'].'?facid='.$id.'&token='.newToken();
		$canRead = $this->userCanRead($user);
		$canWrite = $this->userCanWrite($user);

		// Le menu ne porte que les actions de CE module. L'envoi via SUPER PDP
		// reste géré par son propre module (bouton intelligent, état B2C/déjà
		// transmise...) : pas de couplage inverse FacturX → SuperPDP.
		$items = array();
		if ($canRead) {
			$items[] = array('href' => $url.'&action=auvoerechnung_verify', 'label' => $langs->trans('AuvoERechnungBtnVerify'));
		}
		if ($canWrite) {
			$items[] = array('href' => $url.'&action=auvoerechnung_regenerate', 'label' => $langs->trans('AuvoERechnungBtnRegenerate'));
			if (getDolGlobalInt('AUVOERECHNUNG_CHORUS_ENABLED')) {
				$items[] = array('href' => $url.'&action=auvoerechnung_generatechorus', 'label' => $langs->trans('AuvoERechnungBtnGenerateChorus'));
			}
		}

		if (!empty($items)) {
			print $this->renderDropdown($langs->trans('AuvoERechnungMenuLabel'), $items);
		}

		return 0;
	}

	/**
	 * Hook doActions — contexte invoicecard.
	 * Traite les actions auvoerechnung_verify / auvoerechnung_regenerate.
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs, $mysoc;

		$contexts = explode(':', $parameters['context'] ?? '');
		if (!in_array('invoicecard', $contexts, true)) {
			return 0;
		}
		if (!in_array($action, ['auvoerechnung_verify', 'auvoerechnung_regenerate', 'auvoerechnung_generatechorus'], true)) {
			return 0;
		}
		if (!is_object($object) || !($object instanceof Facture) || empty($object->id)) {
			return 0;
		}
		if (GETPOST('token', 'alpha') !== currentToken()) {
			setEventMessages('Bad value for CSRF token', null, 'errors');
			return 0;
		}
		if (is_object($langs)) {
			$langs->loadLangs(['auvoerechnung@auvoerechnung']);
		}

		if ($action === 'auvoerechnung_regenerate') {
			if (!$this->userCanWrite($user)) {
				setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
				return 0;
			}
			// La régénération du PDF re-déclenche afterPDFCreation (et donc
			// l'injection Factur-X) : c'est le chemin nominal du module.
			// Mêmes conventions que l'action builddoc du cœur : langue du tiers
			// (MAIN_MULTILANGS) et flags de masquage, pour ne pas écraser le PDF
			// client avec une version dans la langue de l'agent.
			$model = !empty($object->model_pdf) ? $object->model_pdf : getDolGlobalString('FACTURE_ADDON_PDF', 'sponge');
			if (empty($object->thirdparty) || !is_object($object->thirdparty)) {
				$object->fetch_thirdparty();
			}
			$outputlangs = $langs;
			if (getDolGlobalInt('MAIN_MULTILANGS') && !empty($object->thirdparty->default_lang)) {
				$outputlangs = new Translate('', $conf);
				$outputlangs->setDefaultLang($object->thirdparty->default_lang);
			}
			$hidedetails = getDolGlobalInt('MAIN_GENERATE_DOCUMENTS_HIDE_DETAILS');
			$hidedesc = getDolGlobalInt('MAIN_GENERATE_DOCUMENTS_HIDE_DESC');
			$hideref = getDolGlobalInt('MAIN_GENERATE_DOCUMENTS_HIDE_REF');
			$res = $object->generateDocument($model, $outputlangs, $hidedetails, $hidedesc, $hideref);
			if ($res <= 0) {
				setEventMessages($langs->trans('AuvoERechnungMsgRegenerateFailed').' : '.$object->error, null, 'errors');
			}
			// Les messages de succès/avertissement sont émis par afterPDFCreation
			$action = '';
			return 0;
		}

		if ($action === 'auvoerechnung_generatechorus') {
			if (!$this->userCanWrite($user)) {
				setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
				return 0;
			}
			$this->generateChorusOnDemand($object);
			$action = '';
			return 0;
		}

		// auvoerechnung_verify : extraire le XML embarqué du PDF et le re-valider
		if (!$this->userCanRead($user)) {
			setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
			return 0;
		}
		$this->verifyInvoicePdf($object);
		$action = '';
		return 0;
	}

	/**
	 * Vérifie le PDF de la facture : présence d'un XML Factur-X embarqué,
	 * validation XSD + règles métier. Affiche le résultat en event message.
	 *
	 * PUBLIQUE : appelée aussi depuis l'extérieur du hook — par des modules
	 * tiers (ex. un onglet « Facturation électronique » d'un module PDP), qui
	 * vérifient la visibilité par réflexion avant l'appel.
	 * Ne PAS repasser en protected : cela casserait ces intégrations externes.
	 */
	public function verifyInvoicePdf($invoice)
	{
		global $conf, $langs;

		$modulePath = dirname(__DIR__);
		require_once $modulePath.'/core/lib/auvoerechnung.lib.php';
		require_once $modulePath.'/core/lib/auvoerechnung_rules.php';

		$pdfPath = $this->getInvoicePdfPath($invoice);
		if ($pdfPath === null) {
			setEventMessages($langs->trans('AuvoERechnungMsgNoPdf'), null, 'warnings');
			return;
		}

		$xml = auvoerechnung_extract_xml_from_pdf($pdfPath);
		if ($xml === null) {
			setEventMessages($langs->trans('AuvoERechnungMsgNoFacturX', basename($pdfPath)), null, 'warnings');
			return;
		}

		$problems = [];
		$xsdError = $this->validateXml($xml, $modulePath);
		if ($xsdError !== null) {
			$problems[] = 'XSD : '.$xsdError;
		}
		if (getDolGlobalInt('AUVOERECHNUNG_BR_CHECK', 1)) {
			$problems = array_merge($problems, auvoerechnung_validate_business_rules($xml));
		}

		if (empty($problems)) {
			setEventMessages($langs->trans('AuvoERechnungMsgVerifyOk', basename($pdfPath)), null, 'mesgs');
			return;
		}
		$msg = $langs->trans('AuvoERechnungMsgVerifyKo', basename($pdfPath)).'<br><ul style="margin:4px 0 0 0;padding-left:20px;">';
		foreach ($problems as $p) {
			$msg .= '<li>'.dol_escape_htmltag($p).'</li>';
		}
		$msg .= '</ul>';
		setEventMessages($msg, null, 'warnings');
	}

	/**
	 * Chemin du PDF principal de la facture, ou null s'il n'existe pas.
	 */
	protected function getInvoicePdfPath($invoice)
	{
		global $conf;

		$entity = $invoice->entity ?? $conf->entity;
		return auvoerechnung_invoice_pdf_path($invoice->ref, $entity, (string) ($invoice->last_main_doc ?? ''));
	}

	/**
	 * Post-validation PDF/A-3 via veraPDF si AUVOERECHNUNG_VERAPDF_PATH est
	 * configuré. Renvoie un warning à afficher, ou null si OK / non configuré.
	 */
	protected function runVeraPdf($pdfFile)
	{
		global $langs;

		$veraPath = trim(getDolGlobalString('AUVOERECHNUNG_VERAPDF_PATH', ''));
		if ($veraPath === '' || !function_exists('exec')) {
			return null;
		}
		if (!is_executable($veraPath)) {
			return auvoerechnung_trans('AuvoERechnungWarnVeraPdfNotFound', $veraPath);
		}

		$cmd = escapeshellarg($veraPath).' -f 3b --format text '.escapeshellarg($pdfFile).' 2>&1';
		// veraPDF est une CLI JVM : borne de 60s pour qu'un process bloqué ne
		// gèle pas la requête web (max_execution_time ne compte pas l'exec).
		if (is_executable('/usr/bin/timeout')) {
			$cmd = '/usr/bin/timeout 60 '.$cmd;
		}
		$output = [];
		$returnCode = 0;
		exec($cmd, $output, $returnCode);
		$text = implode("\n", $output);

		// veraPDF (format text) : "PASS file.pdf" / "FAIL file.pdf"
		if (strpos($text, 'PASS') === 0 || preg_match('/^PASS\b/m', $text)) {
			dol_syslog('AuVo E-Rechnung: veraPDF PASS for '.$pdfFile, LOG_INFO);
			return null;
		}
		dol_syslog('AuVo E-Rechnung: veraPDF FAIL for '.$pdfFile.': '.dol_trunc($text, 500), LOG_WARNING);
		return auvoerechnung_trans('AuvoERechnungWarnVeraPdfFailed');
	}

	/**
	 * Droit de lecture des factures (compatible anciennes/nouvelles versions Dolibarr).
	 */
	protected function userCanRead($user)
	{
		if (method_exists($user, 'hasRight')) {
			return (bool) $user->hasRight('facture', 'lire');
		}
		return !empty($user->rights->facture->lire);
	}

	/**
	 * Droit de création/modification des factures.
	 */
	protected function userCanWrite($user)
	{
		if (method_exists($user, 'hasRight')) {
			return (bool) $user->hasRight('facture', 'creer');
		}
		return !empty($user->rights->facture->creer);
	}

	/**
	 * Résout et valide le binaire PHP CLI configuré (AUVOERECHNUNG_PHP_CLI_PATH).
	 * Retourne null en cas d'échec après avoir remonté le message via handleNonFatal().
	 *
	 * @param int $strict
	 * @return string|null
	 */
	protected function resolvePhpBinary($strict)
	{
		// Sans surcharge manuelle, on auto-détecte le binaire CLI (avec cache) :
		// version major.minor du web, en évitant le piège PHP_BINARY=php-fpm en FPM.
		// 'php' nu (ancien défaut résiduel des activations passées) = pas une vraie
		// surcharge → auto-détection aussi.
		$manual = trim(getDolGlobalString('AUVOERECHNUNG_PHP_CLI_PATH', ''));
		if ($manual === '' || $manual === 'php') {
			return auvoerechnung_resolve_php_cli($this->db);
		}

		$phpBin = getDolGlobalString('AUVOERECHNUNG_PHP_CLI_PATH', 'php');

		// Hardening : la constante est modifiable par un admin via /admin/const.php.
		// escapeshellarg() sur la commande bloque déjà toute injection shell (une
		// valeur piégée finit en "command not found"), mais on refuse explicitement
		// les valeurs avec caractères exotiques pour éviter les fautes de frappe
		// qui partiraient en boucle d'erreur et pour afficher un message clair.
		// `:`, `\`, `(`, `)`, espace autorisés pour les chemins Windows
		// (ex C:\Program Files\php\php.exe).
		if (!preg_match('#^[A-Za-z0-9/._:() \\\\-]+$#', $phpBin)) {
			dol_syslog('AuVo E-Rechnung: AUVOERECHNUNG_PHP_CLI_PATH value received: '.$phpBin, LOG_ERR);
			$this->handleNonFatal('AuVo E-Rechnung: '.auvoerechnung_trans('AuvoERechnungErrPhpCliChars'), $strict);
			return null;
		}

		// Si l'admin a fourni un chemin absolu, on vérifie qu'il pointe vraiment
		// vers un exécutable. Cas relatif ("php", "php8.2") : on laisse passer
		// au shell qui résoudra via PATH.
		if (strpos($phpBin, '/') !== false && !is_executable($phpBin)) {
			$this->handleNonFatal('AuVo E-Rechnung: '.auvoerechnung_trans('AuvoERechnungErrPhpCliNotFound', $phpBin), $strict);
			return null;
		}

		return $phpBin;
	}

	/**
	 * Injecte le XML Factur-X dans le PDF selon le mode configuré
	 * (AUVOERECHNUNG_INJECTION_MODE) :
	 *  - 'auto' (défaut)  : in-process, puis repli sur le sous-process si échec ;
	 *  - 'inprocess'      : in-process uniquement (jamais d'exec) ;
	 *  - 'subprocess'     : sous-process uniquement (comportement historique).
	 *
	 * @param string $pdfPath    PDF à modifier sur place
	 * @param string $xmlPath    Fichier XML temporaire à injecter
	 * @param string $modulePath Racine du module
	 * @return array{ok:bool,method:string,error:string}
	 */
	protected function injectFacturx($pdfPath, $xmlPath, $modulePath)
	{
		$mode = strtolower(trim(getDolGlobalString('AUVOERECHNUNG_INJECTION_MODE', 'auto')));
		if (!in_array($mode, array('auto', 'inprocess', 'subprocess'), true)) {
			$mode = 'auto';
		}

		// Forçages explicites.
		if ($mode === 'subprocess') {
			return $this->injectSubprocess($pdfPath, $xmlPath, $modulePath);
		}
		if ($mode === 'inprocess') {
			return $this->injectInProcess($pdfPath, $xmlPath, $modulePath);
		}

		// Mode AUTO. Le sous-process (process PHP isolé) est le chemin SÛR : aucun
		// conflit de bibliothèques possible. L'in-process, lui, peut déclencher une
		// FATAL NON RATTRAPABLE quand un autre composant (Dolibarr tcpdi, un autre
		// module embarquant setasign/fpdi) a déjà chargé une version incompatible
		// de FPDF/FPDI — c'est une erreur de COMPILATION que le try/catch ne capture
		// pas (vu en prod : FpdfTplTrait::setPageFormat). On réserve donc l'in-process
		// au SEUL cas où exec() est indisponible (mutualisé durci), là où il n'y a
		// pas d'alternative.
		if (function_exists('exec')) {
			return $this->injectSubprocess($pdfPath, $xmlPath, $modulePath);
		}
		return $this->injectInProcess($pdfPath, $xmlPath, $modulePath);
	}

	/**
	 * Injection IN-PROCESS : appelle directement la lib d'injection dans le
	 * process web courant, sans exec(). Validé sans conflit FPDF/TCPDF (le PDF
	 * facture utilise TCPDF, la lib un FPDF setasign qui cohabite). Écriture
	 * atomique. Ne lève jamais : retourne le statut.
	 *
	 * @param string $pdfPath
	 * @param string $xmlPath
	 * @param string $modulePath
	 * @return array{ok:bool,method:string,error:string}
	 */
	protected function injectInProcess($pdfPath, $xmlPath, $modulePath)
	{
		try {
			$pdfContent = @file_get_contents($pdfPath);
			$xmlContent = @file_get_contents($xmlPath);
			if ($pdfContent === false || $xmlContent === false) {
				return array('ok' => false, 'method' => 'inprocess', 'error' => auvoerechnung_trans_raw('AuvoERechnungErrInjReadSource'));
			}

			// Garantit un minimum mémoire pour le parsing PDF en mémoire
			// (best-effort, ne baisse jamais une limite déjà plus haute).
			$this->ensureMemoryLimit(256 * 1024 * 1024);

			require_once $modulePath.'/vendor/autoload.php';
			if (!class_exists('\\Atgp\\FacturX\\Writer')) {
				return array('ok' => false, 'method' => 'inprocess', 'error' => auvoerechnung_trans_raw('AuvoERechnungErrInjLibMissing'));
			}

			// Garde-fou anti-FATAL : si une classe FPDF/FPDI est déjà chargée par
			// un AUTRE composant que notre vendor, instancier la lib d'injection
			// déclencherait une erreur de COMPILATION non rattrapable (le
			// try/catch ci-dessus ne la capturerait pas). On détecte et on sort
			// proprement — en mode auto, l'appelant repassera par le sous-process.
			$conflict = $this->detectInProcessPdfConflict($modulePath);
			if ($conflict !== null) {
				return array('ok' => false, 'method' => 'inprocess', 'error' => $conflict);
			}

			// AFRelationship 'Alternative' : imposé par la spec pour EN16931
			// (cf scripts/inject_facturx.php).
			$writer = new \Atgp\FacturX\Writer();
			$facturxPdf = $writer->generate($pdfContent, $xmlContent, 'en16931', false, array(), false, 'Alternative');
			if (!is_string($facturxPdf) || $facturxPdf === '') {
				return array('ok' => false, 'method' => 'inprocess', 'error' => auvoerechnung_trans_raw('AuvoERechnungErrInjEmptyOutput'));
			}

			// Écriture atomique : le PDF d'origine n'est remplacé qu'une fois le
			// nouveau fichier intégralement écrit.
			$tmpPath = $pdfPath.'.facturx.tmp';
			if (file_put_contents($tmpPath, $facturxPdf) !== strlen($facturxPdf)) {
				@unlink($tmpPath);
				return array('ok' => false, 'method' => 'inprocess', 'error' => auvoerechnung_trans_raw('AuvoERechnungErrInjWriteOutput'));
			}
			if (!@rename($tmpPath, $pdfPath)) {
				@unlink($tmpPath);
				return array('ok' => false, 'method' => 'inprocess', 'error' => auvoerechnung_trans_raw('AuvoERechnungErrInjReplacePdf'));
			}

			return array('ok' => true, 'method' => 'inprocess', 'error' => '');
		} catch (\Throwable $e) {
			return array('ok' => false, 'method' => 'inprocess', 'error' => $e->getMessage());
		}
	}

	/**
	 * Détecte si une classe FPDF/FPDI est déjà chargée par un composant AUTRE
	 * que le vendor du module (le tcpdi de Dolibarr, un autre module embarquant
	 * setasign/fpdi…). Dans ce cas, instancier la lib d'injection in-process
	 * provoque une FATAL de compilation non rattrapable (« Declaration … must be
	 * compatible », typiquement FpdfTplTrait::setPageFormat). On l'évite donc en
	 * amont.
	 *
	 * @param string $modulePath
	 * @return string|null  message de conflit, ou null si aucun conflit
	 */
	protected function detectInProcessPdfConflict($modulePath)
	{
		$ourVendor = str_replace('\\', '/', $modulePath).'/vendor/setasign/';

		// Cas principal : la classe parente de notre FpdfTpl est la classe GLOBALE
		// \FPDF. Or Dolibarr fournit lui aussi un \FPDF — includes/tcpdi/tcpdi.php :
		// « class FPDF extends TCPDF {} ». On FORCE la résolution de \FPDF (autoload
		// autorisé pour la PARENTE — surtout pas pour FpdfTpl/Fpdi, dont le
		// chargement est justement ce qui déclencherait la fatale) et on regarde qui
		// gagne l'autoload :
		//  - notre setasign/fpdf            → in-process sûr (on laisse passer) ;
		//  - le FPDF-sur-TCPDF de Dolibarr  → conflit garanti (setPageFormat
		//    incompatible) → on renonce proprement à l'in-process.
		if (class_exists('FPDF', true)) {
			try {
				$file = (new \ReflectionClass('FPDF'))->getFileName();
			} catch (\Throwable $e) {
				$file = false;
			}
			if ($file !== false && strpos(str_replace('\\', '/', $file), $ourVendor) === false) {
				return function_exists('auvoerechnung_trans')
					? auvoerechnung_trans('AuvoERechnungErrTcpdiConflict', basename($file))
					: 'Conflit FPDF/TCPDF (tcpdi de Dolibarr, '.basename($file).') : injection in-process impossible. Activez exec() (mode sous-process) ou posez MAIN_DISABLE_TCPDI=1.';
			}
		}

		// Cas secondaire : une classe FPDI setasign déjà chargée par un AUTRE
		// composant (autoload=false : on ne force PAS le chargement de ces enfants).
		foreach (array('setasign\\Fpdi\\FpdfTpl', 'setasign\\Fpdi\\Fpdi') as $cls) {
			if (!class_exists($cls, false)) {
				continue;
			}
			try {
				$file = (new \ReflectionClass($cls))->getFileName();
			} catch (\Throwable $e) {
				continue;
			}
			if ($file !== false && strpos(str_replace('\\', '/', $file), $ourVendor) === false) {
				return auvoerechnung_trans_raw('AuvoERechnungErrInjFpdiConflict', $cls, basename($file));
			}
		}
		return null;
	}

	/**
	 * Injection via SOUS-PROCESS PHP CLI (comportement historique, isolé du
	 * process web). Nécessite exec() et un binaire PHP CLI valide.
	 *
	 * @param string $pdfPath
	 * @param string $xmlPath
	 * @param string $modulePath
	 * @return array{ok:bool,method:string,error:string}
	 */
	protected function injectSubprocess($pdfPath, $xmlPath, $modulePath)
	{
		if (!function_exists('exec')) {
			return array('ok' => false, 'method' => 'subprocess', 'error' => auvoerechnung_trans('AuvoERechnungErrNoExec'));
		}
		$phpBin = $this->resolvePhpBinary(0);
		if ($phpBin === null) {
			return array('ok' => false, 'method' => 'subprocess', 'error' => auvoerechnung_trans_raw('AuvoERechnungErrInjNoPhpCli'));
		}

		$cmd  = escapeshellarg($phpBin);
		$cmd .= ' '.escapeshellarg($modulePath.'/scripts/inject_facturx.php');
		$cmd .= ' '.escapeshellarg($pdfPath);
		$cmd .= ' '.escapeshellarg($xmlPath);
		$cmd .= ' 2>&1';

		$output = array();
		$rc = 0;
		exec($cmd, $output, $rc);
		if ($rc !== 0) {
			return array('ok' => false, 'method' => 'subprocess', 'error' => dol_trunc(implode(' ', $output), 300));
		}
		return array('ok' => true, 'method' => 'subprocess', 'error' => '');
	}

	/**
	 * Relève (best-effort) memory_limit au minimum demandé, sans jamais la
	 * baisser. No-op si la limite est illimitée (-1) ou si ini_set est bridé.
	 *
	 * @param int $minBytes
	 * @return void
	 */
	protected function ensureMemoryLimit($minBytes)
	{
		$cur = @ini_get('memory_limit');
		if ($cur === false || $cur === '' || (string) $cur === '-1') {
			return; // illimité ou inconnu : on ne touche à rien
		}
		$val = trim($cur);
		$unit = strtolower(substr($val, -1));
		$num = (float) $val;
		$bytes = $num;
		if ($unit === 'g') {
			$bytes = $num * 1024 * 1024 * 1024;
		} elseif ($unit === 'm') {
			$bytes = $num * 1024 * 1024;
		} elseif ($unit === 'k') {
			$bytes = $num * 1024;
		}
		if ($bytes > 0 && $bytes < $minBytes) {
			@ini_set('memory_limit', (string) $minBytes);
		}
	}

	/**
	 * Affiche le message final consolidé (vert si aucun warning, orange avec la liste sinon).
	 *
	 * @param string $invoiceRef
	 * @param array  $warnings
	 */
	protected function reportSuccess($invoiceRef, array $warnings)
	{
		// Pas de session interactive (API REST, CLI, cron) : log uniquement
		if (!function_exists('setEventMessages') || (php_sapi_name() === 'cli')) {
			return;
		}

		$safeRef = dol_escape_htmltag($invoiceRef);
		if (empty($warnings)) {
			setEventMessages(auvoerechnung_trans('AuvoERechnungMsgSuccess', $safeRef), null, 'mesgs');
			return;
		}

		$msg  = auvoerechnung_trans('AuvoERechnungMsgSuccessWithWarnings', $safeRef, count($warnings)).'<br>';
		$msg .= '<ul style="margin:4px 0 0 0;padding-left:20px;">';
		foreach ($warnings as $w) {
			$msg .= '<li>'.dol_escape_htmltag($w).'</li>';
		}
		$msg .= '</ul>';
		setEventMessages($msg, null, 'warnings');
	}

	/**
	 * Centralise le traitement d'une erreur non fatale du hook :
	 *  - mode strict : remonte une erreur bloquante et renvoie -1
	 *  - mode best-effort : affiche un warning, laisse le PDF classique en place, renvoie 0
	 *
	 * Note : même en mode strict, le PDF classique déjà généré par Dolibarr
	 * reste sur le disque (le hook intervient après sa création). Le mode
	 * strict bloque le retour utilisateur, pas l'existence du fichier.
	 *
	 * @param string $msg           Message d'erreur (sera loggué + affiché)
	 * @param int    $strict        0 = best-effort, 1 = strict bloquant
	 * @param string $fallbackHint  Précision affichée en best-effort entre parenthèses
	 * @return int                  -1 (strict) ou 0 (best-effort)
	 */
	protected function handleNonFatal($msg, $strict, $fallbackHint = '')
	{
		dol_syslog($msg, LOG_ERR);
		if ($fallbackHint === '') {
			$fallbackHint = auvoerechnung_trans('AuvoERechnungHintPdfKeptShort');
		}
		$interactive = function_exists('setEventMessages') && php_sapi_name() !== 'cli';
		if ($strict) {
			$this->error = $msg;
			$this->errors[] = $msg;
			if ($interactive) {
				setEventMessages($msg, null, 'errors');
			}
			return -1;
		}
		if ($interactive) {
			setEventMessages($msg.' ('.auvoerechnung_trans('AuvoERechnungHintBestEffort').' : '.$fallbackHint.')', null, 'warnings');
		}
		return 0;
	}

	/**
	 * Valide le XML généré avant injection PDF.
	 * Étape 1 : well-formed (évite les crash de la lib d'injection sur XML cassé)
	 * Étape 2 : conformité XSD Factur-X EN16931 (signale les erreurs structurelles
	 *          avant qu'elles n'arrivent chez un destinataire ou un validateur externe)
	 *
	 * @param string $xml         XML à valider
	 * @param string $modulePath  Racine du module (pour localiser le XSD embarqué)
	 * @return string|null        Message d'erreur si invalide, null si OK
	 */
	protected function validateXml($xml, $modulePath)
	{
		// Implémentation partagée avec les suites de tests : les tests valident
		// le même code que la production (cf. core/lib/auvoerechnung_rules.php).
		require_once $modulePath.'/core/lib/auvoerechnung_rules.php';
		$xsdPath = $modulePath.'/vendor/atgp/factur-x/xsd/factur-x/en16931/Factur-X_1.09.2_EN16931.xsd';
		if (!file_exists($xsdPath)) {
			dol_syslog('AuVo E-Rechnung: EN16931 XSD missing from vendor/, structural validation limited to well-formedness', LOG_WARNING);
		}
		return auvoerechnung_validate_xsd($xml, $modulePath);
	}
}
