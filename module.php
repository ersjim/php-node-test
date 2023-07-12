<?php

define("SERVICE_UP", 1);
define("SERVICE_UNDETERMINED", 0);
define("SERVICE_DOWN", -1);

function module_host_available(...$setval): int
{
    static $value = SERVICE_UNDETERMINED;
    $setval = $setval[0] ?? NULL;
    if ($setval && is_numeric($setval) && ($setval === 1 || $setval === -1)) {
        $value = $setval;
    }
    return $value;
}

// TODO - cumulative time accounting
//        (module overhead should never be above 2 seconds per request)
// TODO - rpc registration file (could replace the heartbeat, actually)
// TODO - consider async requests (curl_multi_exec) or request batching
//        (by returning a handle instead of the result)
// heartbeat = GET  /platform/service
// event     = POST /platform/service with {event, params} in body
function module_event(string $name, ...$params): string
{
    static $cumulative_time = 0;
    if (module_service_up() !== 1) {
        return "";
    }

    $content = json_encode([
        "event" => $name,
        "params" => $params,
    ]);
    if (json_last_error()) {
        module_error("json_encode failed: " . json_last_error_msg());
        return "";
    }

    $ctx = stream_context_create(
        [
            "http" => [
                "timeout" => 1, // 1 sec timeout
                "method" => "POST",
                "header" => "Content-Type: application/json",
                // TODO - you need to encode the event name also!
                "content" => $content,
            ],
        ],
    );

    $start = microtime(true);
    $output = file_get_contents("http://localhost:3000/platform/service", false, $ctx);
    $time = microtime(true) - $start;
    $cumulative_time += $time;
    if ($time > 0.5) {
        module_host_available(SERVICE_DOWN);
    }
    if ($cumulative_time > 2) {
        module_host_available(SERVICE_DOWN);
    }

    $output = json_decode($output, true);
    if ($output["success"] !== true) {
        // TODO - do something with $output["message"]
        return "";
    }
    return strval($output["result"]);
}

// LOGGING functions:
function module_log(string $message): void {
    _module_log("LOG  ", $message);
}
function module_warn(string $message): void {
    _module_log("WARN ", $message);
}
function module_error(string $message): void {
    _module_log("ERROR", $message);
}
function module_info(string $message): void {
    _module_log("INFO ", $message);
}
function _module_log(string $type, string $message): void {
    if (function_exists("write_to_general_log")) {
        call_user_func("write_to_general_log", "[$type] $message");
        // ^ we use call_user_func to shut intelephense up.
    }
}

function module_service_up(): int
{
    static $maxtime = 0.01;
    if (module_host_available() === SERVICE_UNDETERMINED) {
        $ctx = stream_context_create(["http" => ["timeout"=>$maxtime]]);
        $start = microtime(true);
        $output = file_get_contents("http://localhost:3000/platform/service", false, $ctx);
        $time = microtime(true) - $start;
        if ($time < $maxtime) {
            module_host_available(SERVICE_UP);
        } else {
            module_host_available(SERVICE_DOWN);
        }
        if ($output !== "OK") {
            module_host_available(SERVICE_DOWN);
        }
    }
    return module_host_available();
}


function run_module_tests() {

    if (!function_exists("assert")) {
        echo "ERROR: assert() is not defined. Please run this script with php -d assert.active=1\n";
        exit(1);
    }

    $output = module_event("echo");
    assert($output === "");

    $output = module_event("echo", 1);
    assert($output === "1");

    $output = module_event("echo", "test");
    assert($output === "test");

    $output = module_event("echo", ["test"]);
    assert($output === '["test"]');

    $output = module_event("echo", ["test" => "test"]);
    assert($output === '{"test":"test"}');

    assert($output === "test");
}

if (php_sapi_name() === "cli") {
    run_module_tests();
}
