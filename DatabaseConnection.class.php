<?php
// table and field names must satisfy regex '/[\w]/'
// tinyint is always interpreted as boolean (0 => false; 1 => true)

// ErrorInfo
// type: database_connection, database_query, database_nomatch
// data: => Error Number if (database_connection, database_query)

class DatabaseConnection {
    private $_connection = null;
    private $_status = null; // true or ErrorInfo

    public function __construct($db_hostname, $db_username, $db_password, $db_database, $db_port = null, $db_socket = null) {
        mysqli_report(MYSQLI_REPORT_OFF);
        $errorlevel = error_reporting();
        error_reporting(0);
        if ($db_port === null && $db_socket === null) {
            $this->_connection = new mysqli($db_hostname, $db_username, $db_password, $db_database);
        } else {
            $this->_connection = new mysqli($db_hostname, $db_username, $db_password, $db_database, $db_port !== null ? $db_port : ini_get("mysqli.default_port"), $db_socket !== null ? $db_socket : ini_get("mysqli.default_socket"));
        }
        error_reporting($errorlevel);

        if ($this->_connection->connect_error === null) {
            $this->_connection->set_charset('utf8');
            $this->_status = true;
        } else {
            $error_message = $this->_connection->connect_error;
            $error_number = $this->_connection->connect_errno;
            $this->_status = new ErrorInfo('database_connection', $error_message, $error_number);
        }
    }

    public function __destruct() {
        $this->CLOSE();
    }

    public function STATUS() { // returns true or ErrorInfo
        return $this->_status;
    }

    public function CLOSE() {
        if ($this->_status === true) {
            $this->_connection->close();
        }
    }


    // ### QUERY Functions ########################################
    public function QUERY($query) { // returns true or mysqli_result object otherwise ErrorInfo
        // can only excecute one statement (otherwise no statement is excecuted)
        if ($this->_status === true) {
            $query_result = $this->_connection->query($query);
            if ($query_result !== false) {
                return $query_result;
            } else {
                $error_message = $this->_connection->error;
                $error_number = $this->_connection->errno;
                return new ErrorInfo('database_query', $error_message, $error_number);
            }
        } else {
            return $this->_status;
        }
    }

    public function TRANSACTION($QUERIES, $rollback = true) { // returns array with true or mysqli_result object otherwise ErrorInfo for each query
        // multiple queries as transaction (rolls back if one query fails)
        // rollback does not work with non transactional table types (like MyISAM or ISAM)
        if ($this->_status === true) {
            $status = true;

            $QUERIES_RESULT = [];
            $this->_connection->begin_transaction();
            foreach ($QUERIES as $query) {
                $query_result = $this->QUERY($query);
                array_push($QUERIES_RESULT, $query_result);
                if (ErrorInfo::isError($query_result)) {
                    $status = false;
                }
            }

            if ($status === true || $rollback === false) {
                $this->_connection->commit();
            } else {
                $this->_connection->rollback();
            }

            return $QUERIES_RESULT;
        } else {
            return $this->_status;
        }
    }

    public function MULTIQUERY($multiquery) {
        // excecutes all statements until first failed
        if ($this->_status === true) {
            if ($this->_connection->multi_query($multiquery)) {
                $RESULT = [];
                do {
                    $query_result = $this->_connection->store_result();
                    if ($this->_connection->errno === 0) {
                        if ($query_result) {
                            array_push($RESULT, $this->RESULT2ARRAY($query_result));
                            $query_result->free();
                        } else {
                            array_push($RESULT, null); // query didn't return a result (e.g. INSERT)
                        }
                    } else {
                        array_push($RESULT, new ErrorInfo('database_query', $this->_connection->error, $this->_connection->errno));
                    }
                } while ($this->_connection->next_result());
            } else {
                return [new ErrorInfo('database_query', $this->_connection->error, $this->_connection->errno)];
            }
        } else {
            return $this->_status;
        }
    }

    public function QUERY2ARRAY($query, $option_single = false) {
        return $this->RESULT2ARRAY($this->QUERY($query), $option_single);
    }


