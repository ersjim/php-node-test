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
    if (module_service_up() !== SERVICE_UP) {
        module_error("service is down, not sending event");
        return "";
    }

    $maxtime = 1.5;
    $time = $maxtime;
    $output = module_fetch("POST", "http://localhost:3000/platform/service", [
        "event" => $name,
        "params" => $params,
    ], $time);

    $cumulative_time += $time;
    if ($time > 0.5) {
        module_info("time exceeded 0.5 seconds: $time, service down");
        module_host_available(SERVICE_DOWN);
    }
    if ($cumulative_time > 2) {
        module_info("cumulative time exceeded 2 seconds: $cumulative_time, service down");
        module_host_available(SERVICE_DOWN);
    }

    $output = json_decode($output, true);
    if (json_last_error()) {
        module_error("json_decode failed: " . json_last_error_msg());
        return "";
    }

    if ($output["success"] !== true) {
        module_warn("server unsuccessful: " . $output["message"]);
        return "";
    }

    return json_encode($output["result"]) ?? "";
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
    } else {
        // print with red text
        printf("\033[31m[%s] %s\033[0m\n", $type, $message);
    }
}

function module_service_up(): int
{
    static $maxtime = 1.5;
    if (module_host_available() === SERVICE_UNDETERMINED) {
        $time = $maxtime;
        $output = module_fetch("GET", "http://localhost:3000/platform/service", [], $time);
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

function module_fetch(string $method, string $url, array $body, float &$timeout): string
{

    if (!in_array($method, ["GET", "POST"])) {
        module_error("module_fetch: invalid method: $method");
        $timeout = 0.0;
        return "";
    }

    $start_time = microtime(true);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($method === "POST") {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        $payload = json_encode($body);
        if (json_last_error()) {
            module_error("json_encode failed: " . json_last_error_msg());
            return "";
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    // set sub-second timeout
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeout * 1000);
    // on UNIX systems, there's a bug/feature where curl will
    // immediately return with sub-second timeouts because of 
    // something to do with signals. This option disables that:

    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    // set headers - we use json for everything

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
    ]);

    $output = curl_exec($ch);
    $info = curl_getinfo($ch);

    if ($info["http_code"] !== 200) {
        module_error(sprintf("curl failed: %d: %s", $info["http_code"], curl_error($ch)));
        curl_close($ch);
        return "";
    }
    if ($output === false) {
        module_error("curl failed: " . curl_error($ch));
        curl_close($ch);
        return "";
    }

    curl_close($ch);
    $timeout = microtime(true) - $start_time;
    return $output;

}


function run_module_tests() {

    if (!function_exists("assert")) {
        echo "ERROR: assert() is not defined. Please run this script with php -d assert.active=1\n";
        exit(1);
    }

    $output = "";

    $output = sprintf("%s\n'%s'", $output, module_event("echo"));
    assert($output === "");

    $output = sprintf("%s\n'%s'", $output, module_event("echo", 1));
    assert($output === "1");

    $output = sprintf("%s\n'%s'", $output, module_event("echo", "test"));
    assert($output === "test");

    $output = sprintf("%s\n'%s'", $output, module_event("echo", ["test"]));
    assert($output === '["test"]');

    $output = sprintf("%s\n'%s'", $output, module_event("echo", ["test" => "test"]));
    assert($output === '{"test":"test"}');

    assert($output === "test");
    echo $output;
}

if (php_sapi_name() === "cli") {
    run_module_tests();
}
