<?php
/*
 *********************************************************************************************************
 * daloRADIUS - RADIUS Web Platform
 * Copyright (C) 2007 - Liran Tal <liran@enginx.com> All Rights Reserved.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 *********************************************************************************************************
 *
 * Authors:    Liran Tal <liran@enginx.com>
 *             Filippo Lauria <filippo.lauria@iit.cnr.it>
 *
 *********************************************************************************************************
 */

    include("library/checklogin.php");
    $operator = $_SESSION['operator_user'];

    include('library/check_operator_perm.php');
    include_once('library/config_read.php');
    
    include_once("lang/main.php");
    include("library/validation.php");
    include("library/layout.php");
    
    // init logging variables
    $log = "visited page: ";
    $logQuery = "performed query on page: ";
    $logDebugSQL = "";
    
    // print HTML prologue
    $title = t('Intro','replastconnect.php');
    $help = t('helpPage','replastconnect');
    
    print_html_prologue($title, $langCode);

    // setting table-related parameters first
    switch($configValues['FREERADIUS_VERSION']) {
    case '1':
        $tableSetting['postauth']['user'] = 'user';
        $tableSetting['postauth']['date'] = 'date';
        break;
        
    case '2':
    case '3':
    default:
        $tableSetting['postauth']['user'] = 'username';
        $tableSetting['postauth']['date'] = 'authdate';
        break;
    }
    
    // in other cases we just check that syntax is ok
    $startdate = (array_key_exists('startdate', $_GET) && isset($_GET['startdate']) &&
                  preg_match(DATE_REGEX, $_GET['startdate'], $m) !== false &&
                  checkdate($m[2], $m[3], $m[1]))
               ? $_GET['startdate'] : date("Y-m-01");

    $enddate = (array_key_exists('enddate', $_GET) && isset($_GET['enddate']) &&
                preg_match(DATE_REGEX, $_GET['enddate'], $m) !== false &&
                checkdate($m[2], $m[3], $m[1]))
             ? $_GET['enddate'] : date("Y-m-01", mktime(0, 0, 0, date('n') + 1, 1, date('Y')));

    $radiusReply = (array_key_exists('radiusReply', $_GET) && !empty(trim($_GET['radiusReply'])) &&
                    in_array(trim($_GET['radiusReply']), $valid_radiusReplys))
                 ? trim($_GET['radiusReply']) : $valid_radiusReplys[0];

    // and in other cases we partially strip some character,
    // and leave validation/escaping to other functions used later in the script
    $username = (array_key_exists('username', $_GET) && !empty(str_replace("%", "", trim($_GET['username']))))
              ? str_replace("%", "", trim($_GET['username'])) : "";
    $username_enc = (!empty($username)) ? htmlspecialchars($username, ENT_QUOTES, 'UTF-8') : "";
    
    $hiddenPassword = (strtolower($configValues['CONFIG_IFACE_PASSWORD_HIDDEN']) == "yes");
    
    // the array $cols has multiple purposes:
    // - its keys (when non-numerical) can be used
    //   - for validating user input
    //   - for table ordering purpose
    // - its value can be used for table headings presentation
    $cols = array( 
                   "fullname" => t('all','Name'),
                   $tableSetting['postauth']['user'] => t('all','Username'),
                 );
    
    if (!$hiddenPassword) {
        $cols["pass"] = t('all','Password');
    }
    
    $date_label = $tableSetting['postauth']['date'];
    $cols[$date_label] = t('all','StartTime');
    
    if ($radiusReply == 'Any') {
        $cols["reply"] = t('all','RADIUSReply');
    } else {
        $cols[] = t('all','RADIUSReply');
    }
    
    $colspan = count($cols);
    $half_colspan = intval($colspan / 2);

    // validating user passed parameters

    $default_orderBy = array_keys($cols)[count($cols) - 2];
    $default_orderType = "desc";
    
    // whenever possible we use a whitelist approach
    $orderBy = (array_key_exists('orderBy', $_GET) && isset($_GET['orderBy']) &&
                in_array($_GET['orderBy'], array_keys($cols)))
             ? $_GET['orderBy'] : $default_orderBy;

    $orderType = (array_key_exists('orderType', $_GET) && isset($_GET['orderType']) &&
                  preg_match(ORDER_TYPE_REGEX, $_GET['orderType']) !== false)
               ? strtolower($_GET['orderType']) : $default_orderType;
    
    include("include/menu/sidebar.php");

    echo '<div id="contentnorightbar">';
    print_title_and_help($title, $help);


    include('include/management/pages_common.php');
    include('library/opendb.php');

    // pa is a placeholder in the SQL statements below
    // except for $username, which has been only partially escaped,
    // all other query parameters have been validated earlier.
    $sql_WHERE = array();
    if (!empty($username)) {
        $sql_WHERE[] = sprintf("pa.%s LIKE '%s%%'", $tableSetting['postauth']['user'],
                                                    $dbSocket->escapeSimple($username));
    }
    $sql_WHERE[] = sprintf("pa.%s BETWEEN '%s' AND '%s'", $tableSetting['postauth']['date'],
                                                          $dbSocket->escapeSimple($startdate),
                                                          $dbSocket->escapeSimple($enddate));
    if ($radiusReply != "Any") {
        $sql_WHERE[] = sprintf("pa.reply='%s'", $dbSocket->escapeSimple($radiusReply));
    }
    
    // setup php session variables for exporting
    $_SESSION['reportTable'] = sprintf("%s AS pa LEFT JOIN %s AS ui ON pa.%s = ui.username",
                                       $configValues['CONFIG_DB_TBL_RADPOSTAUTH'],
                                       $configValues['CONFIG_DB_TBL_DALOUSERINFO'],
                                       $tableSetting['postauth']['user']);
    $_SESSION['reportQuery'] = " WHERE " . implode(" AND ", $sql_WHERE);
    $_SESSION['reportType'] = "reportsLastConnectionAttempts";

    
    $sql = sprintf("SELECT CONCAT(COALESCE(ui.firstname, ''), ' ', COALESCE(ui.lastname, '')) AS fullname,
                           pa.%s AS username, pa.pass, pa.reply, pa.%s
                      FROM %s %s", $tableSetting['postauth']['user'], $tableSetting['postauth']['date'],
                                   $_SESSION['reportTable'], $_SESSION['reportQuery']);
    
    $res = $dbSocket->query($sql);
    $numrows = $res->numRows();
    
    if ($numrows > 0) {
        /* START - Related to pages_numbering.php */
        
        // when $numrows is set, $maxPage is calculated inside this include file
        include('include/management/pages_numbering.php');    // must be included after opendb because it needs to read
                                                              // the CONFIG_IFACE_TABLES_LISTING variable from the config file
        
        // here we decide if page numbers should be shown
        $drawNumberLinks = strtolower($configValues['CONFIG_IFACE_TABLES_LISTING_NUM']) == "yes" && $maxPage > 1;
        
        /* END */
        
        $sql .= sprintf(" ORDER BY %s %s LIMIT %s, %s", $orderBy, $orderType, $offset, $rowsPerPage);
        $res = $dbSocket->query($sql);
        $logDebugSQL = "$sql;\n";

        $per_page_numrows = $res->numRows();

        // the partial query is built starting from user input
        // and for being passed to setupNumbering and setupLinks functions
        $partial_query_params = array();
        if (!empty($startdate)) {
            $partial_query_params[] = sprintf("startdate=%s", $startdate);
        }
        if (!empty($enddate)) {
            $partial_query_params[] = sprintf("enddate=%s", $enddate);
        }
        if (!empty($username_enc)) {
            $partial_query_params[] = sprintf("username=%s", urlencode($username_enc));
        }
        if (!empty($radiusReply)) {
            $partial_query_params[] = sprintf("radiusReply=%s", $radiusReply);
        }
        
        $partial_query_string = ((count($partial_query_params) > 0) ? "&" . implode("&", $partial_query_params)  : "");

?>
    <table border="0" class="table1">
        <thead>
            <tr style="background-color: white">
<?php
        // page numbers are shown only if there is more than one page
        if ($drawNumberLinks) {
            printf('<td style="text-align: left" colspan="%s">go to page: ', $half_colspan + ($colspan % 2));
            setupNumbering($numrows, $rowsPerPage, $pageNum, $orderBy, $orderType, $partial_query_string);
            echo '</td>';
        }
?>
                <td style="text-align: right" colspan="<?= ($drawNumberLinks) ? $half_colspan : $colspan ?>">
                    <input class="button" type="button" value="CSV Export"
                        onclick="location.href='include/management/fileExport.php?reportFormat=csv'">
                </td>

            </tr>
            
            <tr>
<?php
        printTableHead($cols, $orderBy, $orderType, $partial_query_string);
?>
            </tr>
        </thead>

        <tbody>
<?php
        $td_format = '<td>%s</td>';
        
        while ($row = $res->fetchRow()) {
            $rowlen = count($row);
            
            // escape row elements
            for ($i = 0; $i < $rowlen; $i++) {
                $row[$i] = htmlspecialchars(trim($row[$i]), ENT_QUOTES, 'UTF-8');
            }

            // The table that is being produced is in the format of:
            // +-------------+-------------+---------------+-----------+-----------+
            // | fullname    | user        | pass (opt.)   | reply     | date      |   
            // +-------------+-------------+---------------+-----------+-----------+

            list($fullname, $user, $pass, $reply, $starttime) = $row;

            echo "<tr>";
            
            printf($td_format, (!empty($fullname) ? $fullname : "(n/a)"));
            printf($td_format, $user);
            if (!$hiddenPassword) {
                printf($td_format, $pass);
            }
            printf($td_format, $starttime);
            
            $reply = sprintf('<span style="color: %s">%s</span>',
                             (($reply == "Access-Reject") ? "red" : "green"), $reply);
            printf($td_format, $reply);
            
            echo "</tr>";

        }
?>
        </tbody>

<?php
        $links = setupLinks_str($pageNum, $maxPage, $orderBy, $orderType, $partial_query_string);
        printTableFoot($per_page_numrows, $numrows, $colspan, $drawNumberLinks, $links);
?>

    </table>
<?php
    } else {
        $failureMsg = "Nothing to display";
        include_once("include/management/actionMessages.php");
    }
    
    include('library/closedb.php');
    
    include('include/config/logging.php');
    print_footer_and_html_epilogue();
?>
