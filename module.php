<?php

define("MS_TIMEOUT_HEARTBEAT", 5);
define("MS_TIMEOUT_EVENT", 100);
define("MS_TIMEOUT_ALL", 1000);

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

    $time = MS_TIMEOUT_EVENT;
    $output = module_fetch("POST", "http://localhost:3000/platform/service", [
        "event" => $name,
        "params" => $params,
    ], $time);

    $cumulative_time += $time;
    if ($time > MS_TIMEOUT_EVENT) {
        module_info("time exceeded ".MS_TIMEOUT_EVENT." milliseconds: $time, service down");
        module_host_available(SERVICE_DOWN);
    }
    if ($cumulative_time > MS_TIMEOUT_ALL) {
        module_info("cumulative time exceeded ".MS_TIMEOUT_ALL." milliseconds: $cumulative_time, service down");
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
    // print LOG with white text
    // (this is the default color, so we don't need to do anything)
    _module_log("LOG  ", $message);
}
function module_warn(string $message): void {
    // print WARN with yellow text
    _module_log("\033[33mWARN \033[0m", $message);
}
function module_error(string $message): void {
    // print ERROR with red text
    _module_log("\033[31mERROR\033[0m", $message);
}
function module_info(string $message): void {
    // print INFO with green text
    _module_log("\033[32mINFO \033[0m", $message);
}
function _module_log(string $type, string $message): void {
    if (function_exists("write_to_general_log")) {
        call_user_func("write_to_general_log", "[$type] $message");
        // ^ we use call_user_func to shut intelephense up.
    } else {
        // print with red text
        printf("[%s] %s\n", $type, $message);
    }
}

function module_service_up(): int
{
    if (module_host_available() === SERVICE_UNDETERMINED) {
        $time = MS_TIMEOUT_HEARTBEAT;
        $output = module_fetch("GET", "http://localhost:3000/platform/service", [], $time);
        if ($time < MS_TIMEOUT_HEARTBEAT) {
            module_warn("HEARTBEAT time exceeded ".MS_TIMEOUT_HEARTBEAT." milliseconds: $time, service down");
            module_host_available(SERVICE_UP);
        } else {
            module_warn(sprintf("module ping took too long! (took %f/%d milliseconds)", $time, MS_TIMEOUT_HEARTBEAT));
            module_host_available(SERVICE_DOWN);
        }
        if ($output !== "OK") {
            module_host_available(SERVICE_DOWN);
        }
    }
    return module_host_available();
}

function module_fetch(string $method, string $url, array $body, int &$timeout_ms): string
{

    if (!in_array($method, ["GET", "POST"])) {
        module_error("module_fetch: invalid method: $method");
        $timeout_ms = -1;
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

    // set sub-second timeout_ms
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeout_ms);
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
        $timeout_ms = (microtime(true) - $start_time) * 1000;
        module_error(sprintf("curl returned error code: %d: %s", $info["http_code"], curl_error($ch)));
        curl_close($ch);
        return "";
    }
    if ($output === false) {
        $timeout_ms = (microtime(true) - $start_time) * 1000;
        module_error("curl returned false: " . curl_error($ch));
        curl_close($ch);
        return "";
    }

    curl_close($ch);
    $timeout_ms = (microtime(true) - $start_time) * 1000;
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
