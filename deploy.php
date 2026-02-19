<?php

declare(strict_types=1);

$configPath = __DIR__ . '/deploy-config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "deploy-config.php not found. Create it from deploy-config.php.example\n";
    exit;
}

/** @var array<string,mixed> $config */
$config = require $configPath;
$tokenExpected = (string)($config['token'] ?? '');
$allowedIps = (array)($config['allowed_ips'] ?? []);
$basicUser = (string)($config['basic_auth_user'] ?? '');
$basicPass = (string)($config['basic_auth_pass'] ?? '');
$branch = (string)($config['branch'] ?? 'main');
$deployScript = (string)($config['deploy_script'] ?? (__DIR__ . '/scripts/deploy.sh'));
$logFile = (string)($config['log_file'] ?? (__DIR__ . '/data/deploy-webhook.log'));

header('Content-Type: text/plain; charset=utf-8');

if ($basicUser !== '' || $basicPass !== '') {
    $authUser = $_SERVER['PHP_AUTH_USER'] ?? '';
    $authPass = $_SERVER['PHP_AUTH_PW'] ?? '';
    if (!hash_equals($basicUser, (string)$authUser) || !hash_equals($basicPass, (string)$authPass)) {
        header('WWW-Authenticate: Basic realm="Deploy"');
        http_response_code(401);
        echo "Unauthorized\n";
        exit;
    }
}

$clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if ($allowedIps !== [] && !in_array($clientIp, $allowedIps, true)) {
    http_response_code(403);
    echo "Forbidden: IP not allowed\n";
    logLine($logFile, "DENY ip={$clientIp} reason=ip_not_allowed");
    exit;
}

$tokenIncoming = (string)($_REQUEST['token'] ?? '');
if ($tokenExpected === '' || !hash_equals($tokenExpected, $tokenIncoming)) {
    http_response_code(403);
    echo "Forbidden: invalid token\n";
    logLine($logFile, "DENY ip={$clientIp} reason=bad_token");
    exit;
}

if (!is_file($deployScript)) {
    http_response_code(500);
    echo "Deploy script not found\n";
    logLine($logFile, "ERROR ip={$clientIp} reason=script_missing path={$deployScript}");
    exit;
}

$cmd = 'BRANCH=' . escapeshellarg($branch) . ' bash ' . escapeshellarg($deployScript) . ' 2>&1';
exec($cmd, $output, $code);

$preview = implode("\n", array_slice($output, -30));
logLine(
    $logFile,
    "RUN ip={$clientIp} code={$code} branch={$branch} output=" . str_replace(["\n", "\r"], ['\\n', ''], $preview)
);

if ($code !== 0) {
    http_response_code(500);
    echo "Deploy failed\n\n";
    echo $preview . "\n";
    exit;
}

echo "OK: deploy completed\n\n";
echo $preview . "\n";

function logLine(string $path, string $line): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($path, "[{$ts}] {$line}\n", FILE_APPEND);
}
