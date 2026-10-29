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
 * Onglet « Chorus Pro » de la fiche facture : paramètres du profil B2G
 * (cadre de facturation BT-23, code service BT-10, n° engagement BT-13,
 * n° marché BT-12) + case d'activation. Ces champs pilotent la génération
 * du second PDF {ref}-CHORUS.pdf.
 */

$res = 0;
if (!$res && file_exists("../main.inc.php"))        $res = require "../main.inc.php";
if (!$res && file_exists("../../main.inc.php"))     $res = require "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php"))  $res = require "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/invoice.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once dol_buildpath('/auvoerechnung/core/lib/auvoerechnung.lib.php');

$langs->loadLangs(array('bills', 'auvoerechnung@auvoerechnung'));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');

if (!auvoerechnung_user_can($user, 'read')) {
	accessforbidden();
}

$object = new Facture($db);
if ($id <= 0 || $object->fetch($id) <= 0) {
	accessforbidden();
}
$object->fetch_thirdparty();

// Zugriff auf die Rechnung (Entity, externe Benutzer, Zugriff nur auf eigene Geschäftspartner)
restrictedArea($user, 'facture', $object->id, '', '', 'fk_soc', 'rowid');

$form = new Form($db);

/*
 * Enregistrement des paramètres Chorus
 */
if ($action === 'savechorus' && auvoerechnung_user_can($user, 'write')) {
	if (GETPOST('token', 'alpha') !== currentToken()) {
		accessforbidden('Bad value for CSRF token');
	}
	$object->array_options['options_lfxchorus']      = GETPOSTINT('lfxchorus');
	$object->array_options['options_lfxcadre']       = GETPOST('lfxcadre', 'alpha');
	$object->array_options['options_lfxservicecode'] = GETPOST('lfxservicecode', 'alphanohtml');
	$object->array_options['options_lfxengagement']  = GETPOST('lfxengagement', 'alphanohtml');
	$object->array_options['options_lfxmarche']      = GETPOST('lfxmarche', 'alphanohtml');
	if ($object->insertExtraFields() > 0) {
		setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
	} else {
		setEventMessages($object->error, $object->errors, 'errors');
	}
}

/*
 * Report manuel des références Chorus remplies dans la note publique (visible
 * sur le PDF). Sur demande uniquement (bouton) : les champs Chorus sont sinon
 * masqués du PDF. N'ajoute que les champs non vides, sans doublon, sans toucher
 * au reste de la note.
 */
if ($action === 'writechorusnote' && auvoerechnung_user_can($user, 'write')) {
	if (GETPOST('token', 'alpha') !== currentToken()) {
		accessforbidden('Bad value for CSRF token');
	}
	$o = $object->array_options;
	$lines = array();
	if (!empty($o['options_lfxcadre'])) {
		$frameworks = auvoerechnung_chorus_frameworks();
		$code = $o['options_lfxcadre'];
		$lines[] = $langs->trans('AuvoERechnungEfCadre').' : '.(isset($frameworks[$code]) ? $frameworks[$code] : $code);
	}
	if (!empty($o['options_lfxservicecode'])) {
		$lines[] = $langs->trans('AuvoERechnungEfServiceCode').' : '.$o['options_lfxservicecode'];
	}
	if (!empty($o['options_lfxengagement'])) {
		$lines[] = $langs->trans('AuvoERechnungEfEngagement').' : '.$o['options_lfxengagement'];
	}
	if (!empty($o['options_lfxmarche'])) {
		$lines[] = $langs->trans('AuvoERechnungEfMarche').' : '.$o['options_lfxmarche'];
	}
	if (empty($lines)) {
		setEventMessages($langs->trans('AuvoERechnungChorusToNoteEmpty'), null, 'warnings');
	} else {
		$note = (string) $object->note_public;
		$added = 0;
		foreach ($lines as $l) {
			if (strpos($note, $l) !== false) {
				continue;
			}
			$note = ($note !== '' ? rtrim($note)."\n" : '').$l;
			$added++;
		}
		if ($added > 0 && $object->update_note($note, '_public') > 0) {
			setEventMessages($langs->trans('AuvoERechnungChorusToNoteDone', $added), null, 'mesgs');
		} elseif ($added === 0) {
			setEventMessages($langs->trans('AuvoERechnungChorusToNoteAlready'), null, 'mesgs');
		} else {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	}
}

/*
 * Vue
 */
llxHeader('', $langs->trans('AuvoERechnungChorusTab').' — '.$object->ref);

$head = facture_prepare_head($object);
print dol_get_fiche_head($head, 'auvoerechnungchorus', $langs->trans('Bill'), -1, 'bill');

$linkback = '<a href="'.DOL_URL_ROOT.'/compta/facture/list.php?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref');

