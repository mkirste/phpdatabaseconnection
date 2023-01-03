<?php
// table: id,categorization_field_id,object_field_id
// categorization_table: id (is categorization_field_id)
// field_table: id (is object_field_id)
// => for 1-n relations (categorization may have multiple objects, object may have only one categorization)
//	  (violations are ommited in addRelation() by deleting existing relations for this object)

class DatabaseRelationGateway2 {
    protected $_dbcon = null;
    protected $_table;
    protected $_object_idfield;
    protected $_categorization_idfield;
    protected $_object_table;
    protected $_categorization_table;
    protected $_related_categorization_ordering;

    public function __construct($DatabaseConnection, $table, $object_idfield, $categorization_idfield) {
        $this->_dbcon = $DatabaseConnection;

        $this->_table = $table;
        $this->_categorization_idfield = $categorization_idfield;
        $this->_object_idfield = $object_idfield;
        $this->_categorization_table = null;
        $this->_object_table = null;
        $this->_related_categorization_ordering = null; // customization
    }


    // ### Custom Functions ########################################
    protected function addRelationCustomCallback($object_id, $categorization_id, $previous_categorization_id) {
        //...
    }
    protected function deleteRelationByObjectCustomCallback($object_id, $previous_categorization_id) {
        //...
    }

    protected function deleteRelationsByCategorizationCustomCallback($categorization_id) {
        //...
    }

    // ### CRUD Functions ########################################
    public function readAllRelationsByCategorization() { // returns [][categorization_id => [object_id1, object_id2, ...]]
        $RELATIONS = $this->_dbcon->SELECT($this->_table, array($this->_categorization_idfield, $this->_object_idfield));
        if (ErrorInfo::isError($RELATIONS)) {
            return $RELATIONS;
        }

        $RESULT = array();
        foreach ($RELATIONS as $relation) {
            $relation_categorization_id = $relation[$this->_categorization_idfield];
            $relation_object_id = $relation[$this->_object_idfield];
            if (array_key_exists($relation_categorization_id, $RESULT)) {
                array_push($RESULT[$relation_categorization_id], $relation_object_id);
            } else {
                $RESULT[$relation_categorization_id] = array($relation_object_id);
            }
        }
        return $RESULT;
    }

    public function readAllRelationsByObject() { // returns [][object_id => categorization_id]]
        $RELATIONS = $this->_dbcon->SELECT($this->_table, array($this->_categorization_idfield, $this->_object_idfield));
        if (ErrorInfo::isError($RELATIONS)) {
            return $RELATIONS;
        }

        $RESULT = array();
        foreach ($RELATIONS as $relation) {
            $relation_categorization_id = $relation[$this->_categorization_idfield];
            $relation_object_id = $relation[$this->_object_idfield];
            if (!array_key_exists($relation_object_id, $RESULT)) {
                $RESULT[$relation_object_id] = $relation_categorization_id;
            }
        }
        return $RESULT;
    }

    public function readRelatedCategorizationId($object_id) { // returns value
        // Validation => $categorization_id, $object_id
        if ($this->checkIdValue($object_id) === false) {
            return new ErrorInfo('cms_datavalidation', 'No valid object id given');
        }

        // Query
        $result = $this->_dbcon->GET($this->_table, $this->_categorization_idfield, '`' . $this->_object_idfield . '`=' . $this->_dbcon->ValueToEscapedString($object_id));
        if (ErrorInfo::isError($result)) {
            if ($result->type !== 'database_nomatch') {
                return $result; // ErrorInfo
            } else {
                return null;
            }
        } else {
            return $result;
        }
    }

    public function readRelatedObjectIds($categorization_id) { // returns []
        // Validation => $categorization_id, $object_id
        if ($this->checkIdValue($categorization_id) === false) {
            return new ErrorInfo('cms_datavalidation', 'No valid categorization id given');
        }

        return $this->_dbcon->SELECT($this->_table, array($this->_object_idfield), '`' . $this->_categorization_idfield . '`=' . $this->_dbcon->ValueToEscapedString($categorization_id), $this->_related_categorization_ordering);
    }

