<?php
/* $Id$ */
// vim: expandtab sw=4 ts=4 sts=4:

/**
 * Common Option Constants For DBI Functions
 */
// PMA_DBI_try_query()
define('PMA_DBI_QUERY_STORE',       1);  // Force STORE_RESULT method, ignored by classic MySQL.
define('PMA_DBI_QUERY_UNBUFFERED',  2);  // Do not read whole query
// PMA_DBI_get_variable()
define('PMA_DBI_GETVAR_SESSION', 1);
define('PMA_DBI_GETVAR_GLOBAL', 2);

/**
 * Including The DBI Plugin
 */
require_once('./libraries/dbi/' . $cfg['Server']['extension'] . '.dbi.lib.php');

/**
 * Common Functions
 */
function PMA_DBI_query($query, $link = NULL, $options = 0) {
    $res = PMA_DBI_try_query($query, $link, $options)
        or PMA_mysqlDie(PMA_DBI_getError($link), $query);
    return $res;
}

function PMA_DBI_get_dblist($link = NULL) {
    if (empty($link)) {
        if (isset($GLOBALS['userlink'])) {
            $link = $GLOBALS['userlink'];
        } else {
            return FALSE;
        }
    }
    $res = PMA_DBI_try_query('SHOW DATABASES;', $link);
    $dbs_array = array();
    while ($row = PMA_DBI_fetch_row($res)) {

       // Before MySQL 4.0.2, SHOW DATABASES could send the
       // whole list, so check if we really have access:
       //if (PMA_MYSQL_CLIENT_API < 40002) {
       // Better check the server version, in case the client API
       // is more recent than the server version

       if (PMA_MYSQL_INT_VERSION < 40002) {
           $dblink = @PMA_DBI_select_db($row[0], $link);
           if (!$dblink) {
               continue;
           }
       }
       $dbs_array[] = $row[0];
    }
    PMA_DBI_free_result($res);
    unset($res);

    return $dbs_array;
}

function PMA_DBI_get_tables($database, $link = NULL) {
    $result       = PMA_DBI_query('SHOW TABLES FROM ' . PMA_backquote($database) . ';', NULL, PMA_DBI_QUERY_STORE);
    $tables       = array();
    while (list($current) = PMA_DBI_fetch_row($result)) {
        $tables[] = $current;
    }
    PMA_DBI_free_result($result);

    return $tables;
}

function PMA_DBI_get_tables_full($database, $link = NULL) {
    $result = PMA_DBI_query('SHOW TABLE STATUS FROM ' . PMA_backquote($database) . ';', NULL, PMA_DBI_QUERY_STORE);
    $tables = array();
    while ($row = PMA_DBI_fetch_assoc($result)) {
        $tables[$row['Name']] = $row;
    }
    PMA_DBI_free_result($result);
    return $tables;
}

function PMA_DBI_get_fields($database, $table, $link = NULL) {
    if (empty($link)) {
        if (isset($GLOBALS['userlink'])) {
            $link = $GLOBALS['userlink'];
        } else {
            return FALSE;
        }
    }
    // here we use a try_query because when coming from 
    // tbl_create + tbl_properties.inc.php, the table does not exist
    $result = PMA_DBI_try_query('SHOW FULL FIELDS FROM ' . PMA_backquote($database) . '.' . PMA_backquote($table), $link);

    if (!$result) {
        return FALSE;
    }

    $fields = array();
    while ($row = PMA_DBI_fetch_assoc($result)) {
        $fields[] = $row;
    }

    return $fields;
}