print '<div class="fichecenter">';
print '<div class="opacitymedium" style="margin:6px 0 14px 0;">'.$langs->trans('AuvoERechnungChorusTabHelp').'</div>';

// État de détection courant
$isChorus = auvoerechnung_is_chorus_invoice($object);
print '<div class="info" style="margin-bottom:12px;">'.$langs->trans('AuvoERechnungChorusDetected').' : <strong>'
	.($isChorus ? $langs->trans('Yes') : $langs->trans('No')).'</strong></div>';

print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'?id='.((int) $object->id).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savechorus">';

print '<table class="border centpercent tableforfield">';

// Case d'activation (forçage du profil Chorus)
print '<tr><td class="titlefieldcreate">'.$langs->trans('AuvoERechnungEfChorus').'</td><td>';
print '<input type="checkbox" name="lfxchorus" value="1"'.(!empty($object->array_options['options_lfxchorus']) ? ' checked' : '').'>';
print ' <span class="opacitymedium">'.$langs->trans('AuvoERechnungEfChorusHint').'</span>';
print '</td></tr>';

// Cadre de facturation BT-23
print '<tr><td>'.$langs->trans('AuvoERechnungEfCadre').'</td><td>';
$curCadre = !empty($object->array_options['options_lfxcadre']) ? $object->array_options['options_lfxcadre'] : 'A1';
print $form->selectarray('lfxcadre', auvoerechnung_chorus_frameworks(), $curCadre, 0, 0, 0, '', 0, 0, 0, '', 'minwidth400');
print '</td></tr>';

// Code service exécutant BT-10
print '<tr><td>'.$langs->trans('AuvoERechnungEfServiceCode').'</td><td>';
print '<input type="text" name="lfxservicecode" class="flat minwidth300" value="'.dol_escape_htmltag($object->array_options['options_lfxservicecode'] ?? '').'">';
print '</td></tr>';

// N° engagement BT-13
print '<tr><td>'.$langs->trans('AuvoERechnungEfEngagement').'</td><td>';
print '<input type="text" name="lfxengagement" class="flat minwidth300" value="'.dol_escape_htmltag($object->array_options['options_lfxengagement'] ?? '').'">';
print '</td></tr>';

// N° marché BT-12
print '<tr><td>'.$langs->trans('AuvoERechnungEfMarche').'</td><td>';
print '<input type="text" name="lfxmarche" class="flat minwidth300" value="'.dol_escape_htmltag($object->array_options['options_lfxmarche'] ?? '').'">';
print '</td></tr>';

print '</table>';
print '</div>';

print '<div class="center" style="margin-top:14px;">';
print '<input type="submit" class="button button-save" value="'.$langs->trans('Save').'">';
print '</div>';
print '</form>';

// Bouton manuel : reporter les références Chorus remplies dans la note publique
// de la facture (qui s'imprime sur le PDF). Sur demande, jamais automatique.
if (auvoerechnung_user_can($user, 'write')) {
	print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'?id='.((int) $object->id).'" class="center" style="margin-top:16px;">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="writechorusnote">';
	print '<input type="submit" class="button button-add" value="'.dol_escape_htmltag($langs->trans('AuvoERechnungChorusToNote')).'">';
	print '<div class="opacitymedium small" style="margin-top:5px;">'.$langs->trans('AuvoERechnungChorusToNoteHint').'</div>';
	print '</form>';
}

print '<div class="opacitymedium center" style="margin-top:18px;">'.$langs->trans('AuvoERechnungChorusTabGenerateHint').'</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
