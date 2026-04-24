<?php
echo 'GD: ' . (extension_loaded('gd') ? '<b style="color:green">ACTIVE</b>' : '<b style="color:red">ABSENT</b>') . '<br>';
echo 'PHP: ' . phpversion() . '<br>';
echo 'INI: ' . php_ini_loaded_file() . '<br>';
$img = @imagecreatetruecolor(100, 100);
echo 'imagecreatetruecolor: ' . ($img ? '<b style="color:green">OK</b>' : '<b style="color:red">FAIL</b>') . '<br>';
if ($img) { imagedestroy($img); echo 'Compression GD: <b style="color:green">FONCTIONNELLE</b>'; }