function PMA_DBI_get_variable($var, $type = PMA_DBI_GETVAR_SESSION, $link = NULL) {
    if ($link === NULL) {
        if (isset($GLOBALS['userlink'])) {
            $link = $GLOBALS['userlink'];
        } else {
            return FALSE;
        }
    }
    if (PMA_MYSQL_INT_VERSION < 40002) {
        $type = 0;
    }
    switch ($type) {
        case PMA_DBI_GETVAR_SESSION:
            $modifier = ' SESSION';
            break;
        case PMA_DBI_GETVAR_GLOBAL:
            $modifier = ' GLOBAL';
            break;
        default:
            $modifier = '';
    }
    $res = PMA_DBI_query('SHOW' . $modifier . ' VARIABLES LIKE \'' . $var . '\';', $link);
    $row = PMA_DBI_fetch_row($res);
    PMA_DBI_free_result($res);
    if (empty($row)) {
        return FALSE;
    } else {
        return $row[0] == $var ? $row[1] : FALSE;
    }
}

function PMA_DBI_postConnect($link, $is_controluser = FALSE) {
    global $collation_connection, $charset_connection;
    if (!defined('PMA_MYSQL_INT_VERSION')) {
        $result = PMA_DBI_query('SELECT VERSION() AS version', $link, PMA_DBI_QUERY_STORE);
        if ($result != FALSE && @PMA_DBI_num_rows($result) > 0) {
            $row   = PMA_DBI_fetch_row($result);
            $match = explode('.', $row[0]);
            PMA_DBI_free_result($result);
        }
        if (!isset($row)) {
            define('PMA_MYSQL_INT_VERSION', 32332);
            define('PMA_MYSQL_STR_VERSION', '3.23.32');
        } else{
            define('PMA_MYSQL_INT_VERSION', (int)sprintf('%d%02d%02d', $match[0], $match[1], intval($match[2])));
            define('PMA_MYSQL_STR_VERSION', $row[0]);
            unset($result, $row, $match);
        }
    }

    if (PMA_MYSQL_INT_VERSION >= 40100) {

        // If $lang is defined and we are on MySQL >= 4.1.x,
        // we auto-switch the lang to its UTF-8 version (if it exists and user didn't force language)
        if (!empty($GLOBALS['lang']) && (substr($GLOBALS['lang'], -5) != 'utf-8') && !isset($GLOBALS['cfg']['Lang'])) {
            $lang_utf_8_version = substr($GLOBALS['lang'], 0, strpos($GLOBALS['lang'], '-')) . '-utf-8';
            if (!empty($GLOBALS['available_languages'][$lang_utf_8_version])) {
                $GLOBALS['lang'] = $lang_utf_8_version;
                $GLOBALS['charset'] = $charset = 'utf-8';
            }
        }

        // and we remove the non-UTF-8 choices to avoid confusion
        if (!defined('PMA_REMOVED_NON_UTF_8')) {
            $tmp_available_languages        = $GLOBALS['available_languages']; 
            $GLOBALS['available_languages'] = array();
            foreach ($tmp_available_languages AS $tmp_lang => $tmp_lang_data) {
                if (substr($tmp_lang, -5) == 'utf-8') {
                    $GLOBALS['available_languages'][$tmp_lang] = $tmp_lang_data;
                }
            } // end foreach
            unset($tmp_lang, $tmp_lang_data, $tmp_available_languages);
            define('PMA_REMOVED_NON_UTF_8',1);
        }

        $mysql_charset = $GLOBALS['mysql_charset_map'][$GLOBALS['charset']];
        if ($is_controluser || empty($collation_connection) || (strpos($collation_connection, '_') ? substr($collation_connection, 0, strpos($collation_connection, '_')) : $collation_connection) == $mysql_charset) {
            PMA_DBI_query('SET NAMES ' . $mysql_charset . ';', $link, PMA_DBI_QUERY_STORE);
        } else {
            PMA_DBI_query('SET CHARACTER SET ' . $mysql_charset . ';', $link, PMA_DBI_QUERY_STORE);
        }
        if (!empty($collation_connection)) {
            PMA_DBI_query('SET collation_connection = \'' . $collation_connection . '\';', $link, PMA_DBI_QUERY_STORE);
        }
        if (!$is_controluser) {
            $collation_connection = PMA_DBI_get_variable('collation_connection',     PMA_DBI_GETVAR_SESSION, $link);
            $charset_connection   = PMA_DBI_get_variable('character_set_connection', PMA_DBI_GETVAR_SESSION, $link);
        }

        // Add some field types to the list
        // (we pass twice here; feel free to code something better :)
        if (!defined('PMA_ADDED_FIELD_TYPES')) {
            $GLOBALS['cfg']['ColumnTypes'][] = 'BINARY';
            $GLOBALS['cfg']['ColumnTypes'][] = 'VARBINARY';
            define('PMA_ADDED_FIELD_TYPES',1);
        }

    } else {
        require_once('./libraries/charset_conversion.lib.php');
    }
}

