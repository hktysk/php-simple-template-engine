<?php

require_once('./engine.php');

$template = file_get_contents('templates/index.html');
$template = TemplateEngine(__DIR__.'/templates/index.html');

echo $template;
