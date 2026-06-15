<?php
/* Copyright (C) 2014-2019  Alexandre Spangaro	<aspangaro@open-dsi.fr>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2025		MDW						<mdeweerd@users.noreply.github.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 */

/**
 * \file		htdocs/salaries/admin/salaries.php
 * \ingroup		Salaries
 * \brief		Setup page to configure salaries module
 *
 * Principle:
 * * - “Single” matches are converted automatically;
 * * - “Multiple” matches are listed for manual processing.
 */

// Load Dolibarr environment
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Societe $mysoc
 * @var Translate $langs
 * @var User $user
 */

// Load translation files required by the page
$langs->loadLangs(array('admin', 'companies', 'other'));

// Security check
if (!$user->admin) accessforbidden();
if (!preg_match('/fr/i', $mysoc->country_code)) accessforbidden();

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$limit = GETPOSTINT('limit') ?: 200;
$maxmanual = GETPOSTINT('maxmanual') ?: 200;

$hookmanager->initHooks(array('thirdpartynaftransitionadmin'));
$form = new Form($db);

$errors = array();
$messages = array();
$stats = array(
	'total_unique_mappings' => 0,
	'total_multiple_mappings' => 0,
	'total_unique_oldcodes' => 0,
	'total_multiple_oldcodes' => 0,
	'thirdparties_unique_candidates' => 0,
	'thirdparties_multiple_candidates' => 0,
	'thirdparties_updated' => 0,
);
$uniqueMap = array();
$multipleMap = array();
$multipleRows = array();
$previewUnique = array();
$previewMultiple = array();

/*
 * Actions
 */

function nafNormalizeCode($code)
{
	$code = trim((string) $code);
	$code = strtoupper($code);
	$code = preg_replace('/[^A-Z0-9]/', '', $code);
	return $code;
}

function nafFormatCode($code)
{
	$code = nafNormalizeCode($code);
	if (preg_match('/^([0-9]{2})([0-9]{2})([A-Z])$/', $code, $m)) {
		return $m[1].'.'.$m[2].$m[3];
	}
	return $code;
}

function nafLoadMappings(&$uniqueMap, &$multipleMap, &$multipleRows, &$stats, &$errors)
{
	$path = DOL_DOCUMENT_ROOT.'/societe/admin/societe_naf_transition_mappings.php';
	if (!is_readable($path)) {
		$errors[] = 'Le fichier de mapping NAF est introuvable: '.$path;
		return false;
	}

	$nafTransitions = require $path;
	if (!is_array($nafTransitions)) {
		$errors[] = 'Le fichier de mapping NAF est invalide.';
		return false;
	}

	foreach ($nafTransitions as $oldcode => $entry) {
		$oldcode = nafNormalizeCode($oldcode);
		if (empty($entry['choices']) || !is_array($entry['choices'])) continue;

		if (($entry['type'] ?? '') === 'Unique' && count($entry['choices']) === 1) {
			$choice = $entry['choices'][0];
			$uniqueMap[$oldcode] = array(
				'old_code' => $oldcode,
				'new_code' => nafNormalizeCode($choice['new_code'] ?? ''),
				'new_label' => trim((string) ($choice['new_label'] ?? '')),
				'type' => 'Unique',
			);
		} else {
			$choices = array();
			foreach ($entry['choices'] as $choice) {
				$choices[] = array(
					'new_code' => nafNormalizeCode($choice['new_code'] ?? ''),
					'new_label' => trim((string) ($choice['new_label'] ?? '')),
				);
			}
			$multipleMap[$oldcode] = $choices;
			$multipleRows[$oldcode] = array(
				'old_code' => $oldcode,
				'choices' => $choices,
			);
		}
	}

	$stats['total_unique_mappings'] = count($uniqueMap);
	$stats['total_unique_oldcodes'] = count($uniqueMap);
	$stats['total_multiple_oldcodes'] = count($multipleMap);
	$stats['total_multiple_mappings'] = 0;
	foreach ($multipleMap as $choices) {
		$stats['total_multiple_mappings'] += count($choices);
	}
	return true;
}

