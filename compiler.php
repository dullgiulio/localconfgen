<?php

global $argv;

class LocalConfParser {
	function __construct($fileContents, $file) {
		$this->file = $file;
		$this->fileContents = $fileContents;
	}

	function parse() {
		$this->data = json_decode($this->fileContents);
		$error = $this->_checkForErrors();
	
		if ($error != NULL) {
			echo "{$this->file}: $error\n";
			echo "Compilation aborted.\n";
			exit;
		}
	}

	function _checkForErrors() {
		switch (json_last_error()) {
			case JSON_ERROR_DEPTH:
				return 'Maximum stack depth exceeded';
			case JSON_ERROR_STATE_MISMATCH:
				return 'Underflow or the modes mismatch';
			case JSON_ERROR_CTRL_CHAR:
				return 'Unexpected control character found';
			case JSON_ERROR_SYNTAX:
				return 'Syntax error, malformed JSON';
			case JSON_ERROR_UTF8:
				return 'Malformed UTF-8 characters, possibly incorrectly encoded';
		}

		return NULL;
	}

	function getData() {
		return $this->data;
	}
}

class LocalConfIncluder {
	function __construct($modulename) {
		// TODO: Path configurable
		$this->filename = 'conf/'.$modulename.'.json';
		$this->_parsed = FALSE;
	}

	function getFile() {
		return $this->filename;
	}

	function getFileContents() {
		return file_get_contents($this->filename);
	}

	function parse() {
		$this->parser = new LocalConfParser($this->getFileContents(), $this->filename);
		$this->parser->parse();
		$this->_parsed = TRUE;

		return $this->parser;	
	}

	function getRoot() {
		if (!$this->_parsed) {
			$this->parse();
		}

		return new LocalConfElement($this->parser->getData());
	}
}

class LocalConfConfig {
	function __construct($data) {
		if (is_object($data)) {
			$this->data = $data;
		} else {
			$this->data = NULL;
		}
	}

	function getModules() {
		if ($this->data && isset($this->data->modules) && is_array($this->data->modules)) {
			return $this->data->modules;
		}

		return array();
	}

	function getType() {
		if ($this->data) {
			return $this->data->type;
		} else {
			return 'string';
		}
	}
}

class LocalConfValuesContainer {
	function __construct($data) {
		$this->data = $data;
	}

	function getAllValues() {
		return $this->data;
	}
	
	// TODO: renders more than one line (calls render for each element);
}

class LocalConfValue {
	function __construct($value, $name = '', $module = '', $defaultType = 'string') {
		if (is_object($value)) {
			$this->value = $value->value;
			$this->type = $value->type;
		} else {
			$this->value = $value;
			$this->type = $defaultType;
		}

		$this->path = $module;
		$this->name = $name;
	}

	function getName() {
		return $this->name;
	}

	function getValue() {
		return $this->value;
	}

	function getType() {
		return $this->type;
	}

	function getPath() {
		return $this->path;
	}

	function setPath($path) {
		$this->path = $path;
	}

	function getFullName() {
		return $this->path . '.' . $this->name;
	}

	function getFullNameAsArray() {
		return explode('.', $this->getFullName());
	}
}

class LocalConfElement {
	function __construct($data) {
		$this->config = array();
		$this->values = array();
		$this->rawValues = array();

		if (is_object($data)) {
			foreach($data as $key => $val) {
				$this->name = $key;
				$this->path = $this->name;
				
				foreach($val as $key => $data) {
					if ($key == 'config') {
						$this->config = $data;
					} elseif ($key == 'values') {
						$this->rawValues = $data;
					}
				}
				
				break;
			}

			$this->config = new LocalConfConfig($this->config);
		
			// If there are modules, load them:
			$this->modules = $this->_loadModules($this->config->getModules());
			
			foreach($this->modules as $module) {
				$this->appendValues($module->getVariables()->getAllValues());
			}

			$this->appendValues($this->createFromRaw($this->getRawValues()));
		} else {
			echo "Error: Invalid object reached Element container\n";
			var_dump($data);
			exit;
		}
	}

	function _loadModules($modules) {
        $loadedModules = array();
        
		foreach($modules as $module) {
			$inclusion = new LocalConfIncluder($module);
            $inclusion->parse();
		    
			$loadedModules[] = $inclusion->getRoot();
        }

        return $loadedModules;
    }

	function createFromRaw($rawValues) {
		$values = array();
		
		$type = $this->getConfig()->getType();

		foreach($rawValues as $name => $value) {
			$values[] = new LocalConfValue($value, $name, $this->getPath(), $type);
		}

		return $values;
	}

	function getName() {
		return $this->name;
	}

	function getVariables() {
		return new LocalConfValuesContainer($this->values);
	}

	function getRawValues() {
		return $this->rawValues;
	}

	function appendValues($values) {
		foreach($values as $value) {
			if ($this->getPath() != $value->getPath()) {
				$value->setPath($this->getPath() . '.' . $value->getPath());
			}

			$this->values[$value->getFullName()] = $value;
		}
	}

	function getConfig() {
		return $this->config;
	}

	function getPath() {
		if ($this->path) {
			return $this->path;
		}

		return $this->getName();
	}

	function getFullNameAsArray() {
		return explode('.', $this->getFullName());
	}
}

class LocalConfTree {
	function __construct($variables) {
		$this->tree = array();
		
		foreach($variables->getAllValues() as $variable) {
			$path = $variable->getFullNameAsArray();

			$this->_addToTree($path, $variable);
		}

		var_dump($this->tree);
	}

	function _addToTree($path, $variable) {
		$reversePath = array_reverse($path);
		$current = $variable;

		foreach($reversePath as $step) {
			$current = array($step => $current);
		}

		$this->tree = array_merge_recursive($this->tree, $current);
	}
}

class LocalConfRenderer {
	static function pathToArray($path) {
		$array = array();

		foreach($path as $step) {
			$array[] = '[\'' . addslashes($step) . '\']';
		}

		return implode('', $array);
	}

	static function quote($text) {
		return '"' . addslashes($text) . '"';
	}

	static function byType($value, $type) {
		switch($type) {
			case 'php':
				return $value;
			case 'serialized':
				// TODO!
			case 'string':
			default:
				return self::quote($value);
		}
	}

	static function renderVariable($variable) {
		$nameArray = $variable->getFullNameAsArray();
		$name = '$' . $nameArray[0] . self::pathToArray(array_slice($nameArray, 1));
	
		return $name . ' = ' . self::byType($variable->getValue(), $variable->getType());
	}
}

$conffile = new LocalConfIncluder($argv[1]);
$conffile->parse();

$root = $conffile->getRoot();
$variables = $root->getVariables();
$output = array();

$tree = new LocalConfTree($variables);

//foreach($variables->getAllValues() as $variable) {
//	$output[] = LocalConfRenderer::renderVariable($variable);
//}
//
//echo implode("\n", $output);
//echo "\n";

