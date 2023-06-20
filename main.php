<?php require_once __DIR__ . "/module.php";

$start = microtime(true);

$output = "'".module_event("whatever")."'\n";
$output .= "'".module_event("whatever")."'\n";
$output .= "'".module_event("whatever")."'\n";
$output .= "'".module_event("whatever")."'\n";
$output .= "'".module_event("whatever")."'\n";
$output .= "'".module_event("whatever")."'\n";
$output .= "'".module_event("whatever")."'\n";
$output .= "'".module_event("whatever")."'\n";
$output .= "'".module_event("whatever")."'\n";
$output .= "'".module_event("whatever")."'\n";
echo $output;

$time = microtime(true) - $start;
printf("done in %f seconds\n", $time);
