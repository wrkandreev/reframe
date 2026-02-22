<?php

declare(strict_types=1);

function adminCheckForUpdates(string $projectRoot, string $branch, string $remoteName = 'origin', string $remoteUrl = ''): array
{
    if (!is_dir($projectRoot . '/.git')) {
        throw new RuntimeException('Репозиторий не найден: .git отсутствует');
    }

    $remoteName = adminNormalizeRemoteName($remoteName);
    adminEnsureRemote($projectRoot, $remoteName, $remoteUrl);

    $remoteRef = $remoteName . '/' . $branch;

    $fetch = adminRunShellCommand('git fetch ' . escapeshellarg($remoteName) . ' ' . escapeshellarg($branch) . ' --prune', $projectRoot);
    if ($fetch['code'] !== 0) {
        throw new RuntimeException('Не удалось обновить данные из ' . $remoteName . ': ' . adminTailOutput($fetch['output']));
    }

    $local = adminRunShellCommand('git rev-parse --short=12 HEAD', $projectRoot);
    $remote = adminRunShellCommand('git rev-parse --short=12 ' . escapeshellarg($remoteRef), $projectRoot);
    $behindRaw = adminRunShellCommand('git rev-list --count HEAD..' . escapeshellarg($remoteRef), $projectRoot);
    $aheadRaw = adminRunShellCommand('git rev-list --count ' . escapeshellarg($remoteRef) . '..HEAD', $projectRoot);

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
        'remote_name' => $remoteName,
        'branch' => $branch,
        'local_ref' => trim($local['output']),
        'remote_ref' => trim($remote['output']),
        'behind' => $behind,
        'ahead' => $ahead,
        'can_deploy' => $state === 'update_available',
    ];
}

function adminRunDeployScript(string $projectRoot, string $branch, string $scriptPath, string $phpBin, string $remoteName = 'origin', string $remoteUrl = ''): array
{
    if (!is_file($scriptPath)) {
        throw new RuntimeException('Скрипт деплоя не найден: ' . $scriptPath);
    }

    $remoteName = adminNormalizeRemoteName($remoteName);

    $run = adminRunShellCommand('bash ' . escapeshellarg($scriptPath), $projectRoot, [
        'BRANCH' => $branch,
        'PHP_BIN' => $phpBin,
        'REMOTE_NAME' => $remoteName,
        'REMOTE_URL' => $remoteUrl,
    ]);

    return [
        'ok' => $run['code'] === 0,
        'code' => $run['code'],
        'output' => adminTailOutput($run['output']),
    ];
}

function adminEnsureRemote(string $projectRoot, string $remoteName, string $remoteUrl): void
{
    $getRemote = adminRunShellCommand('git remote get-url ' . escapeshellarg($remoteName), $projectRoot);
    if ($getRemote['code'] !== 0) {
        if ($remoteUrl === '') {
            throw new RuntimeException('Remote ' . $remoteName . ' не найден');
        }

        $add = adminRunShellCommand('git remote add ' . escapeshellarg($remoteName) . ' ' . escapeshellarg($remoteUrl), $projectRoot);
        if ($add['code'] !== 0) {
            throw new RuntimeException('Не удалось добавить remote ' . $remoteName . ': ' . adminTailOutput($add['output']));
        }
        return;
    }

    if ($remoteUrl === '') {
        return;
    }

    $currentUrl = trim($getRemote['output']);
    if ($currentUrl === $remoteUrl) {
        return;
    }

    $set = adminRunShellCommand('git remote set-url ' . escapeshellarg($remoteName) . ' ' . escapeshellarg($remoteUrl), $projectRoot);
    if ($set['code'] !== 0) {
        throw new RuntimeException('Не удалось обновить remote ' . $remoteName . ': ' . adminTailOutput($set['output']));
    }
}

function adminNormalizeRemoteName(string $remoteName): string
{
    $remoteName = trim($remoteName);
    if ($remoteName === '') {
        return 'origin';
    }

    if (!preg_match('/^[A-Za-z0-9._-]+$/', $remoteName)) {
        throw new RuntimeException('Некорректное имя remote');
    }

    return $remoteName;
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
