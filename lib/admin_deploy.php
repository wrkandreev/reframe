<?php

declare(strict_types=1);

function adminCheckForUpdates(string $projectRoot, string $branch): array
{
    if (!is_dir($projectRoot . '/.git')) {
        throw new RuntimeException('Репозиторий не найден: .git отсутствует');
    }

    $fetch = adminRunShellCommand('git fetch origin ' . escapeshellarg($branch) . ' --prune', $projectRoot);
    if ($fetch['code'] !== 0) {
        throw new RuntimeException('Не удалось обновить данные из origin: ' . adminTailOutput($fetch['output']));
    }

    $local = adminRunShellCommand('git rev-parse --short=12 HEAD', $projectRoot);
    $remote = adminRunShellCommand('git rev-parse --short=12 origin/' . escapeshellarg($branch), $projectRoot);
    $behindRaw = adminRunShellCommand('git rev-list --count HEAD..origin/' . escapeshellarg($branch), $projectRoot);
    $aheadRaw = adminRunShellCommand('git rev-list --count origin/' . escapeshellarg($branch) . '..HEAD', $projectRoot);

    if ($local['code'] !== 0 || $remote['code'] !== 0 || $behindRaw['code'] !== 0 || $aheadRaw['code'] !== 0) {
        throw new RuntimeException('Не удалось определить состояние ветки');
    }

    $behind = (int)trim($behindRaw['output']);
    $ahead = (int)trim($aheadRaw['output']);

    $state = 'up_to_date';
    if ($behind > 0 && $ahead === 0) {
        $state = 'update_available';
    } elseif ($ahead > 0 && $behind === 0) {
        $state = 'local_ahead';
    } elseif ($ahead > 0 && $behind > 0) {
        $state = 'diverged';
    }

    return [
        'state' => $state,
        'branch' => $branch,
        'local_ref' => trim($local['output']),
        'remote_ref' => trim($remote['output']),
        'behind' => $behind,
        'ahead' => $ahead,
        'can_deploy' => $state === 'update_available',
    ];
}

function adminRunDeployScript(string $projectRoot, string $branch, string $scriptPath, string $phpBin): array
{
    if (!is_file($scriptPath)) {
        throw new RuntimeException('Скрипт деплоя не найден: ' . $scriptPath);
    }

    $run = adminRunShellCommand('bash ' . escapeshellarg($scriptPath), $projectRoot, [
        'BRANCH' => $branch,
        'PHP_BIN' => $phpBin,
    ]);

    return [
        'ok' => $run['code'] === 0,
        'code' => $run['code'],
        'output' => adminTailOutput($run['output']),
    ];
}

function adminRunShellCommand(string $command, string $cwd, array $env = []): array
{
    $envPrefix = '';
    foreach ($env as $key => $value) {
        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', (string)$key)) {
            continue;
        }
        $envPrefix .= $key . '=' . escapeshellarg((string)$value) . ' ';
    }

    $fullCommand = 'cd ' . escapeshellarg($cwd) . ' && ' . $envPrefix . $command . ' 2>&1';
    $output = [];
    $code = 0;
    exec($fullCommand, $output, $code);

    return ['code' => $code, 'output' => implode("\n", $output)];
}

function adminTailOutput(string $output, int $maxLines = 80): string
{
    $output = trim($output);
    if ($output === '') {
        return '';
    }

    $lines = preg_split('/\r\n|\r|\n/', $output);
    if (!is_array($lines)) {
        return $output;
    }

    return implode("\n", array_slice($lines, -$maxLines));
}