/**
 * returns a single value from the given result or query,
 * if the query or the result has more than one row or field
 * the first field of the first row is returned
 * 
 * <code>
 * $sql = 'SELECT `name` FROM `user` WHERE `id` = 123';
 * $user_name = PMA_DBI_fetch_value( $sql );
 * // produces
 * // $user_name = 'John Doe'
 * </code>
 * 
 * @uses    is_string()
 * @uses    is_int()
 * @uses    PMA_DBI_try_query()
 * @uses    PMA_DBI_num_rows()
 * @uses    PMA_DBI_fetch_row()
 * @uses    PMA_DBI_fetch_assoc()
 * @uses    PMA_DBI_free_result()
 * @param   string|mysql_result $result query or mysql result
 * @param   integer             $row_number row to fetch the value from,
 *                                      starting at 0, with 0 beeing default
 * @param   integer|string      $field  field to fetch the value from,
 *                                      starting at 0, with 0 beeing default
 * @param   resource            $link   mysql link
 * @param   mixed               $options    
 * @return  mixed               value of first field in first row from result
 *                              or false if not found
 */
function PMA_DBI_fetch_value( $result, $row_number = 0, $field = 0, $link = NULL, $options = 0 ) {
    $value = false;
    
    if ( is_string( $result ) ) {
        $result = PMA_DBI_try_query( $result, $link, $options | PMA_DBI_QUERY_STORE );
    }
    
    // return false if result is empty or false
    // or requested row is larger than rows in result
    if ( PMA_DBI_num_rows( $result ) < ( $row_number + 1 ) ) {
        return $value;
    }
    
    // if $field is an integer use non associative mysql fetch function    
    if ( is_int( $field ) ) {
        $fetch_function = 'PMA_DBI_fetch_row';
    } else {
        $fetch_function = 'PMA_DBI_fetch_assoc';
    }
    
    // get requested row
    for ( $i = 0; $i <= $row_number; $i++ ) {
        $row = $fetch_function( $result );
    }
    PMA_DBI_free_result( $result );
    
    // return requested field
    if ( isset( $row[$field] ) ) {
        $value = $row[$field];
    }
    unset( $row );
    
    return $value;
}

/**
 * returns only the first row from the result
 * 
 * <code>
 * $sql = 'SELECT * FROM `user` WHERE `id` = 123';
 * $user = PMA_DBI_fetch_single_row( $sql );
 * // produces
 * // $user = array( 'id' => 123, 'name' => 'John Doe' )
 * </code>
 * 
 * @uses    is_string()
 * @uses    PMA_DBI_try_query()
 * @uses    PMA_DBI_num_rows()
 * @uses    PMA_DBI_fetch_row()
 * @uses    PMA_DBI_fetch_assoc()
 * @uses    PMA_DBI_fetch_array()
 * @uses    PMA_DBI_free_result()
 * @param   string|mysql_result $result query or mysql result
 * @param   string              $type   NUM|ASSOC|BOTH
 *                                      returned array should either numeric
 *                                      associativ or booth
 * @param   resource            $link   mysql link
 * @param   mixed               $options    
 * @return  array|boolean       first row from result
 *                              or false if result is empty
 */
