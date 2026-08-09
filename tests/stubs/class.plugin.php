<?php
// Stub. Signaturen 1:1 aus osTicket 1.18.x include/class.plugin.php.
abstract class PluginConfig {
    public $data = array();
    function get($key, $default=null) { return array_key_exists($key,$this->data) ? $this->data[$key] : $default; }
    function set($key,$val) { $this->data[$key]=$val; }
    function getOptions() { return array(); }
    function pre_save(&$config, &$errors) { return true; }
}
class PluginInstance {}
class Plugin {
    public static $testConfig = null;          // vom Harness gesetzt
    var $config_class;
    function getConfig(?PluginInstance $instance = null, $defaults = []) { return self::$testConfig; }
    function isMultiInstance() { return true; }
    function isCompatible() { return true; }
}
