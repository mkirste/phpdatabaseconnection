<?php
/*
Restrictions: table must have a column 'id' as unique identifier and created_on + modified_on fields
  `id` int(9) NOT NULL => ADD PRIMARY KEY (`id`)
  `created_on` int(12) NOT NULL
  `modified_on` int(12) DEFAULT NULL
*/

class DatabaseGateway {
    protected $_dbcon = null;
    protected $_table;
    protected $_FIELDRULES;
    protected $_UNIQUE_FIELDS;
    protected $_FIELDS_READ;
    protected $_REQUIRED_FIELDS_CREATE;
    protected $_ALLOWED_FIELDS_CREATE;
    protected $_REQUIRED_FIELDS_UPDATE;
    protected $_ALLOWED_FIELDS_UPDATE;
    protected $_read_filter;
    protected $_read_order;

    public function __construct($DatabaseConnection, $table, $FIELDS) { //'tablename', array(id, field1, field2, ...)
        $this->_dbcon = $DatabaseConnection;

        $this->_table = $table;
        $this->_FIELDRULES = array();
        $this->_UNIQUE_FIELDS = array();
        $this->_FIELDS_READ = $FIELDS;
        $this->_REQUIRED_FIELDS_CREATE = array_diff($FIELDS, array('id', 'created_on', 'modified_on'));
        $this->_ALLOWED_FIELDS_CREATE = array_diff($FIELDS, array('id', 'created_on', 'modified_on'));
        $this->_REQUIRED_FIELDS_UPDATE = array();
        $this->_ALLOWED_FIELDS_UPDATE = array_diff($FIELDS, array('id', 'created_on', 'modified_on'));
        $this->_read_filter = null;  // customization
        $this->_read_order = null;  // customization
    }


    // ### Custom Functions ########################################
    protected function readCustomValidation($object_id) {
        // overwrite for adding custom validations (must return error array or true) ...
        return true;
    }
    protected function createCustomValidation($OBJECT) {
        // overwrite for adding custom validations (must return error array or true) ...
        return true;
    }
    protected function updateCustomValidation($object_id, $OBJECT) {
        // overwrite for adding custom validations (must return error array or true) ...
        return true;
    }
    protected function deleteCustomValidation($object_id) {
        // overwrite for adding custom validations (must return error array or true) ...
        return true;
    }
    protected function createObjectCustomModification($OBJECT) {
        // overwrite for adding custom code ...
        return $OBJECT;
    }
    protected function updateObjectCustomModification($OBJECT) {
        // overwrite for adding custom code ...
        return $OBJECT;
    }

    protected function deleteObjectCustomPrecall($object_id) {
        // overwrite for adding custom code ... (object with object_id still exists in database)
    }

    protected function deleteObjectCustomCallback($object_id) {
        // overwrite for adding custom code ... (object with object_id no longer exists in database)
    }

    // ### CRUD Functions ########################################
    public function readObjects($fields = null) { // returns [][fied1,field2,...]
        $selectedFields = $this->_FIELDS_READ;
        if ($fields !== null) {
            $selectedFields = array_intersect($this->_FIELDS_READ, explode(',', $fields));
        }

        if (empty($selectedFields)) {
            return new ErrorInfo('cms_datavalidation', 'No valid fields given');
        }

        //$RESULT = $this->getIdAssociativedObject($this->_dbcon->SELECT($this->_table, $selectedFields));
        return $this->_dbcon->SELECT($this->_table, $selectedFields, $this->_read_filter, $this->_read_order);
    }