function nafGetThirdpartyPreview($db, $codes, $limit)
{
	if (empty($codes)) return array();

	$escaped = array();
	foreach ($codes as $code) {
		$escaped[] = "'".$db->escape($code)."'";
	}

	$sql = 'SELECT s.rowid, s.nom, s.name_alias, s.ape';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'societe as s';
	$sql .= ' WHERE s.entity IN ('.getEntity('societe').')';
	$sql .= ' AND s.fk_pays = 1';
	$sql .= ' AND s.ape IS NOT NULL AND s.ape <> ""';
	$sql .= ' AND UPPER(REPLACE(REPLACE(s.ape, ".", ""), " ", "")) IN ('.implode(',', $escaped).')';
	$sql .= ' ORDER BY s.nom ASC';
	$sql .= ' LIMIT '.((int) $limit);

	$resql = $db->query($sql);
	$rows = array();
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$rows[] = $obj;
		}
	}
	return $rows;
}

if (in_array($action, array('preview', 'convert'))) {
	$ok = nafLoadMappings($uniqueMap, $multipleMap, $multipleRows, $stats, $errors);
	if ($ok) {
		$previewUnique = nafGetThirdpartyPreview($db, array_keys($uniqueMap), $limit);
		$previewMultiple = nafGetThirdpartyPreview($db, array_keys($multipleMap), $maxmanual);
		$stats['thirdparties_unique_candidates'] = count($previewUnique);
		$stats['thirdparties_multiple_candidates'] = count($previewMultiple);
	}
}

if ($action === 'convert' && empty($errors)) {
	if ($confirm !== 'yes') {
		$errors[] = 'Confirmation obligatoire avant conversion.';
	} else {
		$error = 0;
		$updated = 0;
		$db->begin();

		$sql = 'SELECT s.rowid, s.ape';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'societe as s';
		$sql .= ' WHERE s.entity IN ('.getEntity('societe').')';
		$sql .= ' AND s.fk_pays = 1';
		$sql .= ' AND s.ape IS NOT NULL AND s.ape <> ""';

		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$oldcode = nafNormalizeCode($obj->ape);
				if (!isset($uniqueMap[$oldcode])) continue;

				$target = $uniqueMap[$oldcode]['new_code'];
				if ($target === $oldcode) continue;

				$company = new Societe($db);
				$ret = $company->fetch($obj->rowid);
				if ($ret <= 0) {
					$error++;
					continue;
				}

				$company->idprof3 = $target;
				$ret = $company->update($company->id, $user);
				if ($ret > 0) {
					$updated++;
				} else {
					$error++;
				}
			}
		} else {
			$error++;
			dol_print_error($db);
		}

		if ($error) {
			$db->rollback();
			$errors[] = 'Des erreurs sont survenues pendant la conversion. Transaction annulée.';
		} else {
			$db->commit();
			$stats['thirdparties_updated'] = $updated;
			$messages[] = $updated.' société(s) mise(s) à jour automatiquement sur ape.';
		}
	}
}

/*
 * View
 */

$title = 'Transition codes NAF 2025';

llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-admin page-company-admin');

print load_fiche_titre($title, '', 'title_setup');

print '<div class="opacitymedium">Migration des codes NAF rév. 2 → NAF 2025 éligible à partir du 1er janvier 2027</div>';
print '<br>';

foreach ($messages as $msg) setEventMessages($msg, null, 'mesgs');
foreach ($errors as $msg) setEventMessages($msg, null, 'errors');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="preview">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Paramètres</td><td>Valeurs</td></tr>';
print '<tr class="oddeven"><td>Limite aperçu cas uniques</td><td><input type="number" name="limit" min="1" value="'.((int) $limit).'"></td></tr>';
print '<tr class="oddeven"><td>Limite aperçu cas multiples</td><td><input type="number" name="maxmanual" min="1" value="'.((int) $maxmanual).'"></td></tr>';
print '</table>';

print '<div class="center paddingtop">';
print '<input class="button button-search" type="submit" value="Relancer l\'analyse">';
print '</div>';
print '</form>';

