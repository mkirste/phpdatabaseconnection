<?php
/*
$ValidationService = New ValidationService(array('field1' => 'test', 'field2' => '...'));
$ValidationService->validateHasFields();
$ValidationService->validateRequiredFields(array('field1', 'field2'));
$ValidationService->validateAllowedFields(array('field1', 'field2'));
$ValidationService->validateRules('field1', 'notempty|stringtype');
$ValidationService->validateRegexPattern('field1', '/[^a-z0-9]/i', false, false, 'Field1');
if($ValidationService->result() === false){}
*/

class ValidationService {
    public $DATA;
    public $ERRORS;

    function __construct($DATA) { //$DATA = array('name' => '...', 'gender => '...')
        $this->DATA = $DATA;
        $this->ERRORS = [];
    }

    public function reset() {
        $this->ERRORS = [];
    }

    public function result() {
        if (empty($this->ERRORS)) {
            return true;
        } else {
            return false;
        }
    }

    // ### public validation functions ##################################
    // all functions return an array with errors [['type' => '', 'field' => '', 'msg' => ''], ...] which is logged in $this->ERRORS
    public function validateHasFields() {
        $ERRORS = [];
        if (empty($this->DATA)) {
            $ERRORS[] = ['type' => 'hasfields', 'field' => null, 'msg' => 'There are no fields'];
        }

        $this->logErrors($ERRORS);
        return $ERRORS;
    }

    public function validateRequiredFields($REQUIRED_FIELDS) { // ['field1', 'field2', ...]   
        $MISSING_FIELDS = [];
        foreach ($REQUIRED_FIELDS as $required_field) {
            if (array_key_exists($required_field, $this->DATA) === false) {
                array_push($MISSING_FIELDS, $required_field);
            }
        }

        $ERRORS = [];
        foreach ($MISSING_FIELDS as $missing_field) {
            $ERRORS[] = ['type' => 'requiredfield', 'field' => $missing_field, 'msg' => 'Field "' . $missing_field . '" is required'];
        }

        $this->logErrors($ERRORS);
        return $ERRORS;
    }

    public function validateAllowedFields($ALLOWED_FIELDS) { // ['field1', 'field2', ...]   
        $FORBIDDEN_FIELDS = [];
        foreach ($this->DATA as $existing_field => $existing_input) {
            if (in_array($existing_field, $ALLOWED_FIELDS) === false) {
                array_push($FORBIDDEN_FIELDS, $existing_field);
            }
        }

        $ERRORS = [];
        foreach ($FORBIDDEN_FIELDS as $forbidden_field) {
            $ERRORS[] = ['type' => 'allowedfield', 'field' => $forbidden_field, 'msg' => 'Field "' . $forbidden_field . '" is forbidden'];
        }

        $this->logErrors($ERRORS);
        return $ERRORS;
    }

    public function validateRules($field, $rules, $validate_if_empty = true, $required_field = true, $label = null) {
        $ERRORS = [];
        if (array_key_exists($field, $this->DATA)) { //field does exist
            if (!empty($this->DATA[$field]) || $validate_if_empty) {
                $fieldValidationResult = self::rulesValidation($this->DATA[$field], ($label ? $label : $field), $rules);
                foreach ($fieldValidationResult as $key => $val) {
                    $ERRORS[] = ['type' => $key, 'field' => $field, 'msg' => $val];
                }
            }
        } else { //field does not exist
            if ($required_field) {
                $ERRORS[] = ['type' => 'requiredfield', 'field' => $field, 'msg' => ucfirst($label ? $label : $field) . ' is required'];
            }
        }

        $this->logErrors($ERRORS);
        return $ERRORS;
    }

    public function validateRegexPattern($field, $regex_pattern, $validate_if_empty = true, $required_field = true, $label = null) {
        $ERRORS = [];
        if (array_key_exists($field, $this->DATA)) { //field does exist
            if (!empty($this->DATA[$field]) || $validate_if_empty) {
                if (preg_match($regex_pattern, $this->DATA[$field])) {
                    $ERRORS[] = ['type' => 'regexPattern(' . $regex_pattern . ')', 'field' => $field, 'msg' => ucfirst($label ? $label : $field) . ' has no match with regex pattern "' . $regex_pattern];
                }
            }
        } else { //field does not exist
            if ($required_field) {
                $ERRORS[] = ['type' => 'requiredfield', 'field' => $field, 'msg' => ucfirst($label ? $label : $field) . ' is required'];
            }
        }

        $this->logErrors($ERRORS);
        return $ERRORS;
    }

