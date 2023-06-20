<?php

define("HEARTBEAT_TRUE", 1);
define("HEARTBEAT_NULL", 0);
define("HEARTBEAT_FALSE", -1);

function modules_available(...$setval): int
{
    static $value = HEARTBEAT_NULL;
    $setval = $setval[0] ?? NULL;
    if ($setval && is_numeric($setval) && ($setval === 1 || $setval === -1)) {
        $value = $setval;
    }
    return $value;
}

function module_event(string $name, ...$args): string
{
    if (module_heartbeat() !== 1) {
        return "";
    }

    $ctx = stream_context_create(["http"=>["timeout"=>5]]);
    $start = microtime(true);
    // TODO - make this a POST request with the event info encoded:
    $output = file_get_contents("http://localhost:3000/event", false, $ctx);
    $time = microtime(true) - $start;
    if ($time > 2) {
        modules_available(HEARTBEAT_FALSE);
    }

    return strval($output);
}

function module_heartbeat()
{
    static $maxtime = 0.01;
    if (modules_available() === HEARTBEAT_NULL) {
        $ctx = stream_context_create(["http"=>["timeout"=>$maxtime]]);
        $start = microtime(true);
        $output = file_get_contents("http://localhost:3000/heartbeat", false, $ctx);
        $time = microtime(true) - $start;
        //printf("heartbeat time: %f\n", $time);
        if ($time < $maxtime) {
            modules_available(HEARTBEAT_TRUE);
        } else {
            modules_available(HEARTBEAT_FALSE);
        }
    }
    return modules_available();
}
