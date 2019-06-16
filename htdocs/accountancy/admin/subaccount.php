<?php
/* Copyright (C) 2013-2016 Olivier Geffroy      <jeff@jeffinfo.com>
 * Copyright (C) 2013-2019 Alexandre Spangaro   <aspangaro@open-dsi.fr>
 * Copyright (C) 2016-2018 Laurent Destailleur  <eldy@users.sourceforge.net>
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * \file 		htdocs/accountancy/admin/subaccount.php
 * \ingroup     Accountancy (Double entries)
 * \brief		List accounting sub account
 */

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/accounting.lib.php';
require_once DOL_DOCUMENT_ROOT . '/accountancy/class/accountingaccount.class.php';

// Load translation files required by the page
$langs->loadLangs(array("compta","bills","admin","accountancy","salaries"));

$mesg = '';
$action = GETPOST('action', 'aZ09');
$cancel = GETPOST('cancel', 'alpha');
$id = GETPOST('id', 'int');
$rowid = GETPOST('rowid', 'int');
$contextpage=GETPOST('contextpage', 'aZ')?GETPOST('contextpage', 'aZ'):'accountingaccountlist';   // To manage different context of search

$search_account = GETPOST('search_subaccount', 'alpha');
$search_label = GETPOST('search_label', 'alpha');

// Security check
if ($user->societe_id > 0) accessforbidden();
if (! $user->rights->accounting->chartofaccount) accessforbidden();

// Load variable for pagination
$limit = GETPOST('limit', 'int')?GETPOST('limit', 'int'):$conf->liste_limit;
$sortfield = GETPOST('sortfield', 'alpha');
$sortorder = GETPOST('sortorder', 'alpha');
$page = GETPOST('page', 'int');
if (empty($page) || $page == -1) { $page = 0; }     // If $page is not defined, or '' or -1
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;
if (! $sortfield) $sortfield = "aa.subaccount_number";
if (! $sortorder) $sortorder = "ASC";

$arrayfields=array(
    'aa.subaccount_number'=>array('label'=>$langs->trans("SubAccountNumber"), 'checked'=>1),
    'aa.label'=>array('label'=>$langs->trans("Label"), 'checked'=>1)
);

$accounting = new AccountingAccount($db);



/*
 * Actions
 */

if (GETPOST('cancel', 'alpha')) { $action='list'; $massaction=''; }
if (! GETPOST('confirmmassaction', 'alpha')) { $massaction=''; }

$parameters=array();
$reshook=$hookmanager->executeHooks('doActions', $parameters, $object, $action);    // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');

if (empty($reshook))
{
    if (! empty($cancel)) $action = '';

    include DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php';

    if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') ||GETPOST('button_removefilter', 'alpha')) // All test are required to be compatible with all browsers
    {
    	$search_subaccount = "";
    	$search_label = "";
		$search_array_options=array();
    }
}


/*
 * View
 */

$form=new Form($db);

llxHeader('', $langs->trans("ListOfSubAccounts"));

// Auxiliary customer account
$sql = "SELECT DISTINCT code_compta, nom ";
$sql .= " FROM ".MAIN_DB_PREFIX."societe";
$sql .= " WHERE entity IN (" . getEntity('societe') . ")";
$sql .= " ORDER BY code_compta";

dol_syslog(get_class($this)."::select_auxaccount", LOG_DEBUG);
$resql = $this->db->query($sql);
if ($resql) {
    while ($obj = $this->db->fetch_object($resql)) {
        if (!empty($obj->code_compta)) {
            $aux_account[$obj->code_compta] = $obj->code_compta.' ('.$obj->nom.')';
        }
    }
} else {
    $this->error = "Error ".$this->db->lasterror();
    dol_syslog(get_class($this)."::select_auxaccount ".$this->error, LOG_ERR);
    return -1;
}
$this->db->free($resql);

// Auxiliary supplier account
$sql = "SELECT DISTINCT code_compta_fournisseur, nom ";
$sql .= " FROM ".MAIN_DB_PREFIX."societe";
$sql .= " WHERE entity IN (" . getEntity('societe') . ")";
$sql .= " ORDER BY code_compta_fournisseur";
dol_syslog(get_class($this)."::select_auxaccount", LOG_DEBUG);
$resql = $this->db->query($sql);
if ($resql) {
    while ($obj = $this->db->fetch_object($resql)) {
        if (!empty($obj->code_compta_fournisseur)) {
            $aux_account[$obj->code_compta_fournisseur] = $obj->code_compta_fournisseur.' ('.$obj->nom.')';
        }
    }
} else {
    $this->error = "Error ".$this->db->lasterror();
    dol_syslog(get_class($this)."::select_auxaccount ".$this->error, LOG_ERR);
    return -1;
}
$this->db->free($resql);

// Auxiliary user account
$sql = "SELECT DISTINCT accountancy_code, lastname, firstname ";
$sql .= " FROM ".MAIN_DB_PREFIX."user";
$sql .= " WHERE entity IN (" . getEntity('user') . ")";
$sql .= " ORDER BY accountancy_code";
dol_syslog(get_class($this)."::select_auxaccount", LOG_DEBUG);
$resql = $this->db->query($sql);
if ($resql) {
    while ($obj = $this->db->fetch_object($resql)) {
        if (!empty($obj->accountancy_code)) {
            $aux_account[$obj->accountancy_code] = $obj->accountancy_code.' ('.dolGetFirstLastname($obj->firstname, $obj->lastname).')';
        }
    }
} else {
    $this->error = "Error ".$this->db->lasterror();
    dol_syslog(get_class($this)."::select_auxaccount ".$this->error, LOG_ERR);
    return -1;
}
$this->db->free($resql);