    // ### private helper functions ##################################
    private function logErrors($ERRORS) {
        foreach ($ERRORS as $error) {
            $this->ERRORS[] = $error;
        }
    }

    // ### public shared input validation functions ##################################
    // rules:notempty|stringtype|integertype|booleantype|objectid
    //       min(x)|max(x)|minlength(x)|maxlength(x)|length(x)
    //       lowercase
    //       alphanumeric|alphanumericdashunderscore|alphanumericdashunderscorespace
    //       alphabeticnumeric|alphabeticnumericdashunderscore|alphabeticnumericdashunderscorespace
    //       filename|url|urlpath|internalurlpath|hostname
    //       mail
    // returns an array with errors ['rulename' => 'Message', ... ] 
    public static function rulesValidation($input, $label, $rules) {
        $label = ucfirst($label);
        $rules = '|' . $rules . '|';

        $ERRORS = [];

        // notempty
        if (strpos($rules, '|notempty|') !== false) {
            if ($input === "" || $input === null) {
                $ERRORS['notempty'] = $label . ' is empty';
            }
        }

        if ($input !== "" && $input !== null) {
            // stringtype
            if (strpos($rules, '|stringtype|') !== false) {
                if (is_string($input) === false) {
                    $ERRORS['stringtype'] = $label . ' is not a string type';
                }
            }

            // integertype
            if (strpos($rules, '|integertype|') !== false) {
                if (is_int($input) === false) {
                    $ERRORS['integertype'] = $label . ' is not an integer type';
                }
            }

            // booleantype
            if (strpos($rules, '|booleantype|') !== false) {
                if (is_bool($input) === false) {
                    $ERRORS['booleantype'] = $label . ' is not boolean type';
                }
            }

            // objectid
            if (strpos($rules, '|objectid|') !== false) {
                if (is_int($input) === false || $input < 0) {
                    $ERRORS['objectid'] = $label . ' is not an objectid (positive integer required)';
                }
            }

            // min(x)
            if (preg_match('/\|min\((-?[0-9]+(\.[0-9]+)?)\)\|/', $rules, $match)) {
                $min = floatval($match[1]);
                if (is_numeric($input) === false || floatval($input) < $min) {
                    $ERRORS['min(' . $min . ')'] = $label . ' is not min ' . $min;
                }
            }

            // max(x)
            if (preg_match('/\|max\((-?[0-9]+(\.[0-9]+)?)\)\|/', $rules, $match)) {
                $max = floatval($match[1]);
                if (is_numeric($input) === false || floatval($input) > $max) {
                    $ERRORS['max(' . $max . ')'] = $label . ' is not max ' . $max;
                }
            }

            // minlength(x)
            if (preg_match('/\|minlength\(([0-9]+)\)\|/', $rules, $match)) {
                $minlength = intval($match[1]);
                if (strlen($input) < $minlength) {
                    $ERRORS['minlength(' . $minlength . ')'] = $label . ' is too short';
                }
            }

            // maxlength(x)
            if (preg_match('/\|maxlength\(([0-9]+)\)\|/', $rules, $match)) {
                $maxlength = intval($match[1]);
                if (strlen($input) > $maxlength) {
                    $ERRORS['maxlength(' . $maxlength . ')'] = $label . ' is too long';
                }
            }

            // length(x)
            if (preg_match('/\|length\(([0-9]+)\)\|/', $rules, $match)) {
                $length = intval($match[1]);
                if (strlen($input) !== $length) {
                    $ERRORS['length(' . $length . ')'] = $label . ' has not a length of ' . $length;
                }
            }

            // lowercase
            if (strpos($rules, '|lowercase|') !== false) {
                if (strtolower($input) !== $input) {
                    $ERRORS['lowercase'] = $label . ' is not lowercase';
                }
            }

            // alphanumeric
            if (strpos($rules, '|alphanumeric|') !== false) {
                if (preg_match('/[^a-z0-9]/i', $input)) {
                    $ERRORS['alphanumeric'] = $label . ' is not alphanumeric';
                }
            }

            // alphanumericdashunderscore
            if (strpos($rules, '|alphanumericdashunderscore|') !== false) {
                if (preg_match('/[^a-z0-9\-_]/i', $input)) {
                    $ERRORS['alphanumericdashunderscore'] = $label . ' is not alphanumericdashunderscore';
                }
            }

            // alphanumericdashunderscorespace
            if (strpos($rules, '|alphanumericdashunderscorespace|') !== false) {
                if (preg_match('/[^a-z0-9\-_ ]/i', $input)) {
                    $ERRORS['alphanumericdashunderscorespace'] = $label . ' is not alphanumericdashunderscorespace';
                }
            }

            // alphabeticnumeric
            if (strpos($rules, '|alphabeticnumeric|') !== false) {
                if (preg_match('/[^\p{L}\p{N}]/iu', $input)) {
                    $ERRORS['alphabeticnumeric'] = $label . ' is not alphabeticnumeric';
                }
            }

            // alphabeticnumericdashunderscore
            if (strpos($rules, '|alphabeticnumericdashunderscore|') !== false) {
                if (preg_match('/[^\p{L}\p{N}\-_]/iu', $input)) {
                    $ERRORS['alphabeticnumericdashunderscore'] = $label . ' is not alphabeticnumericdashunderscore';
                }
            }

            // alphabeticnumericdashunderscorespace
            if (strpos($rules, '|alphabeticnumericdashunderscorespace|') !== false) {
                if (preg_match('/[^\pL\pN\-_ ]/iu', $input)) {
                    $ERRORS['alphabeticnumericdashunderscorespace'] = $label . ' is not alphabeticnumericdashunderscorespace';
                }
            }

            // filename
            if (strpos($rules, '|filename|') !== false) {
                if (preg_match('/[^a-z0-9\-_.]/i', $input) || substr_count($input, '.') !== 1 || substr($input, 0, 1) === '.') {
                    $ERRORS['filename'] = $label . ' is not a valid filename';
                }
            }

            // domain
            if (strpos($rules, '|domain|') !== false) {
                if ((preg_match("/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$/i", $input) //valid chars check
                        && preg_match("/^.{1,253}$/", $input) //overall length check
                        && preg_match("/^[^\.]{1,63}(\.[^\.]{1,63})*$/", $input)) //length of each label
                    !== true
                ) {
                    $ERRORS['domain'] = $label . ' is not a valid domain';
                }
            }

            // url
            if (strpos($rules, '|url|') !== false) {
                if (!filter_var($input, FILTER_VALIDATE_URL)) {
                    $ERRORS['url'] = $label . ' is not a valid url';
                }
            }

            // urlpath
            if (strpos($rules, '|urlpath|') !== false) {
                if ($input !== null && !str_starts_with($input, 'https://') && !str_starts_with($input, 'http://') && !str_starts_with($input, 'www.')) {
                    if (str_starts_with($input, '/') === false) {
                        $ERRORS['urlpath'] = $label . ' must start with a slash ("/")';
                    }
                    if (str_ends_with($input, '/') === true) {
                        $ERRORS['urlpath'] = $label . ' may not end with a slash ("/")';
                    }
                    if (strlen($input) < 2) {
                        $ERRORS['urlpath'] = $label . ' is to short';
                    }
                    if (preg_match('/[^\pL\pN\-_\/]/iu', $input)) {
                        $ERRORS['urlpath'] = $label . ' is not a valid internal path';
                    }
                }
            }

            // internalurlpath
            if (strpos($rules, '|internalurlpath|') !== false) {
                if (str_starts_with($input, '/') === false) {
                    $ERRORS['internalurlpath'] = $label . ' must start with a slash ("/")';
                }
                if (str_ends_with($input, '/') === true) {
                    $ERRORS['internalurlpath'] = $label . ' may not end with a slash ("/")';
                }
                if (strlen($input) < 2) {
                    $ERRORS['internalurlpath'] = $label . ' is to short';
                }
                if (preg_match('/[^\pL\pN\-_\/]/iu', $input)) {
                    $ERRORS['internalurlpath'] = $label . ' is not a valid path';
                }
            }

            // hostname
            if (strpos($rules, '|hostname|') !== false) {
                if (!preg_match('/^(?!\-)(?:(?:[a-zA-Z\d][a-zA-Z\d\-]{0,61})?[a-zA-Z\d]\.){1,126}(?!\d+)[a-zA-Z\d]{1,63}$/', $input)) {
                    $ERRORS['hostname'] = $label . ' is not a valid hostname';
                }
            }

            //mail
            if (strpos($rules, '|mail|') !== false) {
                if (!preg_match('/^[^\x00-\x20()<>@,;:\\".[\]\x7f-\xff]+(?:\.[^\x00-\x20()<>@,;:\\".[\]\x7f-\xff]+)*\@[^\x00-\x20()<>@,;:\\".[\]\x7f-\xff]+(?:\.[^\x00-\x20()<>@,;:\\".[\]\x7f-\xff]+)+$/i', $input)) {
                    $ERRORS['mail'] = $label . ' is not a valid mail address';
                }
            }
        }

        return $ERRORS;
    }
}