    public function readObjectById($object_id) { // returns [fied1,field2,...]
        // Validation => $object_id
        if ($this->checkIdValue($object_id) === false) {
            return new ErrorInfo('cms_datavalidation', 'No valid id given');
        }

        // Custom Validation
        $customValidation = $this->readCustomValidation($object_id);
        if ($customValidation !== true) {
            return $customValidation;
        }

        // Query 
        if ($this->_read_filter === null) {
            $RESULT = $this->_dbcon->SELECT($this->_table, $this->_FIELDS_READ, '`id`=' . $this->_dbcon->ValueToEscapedString($object_id), null, 1, null, true);
        } else {
            $RESULT = $this->_dbcon->SELECT($this->_table, $this->_FIELDS_READ, '`id`=' . $this->_dbcon->ValueToEscapedString($object_id) . ' AND ' . $this->_read_filter, null, 1, null, true);
        }

        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            if (empty($RESULT)) {
                return new ErrorInfo('cms_objectnotfound', 'Object with id ' . $object_id . ' not found');
            } else {
                return $RESULT;
            }
        }
    }

    public function createObject($OBJECT) { //[fied1,field2,...]
        // Validation => $OBJECT null?       
        if ($OBJECT === null) {
            return new ErrorInfo('cms_datavalidation', 'No data given');
        }

        // Validation => _REQUIRED_FIELDS_CREATE / _ALLOWED_FIELDS_CREATE / _FIELDRULES in $OBJECT
        $ValidationService = new ValidationService($OBJECT);
        if (!empty($this->_REQUIRED_FIELDS_CREATE)) {
            $ValidationService->validateRequiredFields($this->_REQUIRED_FIELDS_CREATE);
        }
        $ValidationService->validateAllowedFields($this->_ALLOWED_FIELDS_CREATE);
        foreach ($OBJECT as $fieldkey => $fieldval) {
            if (array_key_exists($fieldkey, $this->_FIELDRULES)) { //field has a rule
                $ValidationService->validateRules($fieldkey, $this->_FIELDRULES[$fieldkey]); //check field rule
            }
        }
        if ($ValidationService->result() === false) {
            return new ErrorInfo('cms_datavalidation', $ValidationService->ERRORS);
        }

        // Validation => _UNIQUE_FIELDS in $OBJECT
        /*
        foreach ($this->_UNIQUE_FIELDS as $uniquefield) {
            if (array_key_exists($uniquefield, $OBJECT)) {
                $result_count = $this->_dbcon->COUNT($this->_table, '`' . $uniquefield . '`=' . $this->_dbcon->ValueToEscapedString($OBJECT[$uniquefield]));
                if (ErrorInfo::isError($result_count)) {
                    return $result_count;
                } else {
                    if ($result_count > 0) {
                        return new ErrorInfo('cms_datavalidation', ucfirst($uniquefield) . ' already taken');
                    }
                }
            }
        }
        */

        // Custom Validation
        $customValidation = $this->createCustomValidation($OBJECT);
        if ($customValidation !== true) {
            return $customValidation;
        }

        // Set created_on, modified_on
        $OBJECT['created_on'] = time();
        $OBJECT['modified_on'] = null;

        // Customization
        $OBJECT = $this->createObjectCustomModification($OBJECT);
        if (ErrorInfo::isError($OBJECT)) {
            return $OBJECT;
        }

        // Query
        $object_id = $this->_dbcon->ADD($this->_table, $OBJECT);
        if (ErrorInfo::isError($object_id)) {
            if ($object_id->data === 1062) {  // {"type":"database_query","message":"Duplicate entry '{XYZ}' for key '{ABC}'","data":1062}   
                return new ErrorInfo('cms_datavalidation', str_replace('key', 'field', $object_id->message));
            } else {
                return $object_id;
            }
        } else {
            return array('id' => $object_id);
        }
    }

    public function updateObjectById($object_id, $OBJECT) { //[field1,fied2,...]
        // Validation => $OBJECT null?       
        if ($OBJECT === null) {
            return new ErrorInfo('cms_datavalidation', 'No data given');
        }

        // Validation => $object_id
        if ($this->checkIdValue($object_id) === false) {
            return new ErrorInfo('cms_datavalidation', 'No valid id given');
        }

        // Validation => _REQUIRED_FIELDS_UPDATE / _ALLOWED_FIELDS_UPDATE / _FIELDRULES in $OBJECT
        $ValidationService = new ValidationService($OBJECT);
        if (empty($this->_REQUIRED_FIELDS_UPDATE)) {
            $ValidationService->validateHasFields();
        } else {
            $ValidationService->validateRequiredFields($this->_REQUIRED_FIELDS_UPDATE);
        }

        $ValidationService->validateAllowedFields($this->_ALLOWED_FIELDS_UPDATE);
        foreach ($OBJECT as $fieldkey => $fieldval) {
            if (array_key_exists($fieldkey, $this->_FIELDRULES)) { //field has a rule
                $ValidationService->validateRules($fieldkey, $this->_FIELDRULES[$fieldkey]); //check field rule
            }
        }
        if ($ValidationService->result() === false) {
            return new ErrorInfo('cms_datavalidation', $ValidationService->ERRORS);
        }

        // Validation => _UNIQUE_FIELDS in $OBJECT
        /*
        foreach ($this->_UNIQUE_FIELDS as $uniquefield) {
            if (array_key_exists($uniquefield, $OBJECT)) {
                $result_count = $this->_dbcon->COUNT($this->_table, '`' . $uniquefield . '`=' . $this->_dbcon->ValueToEscapedString($OBJECT[$uniquefield]) . ' AND `id`<>' . $this->_dbcon->ValueToEscapedString($object_id));
                if (ErrorInfo::isError($result_count)) {
                    return $result_count;
                } else {
                    if ($result_count > 0) {
                        return new ErrorInfo('cms_datavalidation', ucfirst($uniquefield) . ' already taken');
                    }
                }
            }
        }
        */

        // Custom Validation
        $customValidation = $this->updateCustomValidation($object_id, $OBJECT);
        if ($customValidation !== true) {
            return $customValidation;
        }

        // Set modified_on
        $OBJECT['modified_on'] = time();

        // Customization
        $OBJECT = $this->updateObjectCustomModification($OBJECT);
        if (ErrorInfo::isError($OBJECT)) {
            return $OBJECT;
        }

        // Query
        $result = $this->_dbcon->UPDATE($this->_table, $OBJECT, '`id`=' . $this->_dbcon->ValueToEscapedString($object_id));
        if (ErrorInfo::isError($result)) {
            if ($result->data === 1062) {  // {"type":"database_query","message":"Duplicate entry '{XYZ}' for key '{ABC}'","data":1062}   
                return new ErrorInfo('cms_datavalidation', str_replace('key', 'field', $result->message));
            } else {
                return $result;
            }
        } else {
            return array('id' => $object_id);
        }
    }

    public function deleteObjectById($object_id) {
        // Validation => $object_id
        if ($this->checkIdValue($object_id) === false) {
            return new ErrorInfo('cms_datavalidation', 'No valid id given');
        }

        // Custom Validation
        $customValidation = $this->deleteCustomValidation($object_id);
        if ($customValidation !== true) {
            return $customValidation;
        }

        // Customization Precall
        $this->deleteObjectCustomPrecall($object_id);

        // Query
        $result = $this->_dbcon->DELETE($this->_table, '`id`=' . $this->_dbcon->ValueToEscapedString($object_id));
        if (ErrorInfo::isError($result)) {
            return $result;
        }

        // Customization Callback
        $this->deleteObjectCustomCallback($object_id);
        return array('id' => $object_id);
    }

    // ### Helper Functions ########################################
    protected function checkIdValue($id) {
        // Validation => $id
        $ValidationService = new ValidationService(array('id' => $id));
        $ValidationService->validateRules('id', 'objectid');
        return $ValidationService->result();
    }

    public static function getIdAssociativedObject($DATA) {
        $RESULT = array();
        foreach ($DATA as $ITEM) {
            $RESULT[$ITEM['id']] = $ITEM;
        }
        return $RESULT;
    }

    public static function getObjectIdByKeyValue($key, $key_value, $OBJECTS) {
        foreach ($OBJECTS as $OBJECT) {
            if ($OBJECT[$key] === $key_value) {
                return $OBJECT['id'];
            }
        }
        return null;
    }
}
