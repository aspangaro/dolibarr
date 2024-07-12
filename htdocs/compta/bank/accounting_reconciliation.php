<?php
/* Copyright (C) 2024       Alexandre Spangaro  <alexandre@inovea-conseil.com>
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
 */

/**
 *	    \file       htdocs/compta/bank/accounting_reconciliation.php
 *      \ingroup    bank
 *		\brief      Page to reconcile accounting & bank
 */

// Load Dolibarr environment
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/bank.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';


// Load translation files required by the page
$langs->loadLangs(array("banks"));

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('account') ? GETPOSTINT('account') : GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$numref = GETPOST('num', 'alpha');
$backtopage = GETPOST('backtopage', 'alpha');

// Initialize technical object to manage hooks of page. Note that conf->hooks_modules contains array of hook context
$hookmanager->initHooks(array('bankaccountingreconciliation', 'globalcard'));

if ($user->hasRight('banque', 'consolidate') && $action == 'dvnext' && !empty($dvid)) {
	$al = new AccountLine($db);
	$al->datev_next($dvid);
}

if ($user->hasRight('banque', 'consolidate') && $action == 'dvprev' && !empty($dvid)) {
	$al = new AccountLine($db);
	$al->datev_previous($dvid);
}


$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	// If $page is not defined, or '' or -1 or if we click on clear filters
	$page = 0;
}
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;
if (!$sortorder) {
	$sortorder = "ASC";
}
if (!$sortfield) {
	$sortfield = "s.nom";
}

$object = new Account($db);
if ($id > 0 || !empty($ref)) {
	$result = $object->fetch($id, $ref);
	// if fetch from ref, $id may be empty
	$id = $object->id; // Force the search field on id of account
}

// Initialize technical object to manage context to save list fields
$contextpage = 'bankaccountingreconciliation'.(empty($object->ref) ? '' : '-'.$object->id);

// Security check
$fieldid = (!empty($ref) ? $ref : $id);
$fieldname = (!empty($ref) ? 'ref' : 'rowid');
if ($user->socid) {
	$socid = $user->socid;
}

$result = restrictedArea($user, 'banque', $fieldid, 'bank_account', '', '', $fieldname);

$error = 0;

// Define number of receipt to show (current, previous or next one ?)
$foundprevious = '';
$foundnext = '';
// Search previous receipt number
$sql = "SELECT b.num_releve as num";
$sql .= " FROM ".MAIN_DB_PREFIX."bank as b";
$sql .= " WHERE b.num_releve < '".$db->escape($numref)."'";
$sql .= " AND b.num_releve <> ''";
$sql .= " AND b.fk_account = ".((int) $object->id);
$sql .= " ORDER BY b.num_releve DESC";
$sql .= $db->plimit(1);

dol_syslog("htdocs/compta/bank/releve.php", LOG_DEBUG);
$resql = $db->query($sql);
if ($resql) {
	$numrows = $db->num_rows($resql);
	if ($numrows > 0) {
		$obj = $db->fetch_object($resql);
		if ($rel == 'prev') {
			$numref = $obj->num;
		}
		$foundprevious = $obj->num;
	}
} else {
	dol_print_error($db);
}
// Search next receipt
$sql = "SELECT b.num_releve as num";
$sql .= " FROM ".MAIN_DB_PREFIX."bank as b";
$sql .= " WHERE b.num_releve > '".$db->escape($numref)."'";
$sql .= " AND b.fk_account = ".((int) $object->id);
$sql .= " ORDER BY b.num_releve ASC";
$sql .= $db->plimit(1);

dol_syslog("htdocs/compta/bank/releve.php", LOG_DEBUG);
$resql = $db->query($sql);
if ($resql) {
	$numrows = $db->num_rows($resql);
	if ($numrows > 0) {
		$obj = $db->fetch_object($resql);
		if ($rel == 'next') {
			$numref = $obj->num;
		}
		$foundnext = $obj->num;
	}
} else {
	dol_print_error($db);
}

$sql = "SELECT b.rowid, b.dateo as do, b.datev as dv,";
$sql .= " b.amount, b.label, b.rappro, b.num_releve, b.num_chq, b.fk_type,";
$sql .= " b.fk_bordereau,";
$sql .= " bc.ref,";
$sql .= " ba.rowid as bankid, ba.ref as bankref, ba.label as banklabel";
$sql .= " FROM ".MAIN_DB_PREFIX."bank_account as ba,";
$sql .= " ".MAIN_DB_PREFIX."bank as b";
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'bordereau_cheque as bc ON bc.rowid=b.fk_bordereau';
$sql .= " WHERE b.num_releve = '".$db->escape($numref)."'";
if (empty($numref)) {
	$sql .= " OR b.num_releve is null";
}
$sql .= " AND b.fk_account = ".((int) $object->id);
$sql .= " AND b.fk_account = ba.rowid";
$sql .= " AND ba.entity IN (".getEntity($object->element).")";
$sql .= $db->order("b.datev, b.datec", "ASC"); // We add date of creation to have correct order when everything is done the same day

