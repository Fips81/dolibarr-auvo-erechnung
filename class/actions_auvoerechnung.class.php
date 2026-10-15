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

		// Case « Keine E-Rechnung » cochée sur la facture : PDF ordinaire.
		require_once dirname(__DIR__).'/core/lib/auvoerechnung.lib.php';
		if (auvoerechnung_einvoice_disabled($invoice)) {
			dol_syslog('AuVo E-Rechnung: e-invoice disabled on '.$invoice->ref.', document left unchanged', LOG_INFO);
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

			// XRechnung : fichier XML autonome à côté du PDF pour les clients qui
			// l'exigent (Leitweg-ID ou format choisi sur la fiche tiers).
			$xr = $this->generateXRechnungXml($invoice, $mysoc, $modulePath);
			if ($xr !== null && !$xr['ok']) {
				$warnings = array_merge($warnings, $xr['msgs']);
			}

			dol_syslog('AuVo E-Rechnung: Factur-X PDF generated for '.$invoice->ref, LOG_INFO);
			$this->reportSuccess($invoice->ref, $warnings, ($xr !== null && $xr['ok']) ? $xr['msgs'] : []);
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

		// Case « Keine E-Rechnung » cochée sur la facture : PDF ordinaire.
		require_once dirname(__DIR__).'/core/lib/auvoerechnung.lib.php';
		if (auvoerechnung_einvoice_disabled($invoice)) {
			dol_syslog('AuVo E-Rechnung: e-invoice disabled on '.$invoice->ref.', document left unchanged', LOG_INFO);
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

			$xr = $this->generateXRechnungXml($invoice, $mysoc, $modulePath);
			if ($xr !== null && !$xr['ok']) {
				$warnings = array_merge($warnings, $xr['msgs']);
			}

			dol_syslog('AuVo E-Rechnung: Factur-X PDF (ODT) generated for '.$invoice->ref, LOG_INFO);
			$this->reportSuccess($invoice->ref, $warnings, ($xr !== null && $xr['ok']) ? $xr['msgs'] : []);
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
	 * Écrit la XRechnung (XML CII, CIUS KoSIT) de la facture dans son dossier
	 * documents : `{ref}/{ref}-XRECHNUNG.xml` (auvoerechnung_xrechnung_path),
	 * visible dans les documents de la facture, à déposer sur l'OZG-RE ou à
	 * joindre au mail.
	 *
	 * Seulement si le tiers attend une XRechnung (auvoerechnung_wants_xrechnung),
	 * sauf $force (génération demandée explicitement depuis la fiche facture).
	 * Non bloquant pour le PDF : en cas de données manquantes ou de XML non
	 * conforme, aucun fichier n'est écrit et un éventuel fichier d'une génération
	 * précédente est supprimé (pour ne jamais laisser traîner une XRechnung
	 * périmée) ; les causes sont renvoyées comme avertissements.
	 *
	 * @param Facture $invoice
	 * @param Societe $mysoc
	 * @param string  $modulePath
	 * @param bool    $force       Générer même si le tiers n'attend pas de XRechnung
	 * @return array{ok:bool,msgs:string[]}|null  Résultat et messages, ou null si la facture n'est pas concernée
	 */
	protected function generateXRechnungXml($invoice, $mysoc, $modulePath, $force = false)
	{
		global $conf;

		if (!$force && !auvoerechnung_wants_xrechnung($invoice->thirdparty, $invoice)) {
			return null;
		}
		$target = auvoerechnung_xrechnung_path($invoice->ref, (int) ($invoice->entity ?? $conf->entity));
		if ($target === '') {
			return ['ok' => false, 'msgs' => [auvoerechnung_trans('AuvoERechnungXrWriteError', $invoice->ref.'-XRECHNUNG.xml')]];
		}
		dol_mkdir(dirname($target));

		$problems = auvoerechnung_check_xrechnung($invoice, $mysoc);
		$attachWarnings = [];
		if (empty($problems)) {
			$xw = [];
			$attachments = auvoerechnung_collect_attachments($invoice, $attachWarnings);
			$xr = auvoerechnung_build_xrechnung($invoice, $mysoc, $xw, ['attachments' => $attachments]);
			$xml = $xr['xml'];
			$xsdError = $this->validateXml($xml, $modulePath);
			if ($xsdError !== null) {
				$problems[] = $xsdError;
			}
			foreach (auvoerechnung_validate_business_rules($xr['cii']) as $v) {
				$problems[] = $v;
			}
		}

		if (!empty($problems)) {
			if (file_exists($target)) {
				@unlink($target);
			}
			dol_syslog('AuVo E-Rechnung XRechnung: not generated for '.$invoice->ref.': '.implode(' ; ', $problems), LOG_WARNING);
			$out = [auvoerechnung_trans('AuvoERechnungXrNotGenerated')];
			foreach ($problems as $pr) {
				$out[] = '— '.$pr;
			}
			return ['ok' => false, 'msgs' => $out];
		}

		if (file_put_contents($target, $xml) === false) {
			dol_syslog('AuVo E-Rechnung XRechnung: unable to write '.$target, LOG_ERR);
			return ['ok' => false, 'msgs' => [auvoerechnung_trans('AuvoERechnungXrWriteError', basename($target))]];
		}
		@chmod($target, octdec(getDolGlobalString('MAIN_UMASK', '0664')));
		dol_syslog('AuVo E-Rechnung: XRechnung generated for '.$invoice->ref.' ('.basename($target).')', LOG_INFO);
		$msg = auvoerechnung_trans('AuvoERechnungXrGenerated', basename($target)).' ('.strtoupper($xr['syntax']).')';
		if (!empty($attachments)) {
			$msg .= ' '.auvoerechnung_trans('AuvoERechnungXrAttachments', count($attachments));
		}
		return ['ok' => true, 'msgs' => array_merge([$msg], $attachWarnings)];
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
	 * Génère la XRechnung à la demande (menu de la fiche facture), même si le
	 * client n'est pas marqué XRechnung. Affiche le résultat en message.
	 *
	 * @param Facture $invoice
	 */
	protected function generateXRechnungOnDemand($invoice)
	{
		global $mysoc;

		$modulePath = dirname(__DIR__);
		require_once $modulePath.'/core/lib/auvoerechnung.lib.php';
		require_once $modulePath.'/core/lib/auvoerechnung_rules.php';
		if (empty($invoice->thirdparty) || !is_object($invoice->thirdparty)) {
			$invoice->fetch_thirdparty();
		}
		if (is_object($invoice->thirdparty) && empty($invoice->thirdparty->array_options) && method_exists($invoice->thirdparty, 'fetch_optionals')) {
			$invoice->thirdparty->fetch_optionals();
		}
		if (empty($invoice->lines)) {
			$invoice->fetch_lines();
		}
		$res = $this->generateXRechnungXml($invoice, $mysoc, $modulePath, true);
		$first = array_shift($res['msgs']);
		setEventMessages($first, $res['msgs'], $res['ok'] ? 'mesgs' : 'warnings');
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
		require_once dirname(__DIR__).'/core/lib/auvoerechnung.lib.php';

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
			// XRechnung à la demande : aussi pour un client sans Leitweg-ID ni
			// format choisi (envoi ponctuel à un destinataire qui l'exige).
			$items[] = array('href' => $url.'&action=auvoerechnung_xrechnung', 'label' => $langs->trans('AuvoERechnungBtnXRechnung'));
			// Envoi à l'OZG-RE (canal e-mail) : seulement si l'adresse du compte
			// OZG-RE est configurée ; confirmation via le hook formConfirm.
			if (getDolGlobalString('AUVOERECHNUNG_OZGRE_EMAIL') !== '' && $this->userCanSend($user)) {
				$items[] = array('href' => $url.'&action=auvoerechnung_ozgre', 'label' => $langs->trans('AuvoERechnungBtnOzgRe'));
			}
		}
		if ($canRead) {
			$xrFile = auvoerechnung_xrechnung_path($object->ref, (int) ($object->entity ?? 1));
			if ($xrFile !== '' && file_exists($xrFile)) {
				$safeRef = dol_sanitizeFileName($object->ref);
				$items[] = array(
					'href' => DOL_URL_ROOT.'/document.php?modulepart=facture&entity='.((int) ($object->entity ?? 1)).'&file='.urlencode($safeRef.'/'.basename($xrFile)),
					'label' => $langs->trans('AuvoERechnungBtnXRechnungDownload'),
				);
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
		if (!in_array($action, ['auvoerechnung_verify', 'auvoerechnung_regenerate', 'auvoerechnung_generatechorus', 'auvoerechnung_xrechnung', 'auvoerechnung_confirm_ozgre'], true)) {
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

		if ($action === 'auvoerechnung_xrechnung') {
			if (!$this->userCanWrite($user)) {
				setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
				return 0;
			}
			$this->generateXRechnungOnDemand($object);
			$action = '';
			return 0;
		}

		if ($action === 'auvoerechnung_confirm_ozgre') {
			if (GETPOST('confirm', 'alpha') === 'yes') {
				if (!$this->userCanSend($user)) {
					setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
				} else {
					$this->sendXRechnungToOzgRe($object);
				}
			}
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
	 * Hook getFormMail — contexte formmail.
	 * À l'ouverture du formulaire « Envoyer par e-mail » d'une facture dont le
	 * client attend une XRechnung, joint le fichier XRechnung (généré au
	 * besoin). Seulement au premier affichage (mode=init) : une pièce retirée à
	 * la main n'est pas rajoutée. Désactivable (AUVOERECHNUNG_MAIL_ATTACH_XR).
	 */
	public function getFormMail($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $mysoc;

		if (!getDolGlobalInt('AUVOERECHNUNG_MAIL_ATTACH_XR', 1) || GETPOST('mode', 'alpha') !== 'init') {
			return 0;
		}
		if (!is_object($object) || !method_exists($object, 'add_attached_files')
			|| !preg_match('/^inv(\d+)$/', (string) ($parameters['trackid'] ?? $object->trackid ?? ''), $m)) {
			return 0;
		}
		$modulePath = dirname(__DIR__);
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		require_once $modulePath.'/core/lib/auvoerechnung.lib.php';
		require_once $modulePath.'/core/lib/auvoerechnung_rules.php';

		$invoice = new Facture($this->db);
		if ($invoice->fetch((int) $m[1]) <= 0 || (int) $invoice->status < 1) {
			return 0;
		}
		$invoice->fetch_thirdparty();
		if (is_object($invoice->thirdparty) && method_exists($invoice->thirdparty, 'fetch_optionals')) {
			$invoice->thirdparty->fetch_optionals();
		}
		if (!auvoerechnung_wants_xrechnung($invoice->thirdparty, $invoice) || auvoerechnung_einvoice_disabled($invoice)) {
			return 0;
		}
		global $user;
		if (!$this->userCanRead($user)) {
			return 0;
		}
		$file = auvoerechnung_xrechnung_path($invoice->ref, (int) ($invoice->entity ?? $conf->entity));
		if ($file !== '' && !is_file($file) && $this->userCanWrite($user)) {
			$res = $this->generateXRechnungXml($invoice, $mysoc, $modulePath);
			if ($res !== null && !$res['ok']) {
				setEventMessages(array_shift($res['msgs']), $res['msgs'], 'warnings');
			}
		}
		if ($file !== '' && is_file($file)) {
			$object->add_attached_files($file, basename($file), 'application/xml');
		}
		return 0;
	}

	/**
	 * Hook formConfirm — contexte invoicecard.
	 * Demande confirmation avant l'envoi de la XRechnung à l'OZG-RE, en
	 * rappelant le destinataire et la Leitweg-ID de la facture.
	 */
	public function formConfirm($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		$contexts = explode(':', $parameters['context'] ?? '');
		if ($action !== 'auvoerechnung_ozgre' || !in_array('invoicecard', $contexts, true) || !($object instanceof Facture)) {
			return 0;
		}
		if (!$this->userCanSend($user)) {
			return 0;
		}
		$langs->loadLangs(['auvoerechnung@auvoerechnung']);
		require_once dirname(__DIR__).'/core/lib/auvoerechnung.lib.php';
		if (empty($object->thirdparty) || !is_object($object->thirdparty)) {
			$object->fetch_thirdparty();
		}
		if (is_object($object->thirdparty) && empty($object->thirdparty->array_options) && method_exists($object->thirdparty, 'fetch_optionals')) {
			$object->thirdparty->fetch_optionals();
		}
		if (empty($object->array_options) && method_exists($object, 'fetch_optionals')) {
			$object->fetch_optionals();
		}
		$leitwegId = auvoerechnung_invoice_leitwegid($object);
		$question = $langs->trans('AuvoERechnungOzgReConfirm', $object->ref, getDolGlobalString('AUVOERECHNUNG_OZGRE_EMAIL'), $leitwegId !== '' ? $leitwegId : '—');
		if ($leitwegId === '') {
			$question .= '<br><span class="warning">'.$langs->trans('AuvoERechnungOzgReNoLeitwegId').'</span>';
		}
		if ($this->countOzgReSendings($object) > 0) {
			$question .= '<br><span class="warning">'.$langs->trans('AuvoERechnungOzgReAlreadySent').'</span>';
		}
		$form = new Form($this->db);
		$this->resPrint = $form->formconfirm($_SERVER['PHP_SELF'].'?facid='.((int) $object->id), $langs->trans('AuvoERechnungBtnOzgRe'), $question, 'auvoerechnung_confirm_ozgre', '', 'yes', 1);
		return 0;
	}

	/**
	 * Envoie la XRechnung de la facture à l'OZG-RE du Bund par e-mail.
	 *
	 * Canal e-mail de l'OZG-RE (Leitfaden Rechnungsversender, Bundesdruckerei) :
	 * adresse individuelle propre au compte OZG-RE du fournisseur, exactement
	 * UNE XRechnung par e-mail, 10 Mo maximum, aucune autre pièce jointe ; objet
	 * et corps ne sont pas exploités. La XRechnung est régénérée juste avant
	 * l'envoi : on n'envoie jamais un fichier périmé ou non conforme.
	 * L'envoi est tracé comme un envoi de facture par e-mail du cœur
	 * (trigger BILL_SENTBYMAIL → événement agenda, compteur d'envois).
	 *
	 * @param Facture $invoice
	 * @return bool
	 */
	protected function sendXRechnungToOzgRe($invoice)
	{
		global $conf, $langs, $mysoc, $user;

		$modulePath = dirname(__DIR__);
		require_once $modulePath.'/core/lib/auvoerechnung.lib.php';
		require_once $modulePath.'/core/lib/auvoerechnung_rules.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
		$langs->loadLangs(['mails', 'auvoerechnung@auvoerechnung']);

		$to = getDolGlobalString('AUVOERECHNUNG_OZGRE_EMAIL');
		if ($to === '' || !isValidEmail($to)) {
			setEventMessages($langs->trans('AuvoERechnungOzgReNoAddress'), null, 'errors');
			return false;
		}
		if (getDolGlobalInt('MAIN_DISABLE_ALL_MAILS')) {
			setEventMessages($langs->trans('AuvoERechnungOzgReMailsDisabled'), null, 'errors');
			return false;
		}
		if (empty($invoice->thirdparty) || !is_object($invoice->thirdparty)) {
			$invoice->fetch_thirdparty();
		}
		if (is_object($invoice->thirdparty) && empty($invoice->thirdparty->array_options) && method_exists($invoice->thirdparty, 'fetch_optionals')) {
			$invoice->thirdparty->fetch_optionals();
		}
		if (empty($invoice->lines)) {
			$invoice->fetch_lines();
		}
		// La plateforme route la facture sur la Leitweg-ID (BT-10) : sans elle,
		// l'OZG-RE ne peut pas l'attribuer.
		if (auvoerechnung_invoice_leitwegid($invoice) === '') {
			setEventMessages($langs->trans('AuvoERechnungOzgReNoLeitwegId'), null, 'errors');
			return false;
		}

		$res = $this->generateXRechnungXml($invoice, $mysoc, $modulePath, true);
		if (empty($res['ok'])) {
			$msgs = $res['msgs'] ?? [];
			setEventMessages($langs->trans('AuvoERechnungOzgReNotSent'), $msgs, 'errors');
			return false;
		}
		$file = auvoerechnung_xrechnung_path($invoice->ref, (int) ($invoice->entity ?? $conf->entity));
		if (!is_file($file) || filesize($file) > 10 * 1024 * 1024) {
			setEventMessages($langs->trans('AuvoERechnungOzgReNotSent'), [$langs->trans('AuvoERechnungOzgReTooLarge')], 'errors');
			return false;
		}

		$fromAddr = getDolGlobalString('MAIN_MAIL_EMAIL_FROM', getDolGlobalString('MAIN_INFO_SOCIETE_MAIL'));
		$from = dol_string_nospecial($mysoc->name, ' ', array(',')).' <'.$fromAddr.'>';
		$subject = 'XRechnung '.$invoice->ref;
		$message = $langs->transnoentitiesnoconv('AuvoERechnungOzgReMailBody', $invoice->ref);
		$trackid = 'inv'.((int) $invoice->id);
		$mail = new CMailFile($subject, $to, $from, $message, [$file], ['application/xml'], [basename($file)], '', '', 0, 0, '', '', $trackid, '', 'standard');
		if (!empty($mail->error) || !$mail->sendfile()) {
			dol_syslog('AuVo E-Rechnung: OZG-RE sending failed for '.$invoice->ref.': '.$mail->error, LOG_ERR);
			setEventMessages($langs->trans('AuvoERechnungOzgReNotSent'), [$mail->error], 'errors');
			return false;
		}
		dol_syslog('AuVo E-Rechnung: XRechnung '.$invoice->ref.' sent to OZG-RE', LOG_INFO);

		// Traçabilité identique à un envoi de facture par e-mail du cœur
		$this->db->begin();
		$this->db->query('UPDATE '.MAIN_DB_PREFIX.'facture SET email_sent_counter = email_sent_counter + 1 WHERE rowid = '.((int) $invoice->id));
		$invoice->socid = $invoice->socid ?: $invoice->thirdparty->id;
		$invoice->sendtoid = [];
		$invoice->actiontypecode = 'AC_EMAIL';
		$invoice->actionmsg2 = $langs->transnoentitiesnoconv('AuvoERechnungOzgReEvent', $invoice->ref);
		$invoice->actionmsg = $langs->transnoentitiesnoconv('MailFrom').': '.dol_escape_htmltag($from)."\n".$langs->transnoentitiesnoconv('MailTo').': '.dol_escape_htmltag($to)."\n".$langs->transnoentitiesnoconv('MailTopic').': '.$subject."\n".$langs->transnoentitiesnoconv('AttachedFiles').': '.basename($file);
		$invoice->trackid = $trackid;
		$invoice->fk_element = $invoice->id;
		$invoice->elementtype = $invoice->element;
		$invoice->attachedfiles = ['paths' => [$file], 'names' => [basename($file)], 'mimes' => ['application/xml']];
		$invoice->email_msgid = $mail->msgid;
		$invoice->email_from = $from;
		$invoice->email_subject = $subject;
		$invoice->email_to = $to;
		$invoice->context['email_msgid'] = $mail->msgid;
		$invoice->context['email_from'] = $from;
		$invoice->context['email_subject'] = $subject;
		$invoice->context['email_to'] = $to;
		$invoice->context['auvoerechnung_ozgre'] = 1;
		if ($invoice->call_trigger('BILL_SENTBYMAIL', $user) < 0) {
			$this->db->rollback();
			setEventMessages($langs->trans('AuvoERechnungOzgReSent', $invoice->ref), $invoice->errors, 'warnings');
			return true;
		}
		$this->db->commit();
		setEventMessages($langs->trans('AuvoERechnungOzgReSent', $invoice->ref), null, 'mesgs');
		return true;
	}

	/**
	 * Nombre d'envois déjà tracés de cette facture vers l'adresse OZG-RE
	 * configurée (événements agenda e-mail liés à la facture).
	 *
	 * @param Facture $invoice
	 * @return int
	 */
	protected function countOzgReSendings($invoice)
	{
		$to = getDolGlobalString('AUVOERECHNUNG_OZGRE_EMAIL');
		if ($to === '') {
			return 0;
		}
		$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."actioncomm";
		$sql .= " WHERE elementtype = '".$this->db->escape($invoice->element)."' AND fk_element = ".((int) $invoice->id);
		$sql .= " AND email_to = '".$this->db->escape($to)."'";
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (int) $obj->nb;
		}
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
		require_once dirname(__DIR__).'/core/lib/auvoerechnung.lib.php';
		return auvoerechnung_user_can($user, 'read');
	}

	/**
	 * Droit de création/modification des factures.
	 */
	protected function userCanWrite($user)
	{
		require_once dirname(__DIR__).'/core/lib/auvoerechnung.lib.php';
		return auvoerechnung_user_can($user, 'write');
	}

	/**
	 * Droit d'envoyer la facture (comme le bouton « Envoyer email » du cœur :
	 * droit d'écriture, plus le droit avancé d'envoi si MAIN_USE_ADVANCED_PERMS).
	 */
	protected function userCanSend($user)
	{
		require_once dirname(__DIR__).'/core/lib/auvoerechnung.lib.php';
		return auvoerechnung_user_can($user, 'send');
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
	protected function reportSuccess($invoiceRef, array $warnings, array $infos = [])
	{
		// Pas de session interactive (API REST, CLI, cron) : log uniquement
		if (!function_exists('setEventMessages') || (php_sapi_name() === 'cli')) {
			return;
		}

		// Informations (ex. XRechnung écrite) : message séparé, jamais compté
		// comme avertissement.
		foreach ($infos as $info) {
			setEventMessages($info, null, 'mesgs');
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
