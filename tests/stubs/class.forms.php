<?php
// Stub. Signaturen 1:1 aus osTicket 1.18.x include/class.forms.php.
class FormField {
    static $more_types = array();
    public $_errors = array(); public $_config = array(); public $ht = array();
    function __construct($options=array()) { $this->ht = $options; }
    static function addFieldTypes($group, $callable) { static::$more_types[$group][] = $callable; }
    static function allTypes() {
        $out=array();
        foreach (static::$more_types as $g=>$cbs) foreach ($cbs as $cb) $out=array_merge($out,$cb());
        return $out;
    }
    function get($key,$default=null){ return array_key_exists($key,$this->ht)?$this->ht[$key]:$default; }
    function getLocal($k){ return $this->get($k); }
    function addError($message, $index=false) { $this->_errors[] = $message; }
    function errors() { return $this->_errors; }
    function getFormName() { return 'field_1'; }
    function getFormNames() { return array('field_1'); }
    function getSource() { return ($_SERVER['REQUEST_METHOD'] ?? 'GET')=='POST' ? $_POST : array(); }
    function getAnswer() { return null; }
    function getConfiguration() { return $this->_config; }
    function getConfigurationOptions() { return array(); }
    function validateEntry($value) { if (!$value && count($this->_errors)) return; }
    function to_database($value) { return $value; }
    function toString($value) { return (string)$value; }
    function display($value) { return $value; }
    function hasData() { return true; }
}
class Widget {
    static $media = null;
    public $field, $name, $id;
    function __construct($field) { $this->field=$field; $this->name=$field->getFormName(); $this->id='_'.$this->name; }
    function getValue() {
        $data=$this->field->getSource();
        foreach ($this->field->getFormNames() as $n) if (isset($data[$n])) return $data[$n];
        return null;
    }
}
class TextboxField extends FormField {}
class BooleanField extends FormField {}
class ChoiceField extends FormField {}
class SectionBreakField extends FormField {}