$sqlrequestforbankline = $sql;


/*
 * Actions
 */

if ($action == 'confirm_editbankreceipt' && !empty($oldbankreceipt) && !empty($newbankreceipt)) {
	// Test to check newbankreceipt does not exists yet
	$sqltest = "SELECT b.rowid FROM ".MAIN_DB_PREFIX."bank as b, ".MAIN_DB_PREFIX."bank_account as ba";
	$sqltest .= " WHERE b.fk_account = ba.rowid AND ba.entity = ".((int) $conf->entity);
	$sqltest .= " AND num_releve = '".$db->escape($newbankreceipt)."'";
	$sqltest .= $db->plimit(1);	// Need the first one only

	$resql = $db->query($sqltest);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj && $obj->rowid) {
			setEventMessages('ErrorBankReceiptAlreadyExists', null, 'errors');
			$error++;
		}
	} else {
		dol_print_error($db);
	}

	// Update bank receipt name
	if (!$error) {
		$sqlupdate = "UPDATE ".MAIN_DB_PREFIX."bank SET num_releve = '".$db->escape($newbankreceipt)."'";
		$sqlupdate .= " WHERE num_releve = '".$db->escape($oldbankreceipt)."' AND fk_account = ".((int) $id);

		$resql = $db->query($sqlupdate);
		if (!$resql) {
			dol_print_error($db);
		}
	}

	$action = 'view';
}


/*
 * View
 */

$form = new Form($db);

// Must be before button action
$param = '';
if (!empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) {
	$param .= '&contextpage='.$contextpage;
}
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit='.$limit;
}
if ($id > 0) {
	$param .= '&id='.urlencode((string) ($id));
}

if (empty($numref)) {
	$title = $object->ref.' - '.$langs->trans("Reconciliation").' '.$langs->trans("Accountant");
	$helpurl = "";
} else {
	$title = $langs->trans("FinancialAccount").' - '.$langs->trans("Reconciliation").' '.$langs->trans("Accountant");
	$helpurl = "";
}


llxHeader('', $title, $helpurl);

$balancestart = array();
$content = array();

/**
 *   Show list of record into a bank statement
 */

// Tabs
$head = account_statement_prepare_head($object, $numref);
print dol_get_fiche_head($head, 'accountingreconciliation', $langs->trans("Reconciliation") . ' ' . $langs->trans("Accountant"), -1, 'account');

$morehtmlright = '';
$morehtmlright .= '<div class="pagination"><ul>';
if ($foundprevious) {
	$morehtmlright .= '<li class="pagination"><a class="paginationnext" href="'.$_SERVER["PHP_SELF"].'?num='.urlencode($foundprevious).'&amp;ve='.urlencode($ve).'&amp;account='.((int) $object->id).'"><i class="fa fa-chevron-left" title="'.dol_escape_htmltag($langs->trans("Previous")).'"></i></a></li>';
}
$morehtmlright .= '<li class="pagination"><span class="active">'.$langs->trans("AccountStatement")." ".$numref.'</span></li>';
if ($foundnext) {
	$morehtmlright .= '<li class="pagination"><a class="paginationnext" href="'.$_SERVER["PHP_SELF"].'?num='.urlencode($foundnext).'&amp;ve='.urlencode($ve).'&amp;account='.((int) $object->id).'"><i class="fa fa-chevron-right" title="'.dol_escape_htmltag($langs->trans("Next")).'"></i></a></li>';
}
$morehtmlright .= '</ul></div>';

$title = $langs->trans("AccountStatement").' '.$numref.' - '.$langs->trans("BankAccount").' '.$object->getNomUrl(1, 'receipts');
print load_fiche_titre($title, $morehtmlright, '');

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td class="right">'.$langs->trans("FinalBalanceShownOnSoftware").'</td>';

// Calculate end amount
$sql = "SELECT sum(b.amount) as amount";
$sql .= " FROM ".MAIN_DB_PREFIX."bank as b";
$sql .= " WHERE b.num_releve = '".$db->escape($numref)."'";
$sql .= " AND b.fk_account = ".((int) $object->id);
$resqlend = $db->query($sql);
if ($resqlend) {
    $obj = $db->fetch_object($resqlend);
    $content[$numref] = $obj->amount;
    $db->free($resqlend);
}
print '<td class="right"><span class="amount nowraponall">'.price(($balancestart[$numref] + $content[$numref]), 0, $langs, 1, -1, -1, empty($object->currency_code) ? $conf->currency : $object->currency_code).'</span></td>';
print '</tr>';
print '</table>';
print '</div>';

