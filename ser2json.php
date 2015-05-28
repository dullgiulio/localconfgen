<?php

global $argv;

include 'json_indent.php';

$data = unserialize(file_get_contents($argv[1]));

echo json_indent(json_encode($data, JSON_NUMERIC_CHECK));