    public function addRelation($object_id, $categorization_id) {
        // Validation => $object_id, $categorization_id
        if ($this->checkIdValue($object_id) === false) {
            return new ErrorInfo('cms_datavalidation', 'No valid object id given');
        }
        if ($this->checkIdValue($categorization_id) === false) {
            return new ErrorInfo('cms_datavalidation', 'No valid categorization id given');
        }

        /*
        // Validation => $categorization_id in categorization_table
        if ($this->_categorization_table !== null) {
            $result_count = $this->_dbcon->COUNT($this->_categorization_table, '`id`=' . $this->_dbcon->ValueToEscapedString($categorization_id));
            if (ErrorInfo::isError($result_count)) {
                return $result_count;
            } else {
                if ($result_count === 0) {
                    return new ErrorInfo('cms_datavalidation', 'Categorization with id ' . $categorization_id . ' not found');
                }
            }
        }

        // Validation => $object_id in field_table
        if ($this->_object_table !== null) {
            $result_count = $this->_dbcon->COUNT($this->_object_table, '`id`=' . $this->_dbcon->ValueToEscapedString($object_id));
            if (ErrorInfo::isError($result_count)) {
                return $result_count;
            } else {
                if ($result_count === 0) {
                    return new ErrorInfo('cms_datavalidation', 'Object with id ' . $object_id . ' not found');
                }
            }
        }
        */

        // Query
        $current_categorization_id = $this->readRelatedCategorizationId($object_id);
        if ($current_categorization_id !== null) {
            $result = $this->_dbcon->SET($this->_table, $this->_categorization_idfield, $categorization_id, '`' . $this->_object_idfield . '`=' . $this->_dbcon->ValueToEscapedString($object_id)); // update current relation for object
        } else {
            $result = $this->_dbcon->ADD($this->_table, array($this->_categorization_idfield => $categorization_id, $this->_object_idfield => $object_id));
        }
        if (ErrorInfo::isError($result)) {
            if ($result->data === 1062) {
                return new ErrorInfo('cms_relationexisting', 'Relation already existing');
            } else {
                return $result;
            }
        }

        $this->addRelationCustomCallback($object_id, $categorization_id, $current_categorization_id);

        return true;
    }

    public function deleteRelationByObject($object_id) {
        // Validation => $object_id
        if ($this->checkIdValue($object_id) === false) {
            return new ErrorInfo('cms_datavalidation', 'No valid object id given');
        }

        // Query
        $current_categorization_id = $this->readRelatedCategorizationId($object_id);
        if ($current_categorization_id !== null) {
            $result_delete = $this->_dbcon->DELETE($this->_table, '`' . $this->_object_idfield . '`=' . $this->_dbcon->ValueToEscapedString($object_id));
            if (ErrorInfo::isError($result_delete)) {
                return $result_delete;
            }
            $this->deleteRelationByObjectCustomCallback($object_id, $current_categorization_id);
            return true;
        } else {
            return false;
        }
    }

    public function deleteRelationsByCategorization($categorization_id) {
        // Validation => $categorization_id
        if ($this->checkIdValue($categorization_id) === false) {
            return new ErrorInfo('cms_datavalidation', 'No valid categorization id given');
        }

        // Query
        $result = $this->_dbcon->DELETE($this->_table, '`' . $this->_categorization_idfield . '`=' . $this->_dbcon->ValueToEscapedString($categorization_id));
        if (ErrorInfo::isError($result)) {
            return $result;
        }

        $this->deleteRelationsByCategorizationCustomCallback($categorization_id);

        return true;
    }

    public function countObjects($categorization_id) {
        return $this->_dbcon->COUNT($this->_table, '`' . $this->_categorization_idfield . '`=' . $this->_dbcon->ValueToEscapedString($categorization_id));
    }

    // ### Helper Functions ########################################
    protected function checkIdValue($id) {
        // Validation => $id
        $ValidationService = new ValidationService(array('id' => $id));
        $ValidationService->validateRules('id', 'objectid');
        return $ValidationService->result();
    }
}
