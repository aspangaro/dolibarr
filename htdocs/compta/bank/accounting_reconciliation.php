<?php
/* Copyright (C) 2024		Alexandre Spangaro			<alexandre@inovea-conseil.com>
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
require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingjournal.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/sociales/class/chargesociales.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/tva/class/tva.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/cheque/class/remisecheque.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/don/class/paymentdonation.class.php';
require_once DOL_DOCUMENT_ROOT.'/loan/class/paymentloan.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/paymentvarious.class.php';
//show files
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';

// Load translation files required by the page
$langs->loadLangs(array("banks", "categories", "companies", "bills", "trips", "donations", "loan", "salaries"));

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('account') ? GETPOSTINT('account') : GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$dvid = GETPOST('dvid', 'alpha');
$numref = GETPOST('num', 'alpha');
$ve = GETPOST("ve", 'alpha');
$brref = GETPOST('brref', 'alpha');
$oldbankreceipt = GETPOST('oldbankreceipt', 'alpha');
$newbankreceipt = GETPOST('newbankreceipt', 'alpha');
$rel = GETPOST("rel", 'alphanohtml');
$backtopage = GETPOST('backtopage', 'alpha');

// Initialize a technical object to manage hooks of page. Note that conf->hooks_modules contains an array of hook context
$hookmanager->initHooks(array('bankaccountstatement', 'globalcard'));

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

// Initialize a technical object to manage context to save list fields
$contextpage = 'banktransactionlist'.(empty($object->ref) ? '' : '-'.$object->id);

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
$sql .= " b.amount, b.label, b.rappro as conciliated, b.num_releve, b.num_chq, b.fk_type,";
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

if ($action == 'confirm_editbankreceipt' && !empty($oldbankreceipt) && !empty($newbankreceipt) && $user->hasRight('banque', 'consolidate')) {
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
$societestatic = new Societe($db);
$chargestatic = new ChargeSociales($db);
$memberstatic = new Adherent($db);
$paymentstatic = new Paiement($db);
$paymentsupplierstatic = new PaiementFourn($db);
$paymentvatstatic = new Tva($db);
$bankstatic = new Account($db);
$banklinestatic = new AccountLine($db);
$remisestatic = new RemiseCheque($db);
$paymentdonationstatic = new PaymentDonation($db);
$paymentloanstatic = new PaymentLoan($db);
$paymentvariousstatic = new PaymentVarious($db);

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
    $title = $object->ref.' - '.$langs->trans("AccountStatements");
    $helpurl = "";
} else {
    $title = $langs->trans("FinancialAccount").' - '.$langs->trans("AccountStatements");
    $helpurl = "";
}


llxHeader('', $title, $helpurl);


    /**
     *   Show list of record into a bank statement
     */

    // Tabs
    $head = account_statement_prepare_head($object, $numref);
    print dol_get_fiche_head($head, 'accountingreconciliation', $langs->trans("AccountStatement"), -1, 'account');


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