    // ### CRUD Functions ########################################
    public function SELECT($table, $SELECT, $condition = null, $ordering = null, $limit = null, $offset = null, $option_single = false) {  // returns result; otherwise ErrorInfo
        //-> multiple: SELECT($table, $SELECT, $condition);
        //-> single: SELECT($table, $SELECT, $condition, null, 1, null, true);
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';
        foreach ($SELECT as &$val) {
            $val = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $val)) . '`';
        }
        $SELECT = implode(', ', $SELECT);
        $where = $this->GetWhere($condition);
        if ($ordering !== Null) {
            $order = ' ORDER BY ' . $ordering;
        } else {
            $order = '';
        }
        if ($limit !== Null) {
            $limit = ' LIMIT ' . intval($limit);
        } else {
            $limit = '';
        }
        if (($limit !== Null) and ($offset !== Null)) {
            $offset = ' OFFSET ' . intval($offset);
        } else {
            $offset = '';
        }

        $RESULT = $this->QUERY('SELECT ' . $SELECT . ' FROM ' . $table . $where . $order . $limit . $offset);
        return $this->RESULT2ARRAY($RESULT, $option_single);
    }

    public function GET($table, $field, $condition) { // returns result; otherwise ErrorInfo
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';
        $field = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $field)) . '`';
        $where = ' WHERE ' . $condition;

        $RESULT = $this->QUERY('SELECT ' . $field . ' as fieldvalue FROM ' . $table . $where . ' LIMIT 1');
        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        }
        if ($RESULT->num_rows === 0) {
            return new ErrorInfo('database_nomatch', "No item found");
        }

        $datatype = self::GetType($RESULT->fetch_field()->type);
        $fieldvalue = $RESULT->fetch_object()->fieldvalue;
        settype($fieldvalue, $datatype);

        return $fieldvalue;
    }

    public function ADD($table, $ADD = null) { // returns insertid as int; otherwise ErrorInfo
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';

        if ($ADD !== Null) {
            $FIELDS = array();
            $VALUES = array();
            foreach ($ADD as $key => $val) {
                array_push($FIELDS, '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $key)) . '`');
                array_push($VALUES, $this->ValueToEscapedString($val));
            }
            $VALUES = '(' . implode(', ', $FIELDS) . ') VALUES (' . implode(', ', $VALUES) . ')';
        } else {
            $VALUES = '() VALUES ()';
        }

        $RESULT = $this->QUERY('INSERT INTO ' . $table . ' ' . $VALUES);
        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            $newid = $this->_connection->insert_id;
            return intval($newid);
        }
    }

    public function UPDATE($table, $UPDATE, $condition = null) { // returns number affectedrows as int/string (can be zero if no changes in records); otherwise ErrorInfo
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';
        $SET = array();
        foreach ($UPDATE as $key => $val) {
            array_push($SET, '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $key)) . '`=' . $this->ValueToEscapedString($val));
        }
        $SET = implode(', ', $SET);
        $where = $this->GetWhere($condition);

        $RESULT = $this->QUERY('UPDATE ' . $table . ' SET ' . $SET . $where);
        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            return $this->_connection->affected_rows;
        }
    }

    public function SET($table, $field, $value, $condition = null) { // returns number affectedrows as int/string (can be zero if no changes in records); otherwise ErrorInfo
        return $this->UPDATE($table, array($field => $value), $condition);
    }

    public function INCREASE($table, $field, $condition) { // returns number affectedrows as int/string (can be zero if no changes in records); otherwise ErrorInfo
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';
        $field = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $field)) . '`';
        $where = $this->GetWhere($condition);

        $RESULT = $this->QUERY('UPDATE ' . $table . ' SET ' . $field . '=' . $field . '+1' . $where);
        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            return $this->_connection->affected_rows;
        }
    }

    public function DECREASE($table, $field, $condition) { // returns number affectedrows as int/string (can be zero if no changes in records); otherwise ErrorInfo
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';
        $field = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $field)) . '`';
        $where = $this->GetWhere($condition);

        $RESULT = $this->QUERY('UPDATE ' . $table . ' SET ' . $field . '=' . $field . '-1' . $where);
        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            return $this->_connection->affected_rows;
        }
    }

    public function DELETE($table, $condition) { // returns number affectedrows as int/string (can be zero if no changes in records); otherwise ErrorInfo
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';
        $where = $this->GetWhere($condition);

        $RESULT = $this->QUERY('DELETE FROM ' . $table . $where);
        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            return $this->_connection->affected_rows;
        }
    }

    public function COUNT($table, $condition = null) { // returns count as integer; otherwise ErrorInfo
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';
        $where = $this->GetWhere($condition);

        $RESULT = $this->QUERY('SELECT COUNT(*) as count FROM ' . $table . $where);
        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            $count = $RESULT->fetch_object()->count;
            return intval($count);
        }
    }


    // ### Helper Functions ########################################     
    public function ESCAPESTRING($value) {
        if ($this->_status === true) {
            return $this->_connection->real_escape_string($value);
        } else {
            return null;
        }
    }

    public function ValueToEscapedString($val) {
        switch (true) {
            case ($val === null):
                return 'null';
                break;
            case is_bool($val):
                return $val ? '1' : '0';
                break;
            case is_int($val):
                return $this->ESCAPESTRING($val);
                break;
            case is_string($val):
                return '\'' . $this->ESCAPESTRING($val) . '\'';
                break;
            default:
                return '\'' . $this->ESCAPESTRING($val) . '\'';
        }
    }

    private function RESULT2ARRAY($RESULT, $option_single = false) { // processes mysqli_result and returns result; otherwise ErrorInfo
        // tinyint is always interpreted as boolean (0 => false; 1 => true)

        // columns/rows*    raw data                    single=false                single=true
        // 1/0              ()			                ()			                null
        // 1/1              ((a=1))			            (1)			                1
        // 1/2              ((a=1), (a=2))		        (1, 2)			            (1, 2)
        // 2/0              ()		                    ()		                    ()
        // 2/1              ((a=1, b=11))		        ((a=1, b=11))		        (a=1, b=11)
        // 2/2              ((a=1, b=11), (a=2, b=22))	((a=1, b=11), (a=2, b=22))	((a=1, b=11), (a=2, b=22))
        // *(fields/items)

        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            $tableset = null;

            if ($RESULT->num_rows > 0) {
                // get data types
                $datatypes = array();
                foreach ($RESULT->fetch_fields() as $field) {
                    $datatypes[$field->name] = self::GetType($field->type);
                }

                // cast data
                if (method_exists($this, 'fetch_all')) {
                    $tableset = $RESULT->fetch_all(MYSQLI_ASSOC);
                } else {
                    $tableset = [];
                    while ($row = $RESULT->fetch_assoc()) {
                        $tableset[] = $row;
                    }
                }
                foreach ($tableset as &$row) {
                    foreach ($row as $colkey => &$colval) {
                        if ($colval !== null) {
                            settype($row[$colkey], $datatypes[$colkey]);
                        }
                    }
                }
                if ($RESULT->field_count === 1) {
                    $tableset = array_map('current', $tableset);
                }

                //consider option_single
                if ($option_single == true) {
                    if ($RESULT->num_rows === 1) {
                        $tableset = current($tableset);
                    }
                }
            } else {
                if ($RESULT->field_count === 1 && $option_single == true) {
                    $tableset = null;
                } else {
                    $tableset = array();
                }
            }

            return $tableset;
        }
    }

    private static function GetWhere($condition) {
        if ($condition !== Null) {
            return ' WHERE ' . $condition;
        } else {
            return '';
        }
    }

    private static function GetType($field_type) {
        $result = null;

        switch ($field_type) {
            case MYSQLI_TYPE_NULL:
                $result = 'null';
                break;
            case MYSQLI_TYPE_BIT:
                $result = 'boolean';
                break;
            case MYSQLI_TYPE_TINY:
            case MYSQLI_TYPE_SHORT:
            case MYSQLI_TYPE_LONG:
            case MYSQLI_TYPE_INT24:
            case MYSQLI_TYPE_LONGLONG:
                $result = 'int';
                break;
            case MYSQLI_TYPE_FLOAT:
            case MYSQLI_TYPE_DOUBLE:
            case MYSQLI_TYPE_DECIMAL:
            case MYSQLI_TYPE_NEWDECIMAL:
                $result = 'float';
                break;
            default:
                $result = 'string';
                break;
        }
        if ($field_type === MYSQLI_TYPE_TINY) {
            $result = 'boolean';
        }

        return $result;
    }

    public static function AddQueryLimiter($limit = null, $start = null) {
        $limiter = '';
        if ($limit !== null) {
            $limiter = ' LIMIT ' . intval($limit);
        }
        if (($limit !== null) and ($start !== null)) {
            $limiter = ' LIMIT ' . intval($start) . ',' . intval($limit);
        }
        return $limiter;
    }
}