print '<br>';
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';

$total = 0;
// list of bank line wasn't conciliated
$sql = "SELECT b.rowid, b.dateo as do, b.datev as dv, b.amount, b.label, b.rappro as conciliated, b.num_releve, b.num_chq,";
$sql .= " b.fk_account, b.fk_type, b.fk_bordereau,";
$sql .= " ba.rowid as bankid, ba.ref as bankref";
$sql .= " FROM ".MAIN_DB_PREFIX."bank_account as ba,";
$sql .= " ".MAIN_DB_PREFIX."bank as b";
$sql .= " WHERE b.fk_account = ba.rowid";
$sql .= " AND ba.entity IN (".getEntity('bank_account').")";
$sql .= " AND b.num_releve IS NULL";
$sql .= " AND b.fk_account = ".((int) $object->id);

/*
$sql .= " UNION";
// List of bank line wasn't transfer in accounting
$sql .= " SELECT b.rowid, b.dateo as do, b.datev as dv, b.amount, b.label, b.rappro as conciliated, b.num_releve, b.num_chq,";
$sql .= " b.fk_account, b.fk_type, b.fk_bordereau,";
$sql .= " ba.rowid as bankid, ba.ref as bankref";
$sql .= " FROM ".MAIN_DB_PREFIX."bank_account as ba,";
$sql .= " ".MAIN_DB_PREFIX."bank as b";
$sql .= " WHERE b.fk_account = ba.rowid";
$sql .= " AND ba.entity IN (".getEntity('bank_account').")";
$sql .= " AND b.num_releve IS NULL";
$sql .= " AND b.fk_account = ".((int) $object->id);
*/

$resql = $db->query($sql);

if ($resql) {
    $num = $db->num_rows($resql);
    $i = 0;

    print '<td class="center">'.$langs->trans("Date").'</td>';
    print '<td class="left">'.$langs->trans("Piece").'</td>';
    print '<td class="center">'.$langs->trans("Description").'</td>';
    print '<td class="center">'.$langs->trans("Debit").'</td>';
    print '<td class="center">'.$langs->trans("Credit").'</td>';
    print '<td class="center">'.$langs->trans("P").'</td>';
    print '</tr>';

    while ($i < min($num, $limit)) {
        $obj = $db->fetch_object($resql);

        print '<tr class="oddeven">';
        print '<td class="center">' . dol_print_date($db->jdate($obj->do)) . '&nbsp;</td>';
        print '<td class="left">' . $obj->rowid . '&nbsp;</td>';
        print '<td class="left">' . $obj->label . '&nbsp;</td>';
        if ($obj->amount < 0) {
            print '<td class="right nowraponall">' . price(price2num(abs($obj->amount), 'MT'), 1, $langs) . '</td>';
        } else {
            print '<td class="right nowraponall"></td>';
        }
        if ($obj->amount > 0) {
            print '<td class="right nowraponall">' . price(price2num(abs($obj->amount), 'MT'), 1, $langs) . '</td>';
        } else {
            print '<td class="right nowraponall"></td>';
        }
        if ($obj->conciliated == 0) {
            $conciliated = '';
        } else {
            $conciliated = 'X';
        }
        print '<td class="center">' . $conciliated . '&nbsp;</td>';
        print "</tr>\n";

        if ($obj->amount < 0) {
            $total_debit += price2num(abs($obj->amount), 'MT');
        }
        if ($obj->amount > 0) {
            $total_credit += price2num(abs($obj->amount), 'MT');
        }
        $i++;
    }

    print '<td colspan="3">'.$langs->trans("Total").'</td>';
    print '<td class="nowrap right"><span class="amount">'.price(price2num(abs($total_debit), 'MT'), 1, $langs).'</span></td>';
    print '<td class="nowrap right"><span class="amount">'.price(price2num(abs($total_credit), 'MT'), 1, $langs).'</span></td>';
    print '<td> </td>';
}

/*
if ($bankaccount->canBeConciliated() > 0 && empty($objp->conciliated)) {
    if ($db->jdate($objp->dv) < ($now - $conf->bank->rappro->warning_delay)) {
        print ' '.img_warning($langs->trans("ReconciliationLate"));
    }
}
*/

if ($result < 0) {
    setEventMessages($result->error, $result->errors, 'errors');
} else {
    print $result;
}

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="add">';

// End of page
llxFooter();
$db->close();