$accountingjournalstatic = new AccountingJournal($db);
$accountingjournalstatic->fetch(0, $object->accountancy_journal);

    $title = $langs->trans("AccountStatement").' '.$numref.' - '.$langs->trans("BankAccount").' '.$object->getNomUrl(1, 'receipts') . ' - Journal : ' . $accountingjournalstatic->getNomUrl(0, 1, 1);
    print load_fiche_titre($title, $morehtmlright, '');

    print '<div class="div-table-responsive">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td class="center">'.$langs->trans("DateOperationShort").'</td>';
    print '<td>'.$langs->trans("Type").'</td>';
    print '<td>'.$langs->trans("Description").'</td>';
    print '<td class="right" width="60">'.$langs->trans("Debit").'</td>';
    print '<td class="right" width="60">'.$langs->trans("Credit").'</td>';
    print '<td class="center">'.$langs->trans("P").'</td>';
    print "</tr>\n";

    $totalc = $totald = $total = 0;
    $ok_conciliated = 0;

    // Recherche les ecritures pour le releve
    $sql = $sqlrequestforbankline;

    $resql = $db->query($sql);
    if ($resql) {
        $num = $db->num_rows($resql);
        $i = 0;

        while ($i < $num) {
            $objp = $db->fetch_object($resql);
            $total += $objp->amount;

            print '<tr class="oddeven">';

            // Date operation
            print '<td class="nowrap center">'.dol_print_date($db->jdate($objp->do), "day").'</td>';

            // Type and num
            if ($objp->fk_type == 'SOLD') {
                $type_label = '&nbsp;';
            } else {
                $type_label = ($langs->trans("PaymentTypeShort".$objp->fk_type) != "PaymentTypeShort".$objp->fk_type) ? $langs->trans("PaymentTypeShort".$objp->fk_type) : $objp->fk_type;
            }
            $link = '';
            if ($objp->fk_bordereau > 0) {
                $remisestatic->id = $objp->fk_bordereau;
                $remisestatic->ref = $objp->ref;
                $link = ' '.$remisestatic->getNomUrl(1);
            }
            print '<td class="nowrap">'.$type_label.' '.($objp->num_chq ? $objp->num_chq : '').$link.'</td>';

            // Description
            print '<td valign="center">';
            print '<a href="'.DOL_URL_ROOT.'/compta/bank/line.php?rowid='.$objp->rowid.'&account='.$object->id.'">';
            $reg = array();

            preg_match('/\((.+)\)/i', $objp->label, $reg); // If text rounded by parenthesis, we try to search translation
            if (!empty($reg[1]) && $langs->trans($reg[1]) != $reg[1]) {
                print $langs->trans($reg[1]);
            } else {
                print dol_escape_htmltag($objp->label);
            }
            print '</a>';

            /*
             * Add links under the label (link to payment, company, user, social contribution...)
             */
            $newline = 1;
            $links = $object->get_url($objp->rowid);
            foreach ($links as $key => $val) {
                if (!$newline) {
                    print ' - ';
                } else {
                    print '<br>';
                }
                if ($links[$key]['type'] == 'payment') {
                    $paymentstatic->id = $links[$key]['url_id'];
                    $paymentstatic->ref = $langs->trans("Payment");
                    print ' '.$paymentstatic->getNomUrl(1);
                    $newline = 0;
                } elseif ($links[$key]['type'] == 'payment_supplier') {
                    $paymentsupplierstatic->id = $links[$key]['url_id'];
                    $paymentsupplierstatic->ref = $langs->trans("Payment");
                    print ' '.$paymentsupplierstatic->getNomUrl(1);
                    $newline = 0;
                } elseif ($links[$key]['type'] == 'payment_sc') {
                    print '<a href="'.DOL_URL_ROOT.'/compta/payment_sc/card.php?id='.$links[$key]['url_id'].'">';
                    print ' '.img_object($langs->trans('ShowPayment'), 'payment').' ';
                    print $langs->trans("SocialContributionPayment");
                    print '</a>';
                    $newline = 0;
                } elseif ($links[$key]['type'] == 'payment_vat') {
                    $paymentvatstatic->id = $links[$key]['url_id'];
                    $paymentvatstatic->ref = $langs->trans("Payment");
                    print ' '.$paymentvatstatic->getNomUrl(1);
                } elseif ($links[$key]['type'] == 'payment_salary') {
                    print '<a href="'.DOL_URL_ROOT.'/salaries/card.php?id='.$links[$key]['url_id'].'">';
                    print ' '.img_object($langs->trans('ShowPayment'), 'payment').' ';
                    print $langs->trans("Payment");
                    print '</a>';
                    $newline = 0;
                } elseif ($links[$key]['type'] == 'payment_donation') {
                    $paymentdonationstatic->id = $links[$key]['url_id'];
                    $paymentdonationstatic->ref = $langs->trans("Payment");
                    print ' '.$paymentdonationstatic->getNomUrl(1);
                    $newline = 0;
                } elseif ($links[$key]['type'] == 'payment_loan') {
                    $paymentloanstatic->id = $links[$key]['url_id'];
                    $paymentloanstatic->ref = $langs->trans("Payment");
                    print ' '.$paymentloanstatic->getNomUrl(1);
                    $newline = 0;
                } elseif ($links[$key]['type'] == 'payment_various') {
                    $paymentvariousstatic->id = $links[$key]['url_id'];
                    $paymentvariousstatic->ref = $langs->trans("Payment");
                    print ' '.$paymentvariousstatic->getNomUrl(1);
                    $newline = 0;
                } elseif ($links[$key]['type'] == 'banktransfert') {
                    // Do not show link to transfer since there is no transfer card (avoid confusion). Can already be accessed from transaction detail.
                    if ($objp->amount > 0) {
                        $banklinestatic->fetch($links[$key]['url_id']);
                        $bankstatic->id = $banklinestatic->fk_account;
                        $bankstatic->label = $banklinestatic->bank_account_label;
                        print ' ('.$langs->trans("from").' ';
                        print $bankstatic->getNomUrl(1, 'transactions');
                        print ' '.$langs->trans("toward").' ';
                        $bankstatic->id = $objp->bankid;
                        $bankstatic->label = $objp->bankref;
                        print $bankstatic->getNomUrl(1, '');
                        print ')';
                    } else {
                        $bankstatic->id = $objp->bankid;
                        $bankstatic->label = $objp->bankref;
                        print ' ('.$langs->trans("from").' ';
                        print $bankstatic->getNomUrl(1, '');
                        print ' '.$langs->trans("toward").' ';
                        $banklinestatic->fetch($links[$key]['url_id']);
                        $bankstatic->id = $banklinestatic->fk_account;
                        $bankstatic->label = $banklinestatic->bank_account_label;
                        print $bankstatic->getNomUrl(1, 'transactions');
                        print ')';
                    }
                } elseif ($links[$key]['type'] == 'company') {
                    $societestatic->id = $links[$key]['url_id'];
                    $societestatic->name = $links[$key]['label'];
                    print $societestatic->getNomUrl(1, 'company', 24);
                    $newline = 0;
                } elseif ($links[$key]['type'] == 'member') {
                    print '<a href="'.DOL_URL_ROOT.'/adherents/card.php?rowid='.$links[$key]['url_id'].'">';
                    print img_object($langs->trans('ShowMember'), 'user').' ';
                    print $links[$key]['label'];
                    print '</a>';
                    $newline = 0;
                } elseif ($links[$key]['type'] == 'user') {
                    print '<a href="'.DOL_URL_ROOT.'/user/card.php?id='.$links[$key]['url_id'].'">';
                    print img_object($langs->trans('ShowUser'), 'user').' ';
                    print $links[$key]['label'];
                    print '</a>';
                    $newline = 0;
                } elseif ($links[$key]['type'] == 'sc') {
                    print '<a href="'.DOL_URL_ROOT.'/compta/sociales/card.php?id='.$links[$key]['url_id'].'">';
                    print img_object($langs->trans('ShowBill'), 'bill').' ';
                    print $langs->trans("SocialContribution");
                    print '</a>';
                    $newline = 0;
                } else {
                    print '<a href="'.$links[$key]['url'].$links[$key]['url_id'].'">';
                    print $links[$key]['label'];
                    print '</a>';
                    $newline = 0;
                }
            }

            // Categories
            if ($ve) {
                $sql = "SELECT label";
                $sql .= " FROM ".MAIN_DB_PREFIX."categorie as ct";
                $sql .= ", ".MAIN_DB_PREFIX."category_bankline as cl";
                $sql .= " WHERE ct.rowid = cl.fk_categ";
                $sql .= " AND ct.entity = ".((int) $conf->entity);
                $sql .= " AND cl.lineid = ".((int) $objp->rowid);

                $resc = $db->query($sql);
                if ($resc) {
                    $numc = $db->num_rows($resc);
                    $ii = 0;
                    if ($numc && !$newline) {
                        print '<br>';
                    }
                    while ($ii < $numc) {
                        $objc = $db->fetch_object($resc);
                        print "<br>-&nbsp;<i>".$objc->label."</i>";
                        $ii++;
                    }
                } else {
                    dol_print_error($db);
                }
            }

            print "</td>";

            if ($objp->amount < 0) {
                $totald += abs($objp->amount);
                print '<td class="nowrap right">'.price($objp->amount * -1)."</td>";
                print "<td>&nbsp;</td>\n";
            } else {
                $totalc += abs($objp->amount);
                print '<td>&nbsp;</td>';
                print '<td class="nowrap right">'.price($objp->amount)."</td>\n";
            }

            if ($objp->conciliated == 0) {
                $conciliated = '';
            } else {
                $conciliated = 'X';
                $ok_conciliated += 1;
            }
            print '<td class="center">' . $conciliated . '&nbsp;</td>';
            print "</tr>\n";

            $i++;
        }
        $db->free($resql);
    } else {
        dol_print_error($db);
    }

    // Line Total
    print "\n".'<tr class="liste_total"><td class="right" colspan="3">'.$langs->trans("Total").' :</td><td class="right">'.price($totald).'</td><td class="right">'.price($totalc)."</td>";
    print '<td class="liste_titre center width100"><span class="badge marginleftonly" title="'.$langs->trans("BankLineReconciled").'">'.$ok_conciliated.'</span></td>';
    print "</tr>";

    // Line Solde
    print "\n".'<tr class="liste_total"><td class="left" colspan="2">'. $langs->trans("BankLinesConciliated", $ok_conciliated);
    print '<td class="right">'.$langs->trans("Solde").' :</td>';
    $solde = 0;
    $solde = $totalc - $totald;
    if ($solde < 0) {
        print '<td class="nowrap right"><span class="amount">'.price(price2num(abs($solde), 'MT'), 1, $langs).'</span></td>';
        print '<td>&nbsp;</td>';
    } else {
        print '<td>&nbsp;</td>';
        print '<td class="nowrap right"><span class="amount">'.price(price2num(abs($solde), 'MT'), 1, $langs).'</span></td>';
    }
	print '<td>&nbsp;</td>';
    print "</tr>";
    print "</table>";

    print "</div>";

