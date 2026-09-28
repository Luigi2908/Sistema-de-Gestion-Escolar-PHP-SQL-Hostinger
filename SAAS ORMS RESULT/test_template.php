<?php
header('Content-Type: text/plain; charset=utf-8');
echo "DIR: " . __DIR__ . "\n";
echo "SERVER_TIME: " . date('Y-m-d H:i:s') . "\n";
$tch = file_get_contents(__DIR__ . '/teachers.php');
echo "TEACHERS_SIZE: " . strlen($tch) . "\n";
echo "HAS_PLANTILLA_ES: " . (strpos($tch, 'plantilla_importar_docentes') !== false ? 'YES' : 'NO') . "\n";
echo "HAS_OLD_TEMPLATE: " . (strpos($tch, 'teachers_import_template') !== false ? 'YES' : 'NO') . "\n";
echo "FILE_MTIME: " . date('Y-m-d H:i:s', filemtime(__DIR__ . '/teachers.php')) . "\n";
