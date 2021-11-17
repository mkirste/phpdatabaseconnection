<?php

class ErrorInfo {
    public $type; //string
    public $message; //string or array
    public $data;

    function __construct($type, $message = null, $data = null) {
        $this->type = $type;
        $this->message = $message;
        $this->data = $data;
    }

    function toArray() {
        return ['type' => $this->type, 'message' => $this->message, 'data' => $this->data];
    }

    function getErrorMessage() {
        $result = $this->type;

        if ($this->message !== null) {
            if (is_array($this->message)) {
                $result = "";
                foreach ($this->message as $key => $value) {
                    if (is_array($value)) {
                        if (array_key_exists('msg', $value)) {
                            $result .= $value['msg'];
                        }
                    } elseif (is_string($value)) {
                        $result .= $value;
                    }
                }
            } elseif (is_string($this->message)) {
                $result = $this->message;
            }
        }

        return $result;
    }
}