/**
 *   Summary
 */
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("SummaryBankStatementBalance").'</td>';
print '<td class="right" width="120px">'.$langs->trans("Debit").'</td>';
print '<td class="right" width="120px">'.$langs->trans("Credit").'</td>';
print "</tr>";

// Calculate start amount
print '<tr class="oddeven">';
$sql = "SELECT sum(b.amount) as amount";
$sql .= " FROM ".MAIN_DB_PREFIX."bank as b";
$sql .= " WHERE b.num_releve < '".$db->escape($numref)."'";
$sql .= " AND b.num_releve <> ''";
$sql .= " AND b.fk_account = ".((int) $object->id);
$resqlstart = $db->query($sql);
if ($resqlstart) {
    $obj = $db->fetch_object($resqlstart);
    $balancestart[$numref] = $obj->amount;
    $db->free($resqlstart);
}
print '<td>'.$langs->trans("StartBalanceShownOnSoftware").'</td>';
if ($balancestart[$numref] < 0) {
    print '<td class="right amount nowraponall">'.abs(price($balancestart[$numref])).'</span></td>';
    print "<td>&nbsp;</td>\n";
} else {
    print "<td>&nbsp;</td>\n";
    print '<td class="right amount nowraponall">'.price($balancestart[$numref]).'</span></td>';
}
print "</tr>";

