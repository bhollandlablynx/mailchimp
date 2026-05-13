<?php
/**
 * CLI Mailchimp Campaign Pusher
 * Reads emails from 2026_EMM MongoDB collections filtered by mailchimp_campaign
 * and subscribes them to the matching Mailchimp audience.
 *
 * Usage: php push_campaign.php CAMPAIGN_10
 */

set_time_limit(0);

require_once __DIR__ . '/vendor/autoload.php';

use MongoDB\Client as MongoClient;

// --- Load .env ---
$env_file = __DIR__ . '/.env';
if (file_exists($env_file)) {
    foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        $_ENV[trim($key)] = $value;
        putenv(trim($key) . '=' . $value);
    }
}

// --- Args ---
$campaign = $argv[1] ?? null;
if (!$campaign) {
    echo "Usage: php push_campaign.php <mailchimp_campaign>" . PHP_EOL;
    echo "Example: php push_campaign.php CAMPAIGN_10" . PHP_EOL;
    exit(1);
}

// --- Config ---
$api_key   = getenv('MAILCHIMP_API_KEY');
$server    = getenv('MAILCHIMP_SERVER') ?: 'us1';
$mongo_uri = getenv('MONGO_URI');

if (!$api_key) {
    fwrite(STDERR, "Missing MAILCHIMP_API_KEY in .env" . PHP_EOL);
    exit(1);
}
if (!$mongo_uri) {
    fwrite(STDERR, "Missing MONGO_URI in .env" . PHP_EOL);
    exit(1);
}

$audiences = [
    'lablynx' => getenv('MAILCHIMP_AUDIENCE_LABLYNX') ?: 'df29a58755',
    'jartech'  => getenv('MAILCHIMP_AUDIENCE_JARTECH') ?: '538e791c3e',
];

$collections = [
    'lablynx' => 'lablynx_newsletter',
    'jartech'  => 'jartech_newsletter',
];

// Mailchimp batch endpoint limit
$batch_size = 500;

// --- Connect ---
$mongo = new MongoClient($mongo_uri);
$db    = $mongo->selectDatabase('2026_EMM');

$auth     = base64_encode("anystring:{$api_key}");
$base_url = "https://{$server}.api.mailchimp.com/3.0";

// --- Helper: push one batch to Mailchimp ---
function push_batch(string $base_url, string $auth, string $list_id, array $emails): array {
    $members = array_map(fn($email) => [
        'email_address' => $email,
        'status'        => 'subscribed',
    ], $emails);

    $ch = curl_init("{$base_url}/lists/{$list_id}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Basic {$auth}",
            "Content-Type: application/json",
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'members'         => $members,
            'update_existing' => true,
        ]),
    ]);

    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        fwrite(STDERR, "Mailchimp API error ({$status}): {$body}" . PHP_EOL);
        return ['new_members' => [], 'updated_members' => [], 'errors' => []];
    }

    $result = json_decode($body, true);
    return [
        'new_members'     => $result['new_members']     ?? [],
        'updated_members' => $result['updated_members'] ?? [],
        'errors'          => $result['errors']          ?? [],
    ];
}

// --- Main ---
echo "=== Mailchimp Campaign Pusher ===" . PHP_EOL;
echo "Campaign:  {$campaign}" . PHP_EOL;
echo "Database:  2026_EMM" . PHP_EOL;
echo str_repeat('-', 40) . PHP_EOL;

foreach ($collections as $brand => $coll_name) {
    $list_id = $audiences[$brand];

    // Fetch emails from MongoDB
    $cursor = $db->selectCollection($coll_name)->find(
        ['mailchimp_campaign' => $campaign],
        ['projection' => ['email' => 1, '_id' => 0]]
    );

    $emails = [];
    foreach ($cursor as $doc) {
        $email = trim((string)($doc['email'] ?? ''));
        if ($email !== '') {
            $emails[] = $email;
        }
    }

    $total = count($emails);
    echo PHP_EOL . "[{$brand}] Collection: {$coll_name} | Audience: {$list_id}" . PHP_EOL;
    echo "[{$brand}] Found {$total} email(s) for campaign '{$campaign}'" . PHP_EOL;

    if ($total === 0) {
        echo "[{$brand}] Nothing to push." . PHP_EOL;
        continue;
    }

    $added   = 0;
    $updated = 0;
    $errors  = 0;
    $chunks  = array_chunk($emails, $batch_size);

    foreach ($chunks as $i => $chunk) {
        $batch_num = $i + 1;
        echo "[{$brand}] Pushing batch {$batch_num}/" . count($chunks) . " (" . count($chunk) . " emails)..." . PHP_EOL;

        $result   = push_batch($base_url, $auth, $list_id, $chunk);
        $added   += count($result['new_members']);
        $updated += count($result['updated_members']);
        $errors  += count($result['errors']);

        foreach ($result['errors'] as $err) {
            echo "  ERROR: " . ($err['email_address'] ?? '?') . " — " . ($err['error'] ?? 'unknown') . PHP_EOL;
        }
    }

    echo "[{$brand}] Done — Added: {$added} | Updated: {$updated} | Errors: {$errors}" . PHP_EOL;
}

echo PHP_EOL . str_repeat('=', 40) . PHP_EOL;
echo "Complete." . PHP_EOL;