//print $sql;
if (strlen(trim($search_subaccount)))		$sql .= natural_search("aa.subaccount_number", $search_subaccount);
if (strlen(trim($search_label)))			$sql .= natural_search("aa.label", $search_label);
$sql .= $db->order($sortfield, $sortorder);

// Count total nb of records
$nbtotalofrecords = '';
if (empty($conf->global->MAIN_DISABLE_FULL_SCANLIST))
{
	$resql = $db->query($sql);
	$nbtotalofrecords = $db->num_rows($resql);
	if (($page * $limit) > $nbtotalofrecords)	// if total resultset is smaller then paging size (filtering), goto and load page 0
	{
		$page = 0;
		$offset = 0;
	}
}

$sql .= $db->plimit($limit + 1, $offset);

dol_syslog('accountancy/admin/subaccount.php:: $sql=' . $sql);
$resql = $db->query($sql);

if ($resql)
{
	$num = $db->num_rows($resql);

    $param='';
	if (! empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) $param.='&contextpage='.$contextpage;
	if ($limit > 0 && $limit != $conf->liste_limit) $param.='&limit='.$limit;
	if ($search_account) $param.= '&search_subaccount='.urlencode($search_subaccount);
	if ($search_label) $param.= '&search_label='.urlencode($search_label);
    if ($optioncss != '') $param.='&optioncss='.$optioncss;


	print '<form method="POST" id="searchFormList" action="' . $_SERVER["PHP_SELF"] . '">';
	if ($optioncss != '') print '<input type="hidden" name="optioncss" value="'.$optioncss.'">';
	print '<input type="hidden" name="token" value="'.$_SESSION['newtoken'].'">';
	print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
	print '<input type="hidden" name="action" value="list">';
	print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
	print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
	print '<input type="hidden" name="page" value="'.$page.'">';
	print '<input type="hidden" name="contextpage" value="'.$contextpage.'">';

    $newcardbutton.= dolGetButtonTitle($langs->trans("New"), $langs->trans("Addanaccount"), 'fa fa-plus-circle', './card.php?action=create');


    print_barre_liste($langs->trans('ListOfSubAccounts'), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'title_accountancy', 0, $newcardbutton, '', $limit);

	$varpage=empty($contextpage)?$_SERVER["PHP_SELF"]:$contextpage;
    $selectedfields=$form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $varpage);	// This also change content of $arrayfields

    print '<div class="div-table-responsive">';
    print '<table class="tagtable liste'.($moreforfilter?" listwithfilterbefore":"").'">'."\n";

	// Line for search fields
	print '<tr class="liste_titre_filter">';
	if (! empty($arrayfields['aa.subaccount_number']['checked']))   print '<td class="liste_titre"><input type="text" class="flat" size="10" name="search_account" value="' . $search_account . '"></td>';
	if (! empty($arrayfields['aa.label']['checked']))			    print '<td class="liste_titre"><input type="text" class="flat" size="20" name="search_label" value="' . $search_label . '"></td>';
	print '<td class="liste_titre maxwidthsearch">';
	$searchpicto=$form->showFilterAndCheckAddButtons($massactionbutton?1:0, 'checkforselect', 1);
	print $searchpicto;
	print '</td>';
	print '</tr>';

    print '<tr class="liste_titre">';
	if (! empty($arrayfields['aa.subaccount_number']['checked']))	print_liste_field_titre($arrayfields['aa.subaccount_number']['label'], $_SERVER["PHP_SELF"], "aa.subaccount_number", "", $param, '', $sortfield, $sortorder);
	if (! empty($arrayfields['aa.label']['checked']))			    print_liste_field_titre($arrayfields['aa.label']['label'], $_SERVER["PHP_SELF"], "aa.label", "", $param, '', $sortfield, $sortorder);
	print_liste_field_titre($selectedfields, $_SERVER["PHP_SELF"], "", '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ');
	print "</tr>\n";

	$accountstatic = new AccountingAccount($db);

	$i=0;
	while ($i < min($num, $limit))
	{
		$obj = $db->fetch_object($resql);

		$accountstatic->id = $obj->rowid;
		$accountstatic->label = $obj->label;
		$accountstatic->subaccount_number = $obj->subaccount_number;

		print '<tr class="oddeven">';

		// Account number
		if (! empty($arrayfields['aa.subaccount_number']['checked']))
		{
			print "<td>";
			print $accountstatic->getNomUrl(1, 0, 0, '', 0, 1);
			print "</td>\n";
			if (! $i) $totalarray['nbfield']++;
		}

		// Account label
		if (! empty($arrayfields['aa.label']['checked']))
		{
			print "<td>";
			print $obj->label;
			print "</td>\n";
			if (! $i) $totalarray['nbfield']++;
		}

		// Action
		print '<td class="center">';
		if ($user->rights->accounting->chartofaccount) {
			print '<a href="./card.php?action=update&id=' . $obj->rowid . '&backtopage='.urlencode($_SERVER["PHP_SELF"].'?chartofaccounts='.$object->id).'">';
			print img_edit();
			print '</a>';
			print '&nbsp;';
			print '<a href="./card.php?action=delete&id=' . $obj->rowid . '&backtopage='.urlencode($_SERVER["PHP_SELF"].'?chartofaccounts='.$object->id). '">';
			print img_delete();
			print '</a>';
		}
		print '</td>' . "\n";
		if (! $i) $totalarray['nbfield']++;

		print "</tr>\n";
		$i++;
	}

	print "</table>";
	print "</div>";
	print '</form>';
} else {
	dol_print_error($db);
}

// End of page
llxFooter();
$db->close();