// Show total movement reconciled
print "\n".'<tr class="oddeven">';
print '<td>'.$langs->trans("BankLineConciliated").'</td>';
print '<td class="right amount nowraponall">'.price($totald).'</td>';
print '<td class="right amount nowraponall">'.price($totalc).'</td>';

// Calculate end amount
print '<tr class="oddeven">';
$sql = "SELECT sum(b.amount) as amount";
$sql .= " FROM ".MAIN_DB_PREFIX."bank as b";
$sql .= " WHERE b.num_releve = '".$db->escape($numref)."'";
$sql .= " AND b.fk_account = ".((int) $object->id);
$resqlend = $db->query($sql);
if ($resqlend) {
    $obj = $db->fetch_object($resqlend);
    $balanceend[$numref] = $obj->amount;
    $db->free($resqlend);
}

print '<td>'.$langs->trans("FinalBalanceShownOnSoftware").'</td>';
if ($balanceend[$numref] < 0) {
    print '<td class="right amount nowraponall">'.abs(price($balanceend[$numref])).'</span></td>';
    print "<td>&nbsp;</td>\n";
} else {
    print "<td>&nbsp;</td>\n";
    print '<td class="right amount nowraponall">'.price($balanceend[$numref]).'</span></td>';
}
print "</tr>";

