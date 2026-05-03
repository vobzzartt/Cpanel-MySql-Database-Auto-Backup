<?php

// Run via cron only, block browser access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

// Load database config (update path if needed)
$config_path = __DIR__ . '/config.php'; // replace with your config file
if (!file_exists($config_path)) {
    die('config.php not found');
}
require_once $config_path;

// Check required constants
foreach (['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME', 'TIMEZONE'] as $const) {
    if (!defined($const)) {
        die("Missing {$const}");
    }
}

date_default_timezone_set(TIMEZONE);


// Telegram config (replace with your bot details)
define('TELEGRAM_BOT_TOKEN', 'YOUR_TELEGRAM_BOT_TOKEN');
define('TELEGRAM_CHAT_ID', 'YOUR_TELEGRAM_CHAT_ID');


// Backup settings (update path if needed)
define('BACKUP_DIRECTORY', __DIR__ . '/dumps/');
define('MAX_TELEGRAM_FILE_SIZE', 50 * 1024 * 1024);


// Send message to Telegram
function sendTelegramMessage($message)
{
    $payload = json_encode([
        'chat_id' => TELEGRAM_CHAT_ID,
        'text' => $message,
        'parse_mode' => 'HTML',
    ]);

    $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    return $response;
}


// Upload backup file to Telegram
function sendTelegramFile($file_path, $message_caption)
{
    if (!file_exists($file_path) || !is_readable($file_path)) {
        return ['ok' => false, 'error' => 'File not accessible'];
    }

    if (filesize($file_path) > MAX_TELEGRAM_FILE_SIZE) {
        return ['ok' => false, 'error' => 'File too large'];
    }

    $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendDocument');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => TELEGRAM_CHAT_ID,
            'document' => new CURLFile($file_path),
            'caption' => $message_caption,
            'parse_mode' => 'HTML',
        ],
        CURLOPT_TIMEOUT => 300,
    ]);

    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    return $response;
}


// Dump database into a .sql file
function dumpDatabase($file_path)
{
    $db_connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $db_connection->set_charset('utf8mb4');

    if ($db_connection->connect_error) {
        return ['success' => false, 'error' => $db_connection->connect_error];
    }

    $file_handle = fopen($file_path, 'w');
    if (!$file_handle) {
        return ['success' => false, 'error' => 'Cannot write file'];
    }

    fwrite($file_handle, "-- Backup: " . date('Y-m-d H:i:s') . "\n\n");

    $tables = [];
    $result = $db_connection->query("SHOW TABLES");

    while ($row = $result->fetch_array()) {
        $tables[] = $row[0];
    }

    foreach ($tables as $table) {

        fwrite($file_handle, "DROP TABLE IF EXISTS `{$table}`;\n");

        $create = $db_connection->query("SHOW CREATE TABLE `{$table}`")->fetch_assoc();
        fwrite($file_handle, $create['Create Table'] . ";\n\n");

        $offset = 0;
        $limit = 500;

        while (true) {
            $rows = $db_connection->query("SELECT * FROM `{$table}` LIMIT {$limit} OFFSET {$offset}");

            if (!$rows || $rows->num_rows === 0) break;

            while ($row = $rows->fetch_assoc()) {
                $values = array_map(function ($value) use ($db_connection) {
                    return is_null($value) ? 'NULL' : "'" . $db_connection->real_escape_string($value) . "'";
                }, array_values($row));

                fwrite($file_handle, "INSERT INTO `{$table}` VALUES (" . implode(', ', $values) . ");\n");
            }

            $offset += $limit;
        }

        fwrite($file_handle, "\n");
    }

    fclose($file_handle);
    $db_connection->close();

    return ['success' => true];
}


// Delete backup file after sending
function deleteFile($file_path)
{
    if (file_exists($file_path)) {
        unlink($file_path);
    }
}


// Run backup process
$start_time = date('d M Y, h:i A');

$backup_filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
$backup_file_path = BACKUP_DIRECTORY . $backup_filename;

if (!file_exists(BACKUP_DIRECTORY)) {
    mkdir(BACKUP_DIRECTORY, 0750, true);
}

$dump_result = dumpDatabase($backup_file_path);

// Dump failure
if (!$dump_result['success']) {
    sendTelegramMessage(
        "<b>BACKUP FAILED</b>\n\n" .
        "Status: Failed\n" .
        "Database: " . DB_NAME . "\n\n" .
        "Reason: " . $dump_result['error'] . "\n\n" .
        "Time: {$start_time}"
    );
    exit;
}

// Empty file
if (!file_exists($backup_file_path) || filesize($backup_file_path) < 500) {
    sendTelegramMessage(
        "<b>BACKUP FAILED</b>\n\n" .
        "Status: Failed\n" .
        "Database: " . DB_NAME . "\n\n" .
        "Reason: Backup file is empty or invalid\n\n" .
        "Time: {$start_time}"
    );
    deleteFile($backup_file_path);
    exit;
}

// Format size
$file_size_bytes = filesize($backup_file_path);
$file_size = $file_size_bytes > 1048576
    ? round($file_size_bytes / 1048576, 2) . ' MB'
    : round($file_size_bytes / 1024, 2) . ' KB';

// Send file
$caption =
    "Backup ready\n\n" .
    "File: <code>{$backup_filename}</code>\n" .
    "Size: {$file_size}\n" .
    "DB: " . DB_NAME . "\n" .
    "Time: {$start_time}";

$upload_result = sendTelegramFile($backup_file_path, $caption);

// Upload failure
if (empty($upload_result['ok'])) {
    sendTelegramMessage(
        "<b>BACKUP FAILED</b>\n\n" .
        "Status: Failed\n" .
        "Database: " . DB_NAME . "\n\n" .
        "Reason: Upload failed\n\n" .
        "File: <code>{$backup_filename}</code>\n" .
        "Size: {$file_size}\n\n" .
        "Time: {$start_time}"
    );
    exit;
}

// Success
deleteFile($backup_file_path);

$end_time = date('d M Y, h:i A');

sendTelegramMessage(
    "<b>BACKUP SUCCESSFUL</b>\n\n" .
    "Status: Completed\n" .
    "Database: " . DB_NAME . "\n" .
    "Size: {$file_size}\n" .
    "Destination: Telegram\n\n" .
    "File: <code>{$backup_filename}</code>\n\n" .
    "Started: {$start_time}\n" .
    "Finished: {$end_time}"
);

echo "Done\n";
