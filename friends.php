<?php

$config = require __DIR__ . '/config.php';

date_default_timezone_set('Europe/Moscow');

function vkApi($method, array $params, $token, $version)
{
    $params['access_token'] = $token;
    $params['v'] = $version;

    $ch = curl_init('https://api.vk.com/method/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('cURL error: ' . curl_error($ch));
    }
    curl_close($ch);

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new Exception('Invalid VK response: ' . $response);
    }
    if (isset($data['error'])) {
        throw new Exception('VK API error: ' . json_encode($data['error'], JSON_UNESCAPED_UNICODE));
    }

    return $data['response'] ?? null;
}

function loadDb($file)
{
    if (!file_exists($file)) return [];
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $friends = [];

    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (is_array($row) && isset($row['id'])) {
            $friends[(int)$row['id']] = $row;
        }
    }

    return $friends;
}

function saveDb($file, array $friends)
{
    $out = [];
    foreach ($friends as $friend) {
        $out[] = json_encode($friend, JSON_UNESCAPED_UNICODE);
    }
    file_put_contents($file, implode(PHP_EOL, $out) . PHP_EOL, LOCK_EX);
}

function logLine($file, $text)
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL;
    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

function sendGroupMessage($token, $version, $peerId, $text)
{
    return vkApi('messages.send', [
        'peer_id' => $peerId,
        'random_id' => random_int(1, PHP_INT_MAX),
        'message' => $text,
        'dont_parse_links' => 0,
    ], $token, $version);
}

function getFriends($token, $version)
{
    $friends = [];
    $offset = 0;
    $count = 1000;

    while (true) {
        $resp = vkApi('friends.get', [
            'fields' => 'domain',
            'count' => $count,
            'offset' => $offset,
        ], $token, $version);

        if (!isset($resp['items']) || !is_array($resp['items'])) {
            break;
        }

        foreach ($resp['items'] as $item) {
            if (!isset($item['id'])) continue;
            $friends[(int)$item['id']] = [
                'id' => (int)$item['id'],
                'first_name' => $item['first_name'] ?? '',
                'last_name' => $item['last_name'] ?? '',
                'domain' => $item['domain'] ?? ('id' . (int)$item['id']),
                'ts' => time(),
            ];
        }

        $offset += count($resp['items']);
        if ($offset >= (int)($resp['count'] ?? 0)) break;
    }

    return $friends;
}

try {
    $currentFriends = getFriends($config['vk_user_token'], $config['api_version']);
    $oldFriends = loadDb($config['db_file']);

    $added = array_diff_key($currentFriends, $oldFriends);
    $removed = array_diff_key($oldFriends, $currentFriends);

    if (!empty($removed)) {
        foreach ($removed as $friend) {
            $name = trim(($friend['first_name'] ?? '') . ' ' . ($friend['last_name'] ?? ''));
            $domain = $friend['domain'] ?? ('id' . $friend['id']);
            $link = 'https://vk.com/' . $domain;

            $message = "Вас удалили из друзей {$name} Ссылка на него {$link} by ADM00103 (https://vk.ru/id_adm00103)";
            sendGroupMessage($config['vk_group_token'], $config['api_version'], $config['notify_peer_id'], $message);
            logLine($config['log_file'], 'Removed: ' . $name . ' ' . $link);
        }
    }

    if (!empty($added)) {
        foreach ($added as $friend) {
            $name = trim(($friend['first_name'] ?? '') . ' ' . ($friend['last_name'] ?? ''));
            $domain = $friend['domain'] ?? ('id' . $friend['id']);
            logLine($config['log_file'], 'Added: ' . $name . ' https://vk.com/' . $domain);
        }
    }

    saveDb($config['db_file'], $currentFriends);
    file_put_contents($config['state_file'], date('Y-m-d H:i:s') . PHP_EOL, LOCK_EX);

    echo "OK\n";
} catch (Throwable $e) {
    logLine($config['log_file'], 'ERROR: ' . $e->getMessage());
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
}