// Unreconciled bank entries
// list of bank line wasn't conciliated
$total_debit_not_conciliated = 0;
$total_credit_not_conciliated = 0;

/*
$sql = "SELECT b.rowid, b.dateo as do, b.datev as dv, b.amount, b.label, b.rappro as conciliated, b.num_releve, b.num_chq,";
$sql .= " b.fk_account, b.fk_type, b.fk_bordereau,";
$sql .= " ba.rowid as bankid, ba.ref as bankref";
$sql .= " FROM ".MAIN_DB_PREFIX."bank_account as ba,";
$sql .= " ".MAIN_DB_PREFIX."bank as b";
$sql .= " WHERE b.fk_account = ba.rowid";
$sql .= " AND ba.entity IN (".getEntity('bank_account').")";
$sql .= " AND b.num_releve IS NULL";
$sql .= " AND b.fk_account = " . ((int) $object->id);
$sql .= " AND b.amount != '0'";
$sql .= $db->order("b.amount", "ASC");

$resql = $db->query($sql);

if ($resql) {
    $num = $db->num_rows($resql);
    $i = 0;

    while ($i < min($num, $limit)) {
        $obj = $db->fetch_object($resql);

        if ($obj->amount < 0) {
            $total_debit_not_conciliated += price2num(abs($obj->amount), 'MT');
        }
        if ($obj->amount > 0) {
            $total_credit_not_conciliated += price2num(abs($obj->amount), 'MT');
        }
    }
    $i++;
}

print '<tr class="oddeven">';
print '<td>'.$langs->trans("UnreconciledBankEntries").'</td>';
print '<td class="right amount nowraponall">'.abs(price($total_debit_not_conciliated)).'</span></td>';
print '<td class="right amount nowraponall">'.price($total_credit_not_conciliated).'</span></td>';
print "</tr>";
*/

print "</table>";
print "</div>";

print '<br>';

/**
 *   Summary
 */
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("SummaryAccountBalance").'</td>';
print '<td class="right" width="120px">'.$langs->trans("Debit").'</td>';
print '<td class="right" width="120px">'.$langs->trans("Credit").'</td>';
print "</tr>";

$bankstatic->fetch($object->id);

// Calculate end amount
$sql2 = "SELECT SUM(a.debit) AS total_debit , SUM(a.credit) AS total_credit";
$sql2 .= " FROM ".MAIN_DB_PREFIX."accounting_bookkeeping AS a";
$sql2 .= " WHERE a.numero_compte = " . ((int) $bankstatic->account_number);

$resqlacc = $db->query($sql2);
if ($resqlacc) {
    $objacc = $db->fetch_object($resqlacc);
    $contentacc[$numref] = $objacc->total_debit - $objacc->total_credit;
    $db->free($resqlacc);
}

print '<tr class="oddeven">';
print '<td>'.$langs->trans("FinalBalanceShownOnAccounting"). ' ' . $bankstatic->account_number . '</td>';

if ($contentacc[$numref] < 0) {
    print '<td class="right amount nowraponall">'.abs(price($contentacc[$numref])).'</span></td>';
    print "<td>&nbsp;</td>\n";
} else {
    print "<td>&nbsp;</td>\n";
    print '<td class="right amount nowraponall">'.price($contentacc[$numref]).'</span></td>';
}
//print '<td class="right"><span class="amount nowraponall">'.price(($balanceaccstart[$numref] + $contentacc[$numref]), 0, $langs, 1, -1, -1, empty($object->currency_code) ? $conf->currency : $object->currency_code).'</span></td>';
print '</tr>';
print '</table>';
print '</div>';


// End of page
llxFooter();
$db->close();
