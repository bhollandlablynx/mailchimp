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
use MongoDB\BSON\UTCDateTime;

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

$batch_size = 500;

// --- Connect ---
$mongo = new MongoClient($mongo_uri);
$db    = $mongo->selectDatabase('2026_EMM');

$auth     = base64_encode("anystring:{$api_key}");
$base_url = "https://{$server}.api.mailchimp.com/3.0";

// --- Helper: look up a single member's actual status ---
function lookup_member_status(string $base_url, string $auth, string $list_id, string $email): string {
    $hash = md5(strtolower(trim($email)));
    $ch   = curl_init("{$base_url}/lists/{$list_id}/members/{$hash}?fields=status,unsubscribe_reason");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Basic {$auth}"],
    ]);
    $body       = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        return 'unknown';
    }

    $data          = json_decode($body, true);
    $member_status = $data['status']             ?? 'unknown';
    $unsub_reason  = $data['unsubscribe_reason'] ?? null;

    $labels = [
        'unsubscribed'  => 'unsubscribed',
        'cleaned'       => 'bounced/cleaned',
        'archived'      => 'archived',
        'pending'       => 'pending opt-in',
        'transactional' => 'transactional only',
    ];

    $label = $labels[$member_status] ?? $member_status;
    if ($unsub_reason) {
        $label .= " ({$unsub_reason})";
    }
    return $label;
}

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

    $body      = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        fwrite(STDERR, "Mailchimp API error ({$http_code}): {$body}" . PHP_EOL);
        return ['new_members' => [], 'updated_members' => [], 'errors' => []];
    }

    $result = json_decode($body, true);
    return [
        'new_members'     => $result['new_members']     ?? [],
        'updated_members' => $result['updated_members'] ?? [],
        'errors'          => $result['errors']          ?? [],
    ];
}

// --- Helper: bulk-write mailchimp_status back to MongoDB ---
function update_statuses($collection, array $status_map): void {
    if (empty($status_map)) return;

    $now  = new UTCDateTime();
    $bulk = [];

    foreach ($status_map as $email => $status) {
        $bulk[] = [
            'updateOne' => [
                ['email' => $email],
                ['$set'  => [
                    'mailchimp_status'            => $status,
                    'mailchimp_status_updated_at' => $now,
                ]],
            ],
        ];
    }

    $collection->bulkWrite($bulk);
}

// --- Main ---
echo "=== Mailchimp Campaign Pusher ===" . PHP_EOL;
echo "Campaign:  {$campaign}" . PHP_EOL;
echo "Database:  2026_EMM" . PHP_EOL;
echo str_repeat('-', 40) . PHP_EOL;

foreach ($collections as $brand => $coll_name) {
    $list_id    = $audiences[$brand];
    $collection = $db->selectCollection($coll_name);

    $cursor = $collection->find(
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
        $batch_num  = $i + 1;
        $chunk_size = count($chunk);
        echo "[{$brand}] Pushing batch {$batch_num}/" . count($chunks) . " ({$chunk_size} emails)..." . PHP_EOL;

        $result = push_batch($base_url, $auth, $list_id, $chunk);

        // Build status map for this batch — start with everyone as subscribed
        $status_map = array_fill_keys($chunk, 'subscribed');

        // Overwrite successes to distinguish new vs updated (both remain subscribed)
        foreach ($result['new_members'] as $m) {
            $status_map[$m['email_address']] = 'subscribed';
        }
        foreach ($result['updated_members'] as $m) {
            $status_map[$m['email_address']] = 'subscribed';
        }

        // Look up and overwrite errors with specific reason
        foreach ($result['errors'] as $err) {
            $email  = $err['email_address'] ?? '';
            if ($email === '') continue;
            $reason = lookup_member_status($base_url, $auth, $list_id, $email);
            $status_map[$email] = $reason;
            echo "  ERROR: {$email} — {$reason}" . PHP_EOL;
        }

        // Write all statuses back to MongoDB
        update_statuses($collection, $status_map);

        $added   += count($result['new_members']);
        $updated += count($result['updated_members']);
        $errors  += count($result['errors']);
    }

    echo "[{$brand}] Done — Added: {$added} | Updated: {$updated} | Errors: {$errors}" . PHP_EOL;
}

echo PHP_EOL . str_repeat('=', 40) . PHP_EOL;
echo "Complete." . PHP_EOL;