function PMA_DBI_fetch_single_row( $result, $type = 'ASSOC', $link = NULL, $options = 0 ) {
    if ( is_string( $result ) ) {
        $result = PMA_DBI_try_query( $result, $link, $options | PMA_DBI_QUERY_STORE );
    }
    
    // return NULL if result is empty or false
    if ( ! PMA_DBI_num_rows( $result ) ) {
        return false;
    }    
    
    switch ( $type ) {
        case 'NUM' :
            $fetch_function = 'PMA_DBI_fetch_row';
            break;
        case 'ASSOC' :
            $fetch_function = 'PMA_DBI_fetch_assoc';
            break;
        case 'BOTH' :
        default :
            $fetch_function = 'PMA_DBI_fetch_array';
            break;
    }
    
    $row = $fetch_function( $result );
    PMA_DBI_free_result( $result );
    return $row;
}

/**
 * returns all rows in the resultset in one array
 * 
 * <code>
 * $sql = 'SELECT * FROM `user`';
 * $users = PMA_DBI_fetch_result( $sql );
 * // produces
 * // $users[] = array( 'id' => 123, 'name' => 'John Doe' )
 * 
 * $sql = 'SELECT `id`, `name` FROM `user`';
 * $users = PMA_DBI_fetch_result( $sql, 'id' );
 * // produces
 * // $users['123'] = array( 'id' => 123, 'name' => 'John Doe' )
 *
 * $sql = 'SELECT `id`, `name` FROM `user`';
 * $users = PMA_DBI_fetch_result( $sql, 0 );
 * // produces
 * // $users['123'] = array( 0 => 123, 1 => 'John Doe' )
 *
 * $sql = 'SELECT `id`, `name` FROM `user`';
 * $users = PMA_DBI_fetch_result( $sql, 'id', 'name' );
 * // or
 * $users = PMA_DBI_fetch_result( $sql, 0, 1 );
 * // produces
 * // $users['123'] = 'John Doe'
 * 
 * $sql = 'SELECT `name` FROM `user`';
 * $users = PMA_DBI_fetch_result( $sql );
 * // produces
 * // $users[] = 'John Doe'
 * </code>
 *
 * @uses    is_string()
 * @uses    is_int()
 * @uses    PMA_DBI_try_query()
 * @uses    PMA_DBI_num_rows()
 * @uses    PMA_DBI_num_fields()
 * @uses    PMA_DBI_fetch_row()
 * @uses    PMA_DBI_fetch_assoc()
 * @uses    PMA_DBI_free_result()
 * @param   string|mysql_result $result query or mysql result
 * @param   string|integer      $key    field-name or offset
 *                                      used as key for array
 * @param   string|integer      $value  value-name or offset
 *                                      used as value for array
 * @param   resource            $link   mysql link
 * @param   mixed               $options    
 * @return  array               resultrows or values indexed by $key
 */
function PMA_DBI_fetch_result( $result, $key = NULL, $value = NULL, $link = NULL, $options = 0 )
{
    $resultrows = array();
    
    if ( is_string( $result ) ) {
        $result = PMA_DBI_try_query( $result, $link, $options );
    }
    
    // return empty array if result is empty or false
    if ( ! $result ) {
        return $resultrows;
    }
    
    $fetch_function = 'PMA_DBI_fetch_assoc';
    
    // no nested array if only one field is in result
    if ( NULL === $key && 1 === PMA_DBI_num_fields( $result ) ) {
        $value = 0;
        $fetch_function = 'PMA_DBI_fetch_row';
    }
    
    // if $key is an integer use non associative mysql fetch function    
    if ( is_int( $key ) ) {
        $fetch_function = 'PMA_DBI_fetch_row';
    }
    
    if ( NULL === $key && NULL === $value ) {
        while ( $row = $fetch_function( $result ) ) {
            $resultrows[] = $row;
        }
    } elseif ( NULL === $key ) {
        while ( $row = $fetch_function( $result ) ) {
            $resultrows[] = $row[$value];
        }
    } elseif ( NULL === $value ) {
        while ( $row = $fetch_function( $result ) ) {
            $resultrows[$row[$key]] = $row;
        }
    } else {
        while ( $row = $fetch_function( $result ) ) {
            $resultrows[$row[$key]] = $row[$value];
        }
    }
    
    PMA_DBI_free_result( $result );
    return $resultrows;
}
?>
