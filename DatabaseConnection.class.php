<?php

class DatabaseConnection {
    private $_connection = null;
    public $_status = null;

    public function __construct($db_host, $db_username, $db_password, $db_name, $db_port = null, $db_socket = null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $this->_status = true;
        try {
            $this->_connection = new mysqli($db_host, $db_username, $db_password, $db_name, $db_port !== null ? $db_port : ini_get("mysqli.default_port"), $db_socket !== null ? $db_socket : ini_get("mysqli.default_socket"));
        } catch (Exception $e) {
            $this->_status = false;
        }

        if ($this->_status === true) {
            $this->_connection->SET_charSET('utf8');
        }
    }

    public function __destruct() {
        $this->CLOSE();
    }

    public function STATUS() {
        return $this->_status;
    }

    public function ESCAPESTRING($value) {
        return $this->_connection->real_escape_string($value);
    }

    public function CLOSE() {
        if ($this->_status === true) {
            $this->_connection->close();
        }
    }

    public function QUERY($query) {
        try {
            return $this->_connection->query($query);
        } catch (Exception $e) {
            return null;
        }
    }

    public function MULTIQUERY($multiquery) {
        try {
            $RESULT = [];
            if ($this->_connection->multi_query($multiquery)) {
                do {
                    if ($query_result = $this->_connection->store_result()) {
                        array_push($RESULT, $this->RESULT2ARRAY($query_result));
                        $query_result->free();
                    }
                } while ($this->_connection->next_result());
            }
            return $RESULT;
        } catch (Exception $e) {
            return null;
        }
    }

    public function QUERY2ARRAY($query, $option_single = false) { // <array>; array()
        return $this->RESULT2ARRAY($this->QUERY($query), $option_single);
    }

    public function ADD($table, $ADD = null) { // <insertid>; null on error
        $table = $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table));

        if ($ADD !== Null) {
            $fields = array();
            $values = array();
            foreach ($ADD as $key => $val) {
                array_push($fields, '`' . $this->ESCAPESTRING($key) . '`');
                array_push($values, $this->ValueToEscapedString($val));
            }
            $values = '(' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ')';
        } else {
            $values = '() VALUES ()';
        }

        $RESULT = $this->QUERY('INSERT INTO ' . $table . ' ' . $values);
        if ($RESULT !== null) {
            $newid = $this->_connection->insert_id;
            return intval($newid);
        } else {
            return null;
        }
    }

    public function UPDATE($table, $UPDATE, $condition = null) { // <affectedrows> (can be zero if no changes in records); null on error
        $table = $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table));
        $set = array();
        foreach ($UPDATE as $key => $val) {
            array_push($set, '`' . $this->ESCAPESTRING($key) . '`=' . $this->ValueToEscapedString($val));
        }
        $set = implode(', ', $set);
        if ($condition !== Null) {
            $where = ' WHERE ' . $condition;
        } else {
            $where = '';
        }

        $RESULT = $this->QUERY('UPDATE ' . $table . ' SET ' . $set . $where);
        if ($RESULT !== null) {
            return $this->_connection->affected_rows;
        } else {
            return null;
        }
    }

    public function SELECT($table, $SELECT, $condition = null, $ordering = null, $limit = null, $offset = null, $option_single = false) {  // <array>; array()
        //-> multiple: SELECT($table, $SELECT, $condition);
        //-> single: SELECT($table, $SELECT, $condition, null, 1, null, true);
        $table = $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table));
        foreach ($SELECT as &$val) {
            $val = '`' . $this->ESCAPESTRING(str_replace(',', '', $val)) . '`';
        }
        $SELECT = implode(', ', $SELECT);
        if ($condition !== Null) {
            $where = ' WHERE ' . $condition;
        } else {
            $where = '';
        }
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

    public function GET($table, $field, $condition) { // <value>; null
        $table = $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table));
        $field = $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $field));
        $where = ' WHERE ' . $condition;

        $RESULT = $this->QUERY('SELECT `' . $field . '` as fieldvalue FROM ' . $table . $where . ' LIMIT 1');
        if ($RESULT === null || $RESULT->num_rows === 0) {
            return null;
        }

        $datatype = $this->getType($RESULT->fetch_field()->type);
        $fieldvalue = $RESULT->fetch_object()->fieldvalue;
        settype($fieldvalue, $datatype);

        return $fieldvalue;
    }

    public function SET($table, $field, $value, $condition = null) { // <affectedrows> (can be zero if no changes in records)
        $this->UPDATE($table, array($field => $value), $condition);
    }

    public function DELETE($table, $condition) { // <affectedrows>; null on error
        $table = $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table));
        $where = ' WHERE ' . $condition;

        $RESULT = $this->QUERY('DELETE FROM ' . $table . $where);
        if ($RESULT !== null) {
            return $this->_connection->affected_rows;
        } else {
            return null;
        }
    }

    public function COUNT($table, $condition = null) { // <count>; null on error
        $table = $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table));
        if ($condition !== Null) {
            $where = ' WHERE ' . $condition;
        } else {
            $where = '';
        }

        $RESULT = $this->QUERY('SELECT COUNT(*) as count FROM ' . $table . $where);
        if ($RESULT !== null) {
            $count = $RESULT->fetch_object()->count;
            return intval($count);
        } else {
            return null;
        }
    }

    public function RESULT2ARRAY($RESULT, $option_single = false) { // <array>; array()
        // tinyint is always interpreted as boolean (0 => false; 1 => true)

        // columns/rows*    raw data                    single=false                single=true
        // 1/0              ()			                ()			                null
        // 1/1              ((a=1))			            (1)			                1
        // 1/2              ((a=1), (a=2))		        (1, 2)			            (1, 2)
        // 2/0              ()		                    ()		                    ()
        // 2/1              ((a=1, b=11))		        ((a=1, b=11))		        (a=1, b=11)
        // 2/2              ((a=1, b=11), (a=2, b=22))	((a=1, b=11), (a=2, b=22))	((a=1, b=11), (a=2, b=22))
        // *(fields/items)

        $tableset = null;
        if ($RESULT !== null && $RESULT->num_rows > 0) {
            // get data types
            $datatypes = array();
            foreach ($RESULT->fetch_fields() as $field) {
                $datatypes[$field->name] = $this->getType($field->type);
            }

            // cast data
            $tableset = $RESULT->fetch_all(MYSQLI_ASSOC);
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
            if ($option_single == true && $RESULT->field_count === 1) {
                $tableset = null;
            } else {
                $tableset = array();
            }
        }
        return $tableset;
    }


    public function IsLocalHost() { // string
        if (substr($this->_connection->host_info, 0, 9) == 'Localhost') {
            return true;
        } else {
            return false;
        }
    }

    public function ValueToEscapedString($val) { // <insertid>; null
        switch (true) {
            case ($val === null):
                return 'null';
                break;
            case is_string($val):
                return '\'' . $this->ESCAPESTRING($val) . '\'';
                break;
            case is_bool($val):
                return $val ? '1' : '0';
                break;
            default:
                return $this->ESCAPESTRING($val);
        }
    }

    public static function getType($field_type) {
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