if ($action === 'preview' || $action === 'convert') {
	print '<br>';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td colspan="2">Statistiques</td></tr>';
	print '<tr class="oddeven"><td>Anciens codes NAF à conversion automatique</td><td>'.((int) $stats['total_unique_oldcodes']).'</td></tr>';
	print '<tr class="oddeven"><td>Anciens codes NAF à traitement manuel</td><td>'.((int) $stats['total_multiple_oldcodes']).'</td></tr>';
	print '<tr class="oddeven"><td>Sociétés françaises aperçues en conversion automatique</td><td>'.((int) $stats['thirdparties_unique_candidates']).'</td></tr>';
	print '<tr class="oddeven"><td>Sociétés françaises aperçues en traitement manuel</td><td>'.((int) $stats['thirdparties_multiple_candidates']).'</td></tr>';
	print '<tr class="oddeven"><td>Conversions réellement effectuées</td><td>'.((int) $stats['thirdparties_updated']).'</td></tr>';
	print '</table>';

	print '<br>';
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td colspan="4">Sociétés à traiter automatiquement (correspondances uniques)</td></tr>';
	print '<tr class="liste_titre"><td>ID</td><td>Société</td><td>Code actuel</td><td>Nouveau code</td></tr>';
	if (empty($previewUnique)) {
		print '<tr class="oddeven"><td colspan="4" class="opacitymedium">Aucune société trouvée pour les correspondances uniques.</td></tr>';
	} else {
		foreach ($previewUnique as $obj) {
			$oldcode = nafNormalizeCode($obj->ape);
			$target = $uniqueMap[$oldcode]['new_code'];

			$thirdparty = new Societe($db);
			$thirdparty->fetch($obj->rowid);

			$link = $thirdparty->getNomUrl(1, '', 0, 0, -1, 0, '_blank').' '.img_picto('Ouvrir dans un nouvel onglet', 'external-link-alt');

			print '<tr class="oddeven">';
			print '<td>'.((int) $obj->rowid).'</td>';
			print '<td>'.$link.'</td>';
			print '<td>'.dol_escape_htmltag(nafFormatCode($oldcode)).'</td>';
			print '<td>'.dol_escape_htmltag(nafFormatCode($target)).'</td>';
			print '</tr>';
		}
	}
	print '</table>';
	print '</div>';

	print '<div class="tabsAction">';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline-block; margin-right: 8px;">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="convert">';
	print '<input type="hidden" name="confirm" value="yes">';
	print '<input type="hidden" name="limit" value="'.((int) $limit).'">';
	print '<input type="hidden" name="maxmanual" value="'.((int) $maxmanual).'">';
	print '<input type="submit" class="button button-edit" value="Lancer la conversion automatique des cas uniques"';
	print ' onclick="return confirm(\'Confirmer la mise à jour du champ ape pour toutes les sociétés françaises ayant une correspondance unique ?\');">';
	print '</form>';
	print '</div>';

	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td colspan="4">Sociétés à traiter manuellement (correspondances multiples)</td></tr>';
	print '<tr class="liste_titre"><td>ID</td><td>Société</td><td>Code actuel</td><td>Choix possibles NAF 2025</td></tr>';
	if (empty($previewMultiple)) {
		print '<tr class="oddeven"><td colspan="4" class="opacitymedium">Aucune société trouvée pour les correspondances multiples.</td></tr>';
	} else {
		foreach ($previewMultiple as $obj) {
			$oldcode = nafNormalizeCode($obj->ape);

			$thirdparty = new Societe($db);
			$thirdparty->fetch($obj->rowid);
			$link = $thirdparty->getNomUrl(1, '', 0, 0, -1, 0, '_blank').' '.img_picto('Ouvrir dans un nouvel onglet', 'external-link-alt');

			$choices = array();
			if (!empty($multipleRows[$oldcode]['choices'])) {
				foreach ($multipleRows[$oldcode]['choices'] as $line) {
					$choices[] = dol_escape_htmltag(nafFormatCode($line['new_code']).' - '.$line['new_label']);
				}
			}

			print '<tr class="oddeven">';
			print '<td>'.((int) $obj->rowid).'</td>';
			print '<td>'.$link.'</td>';
			print '<td>'.dol_escape_htmltag(nafFormatCode($oldcode)).'</td>';
			print '<td>'.implode('<br>', $choices).'</td>';
			print '</tr>';
		}
	}
	print '</table>';
	print '</div>';
}

llxFooter();
$db->close();
