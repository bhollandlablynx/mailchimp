<?php
/**
 * CLI Mailchimp Archiver
 * Usage: php archive_all.php [lablynx|jartech|both] [max]
 */

set_time_limit(0);

require_once __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;

// --- Load .env ---
$env_file = __DIR__ . '/.env';
if (file_exists($env_file)) {
    foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
        putenv(trim($key) . '=' . trim($value));
    }
}

// --- Config ---
$api_key     = getenv('MAILCHIMP_API_KEY');
$server      = getenv('MAILCHIMP_SERVER') ?: 'us1';
$batch_size  = 1000;   // Mailchimp max per fetch
$concurrency = 10;     // Mailchimp's documented connection limit
$max_retries = 5;      // How many times to retry rate-limited (429) records

if (!$api_key) {
    fwrite(STDERR, "Missing MAILCHIMP_API_KEY env var. Check .env file." . PHP_EOL);
    exit(1);
}

$audiences = [
    'lablynx' => getenv('MAILCHIMP_AUDIENCE_LABLYNX') ?: 'df29a58755',
    'jartech'  => getenv('MAILCHIMP_AUDIENCE_JARTECH') ?: '538e791c3e',
];

// --- Parse CLI arguments ---
$choice = $argv[1] ?? null;
$max    = isset($argv[2]) ? (int) $argv[2] : 0;

if (!$choice || ($choice !== 'both' && !isset($audiences[$choice]))) {
    echo "Usage: php archive_all.php [lablynx|jartech|both] [max]" . PHP_EOL;
    foreach ($audiences as $name => $id) {
        echo "  {$name} => {$id}" . PHP_EOL;
    }
    echo "  both  => archives all audiences sequentially" . PHP_EOL;
    exit(1);
}

$to_archive = $choice === 'both' ? $audiences : [$choice => $audiences[$choice]];

// --- Confirm ---
$max_label = $max > 0 ? $max : 'ALL';
if ($choice === 'both') {
    echo "You are about to archive {$max_label} contacts from BOTH audiences:" . PHP_EOL;
    foreach ($audiences as $name => $id) {
        echo "  " . strtoupper($name) . " ({$id})" . PHP_EOL;
    }
} else {
    $audience_id   = $audiences[$choice];
    $audience_name = strtoupper($choice);
    echo "You are about to archive {$max_label} contacts in {$audience_name} ({$audience_id})" . PHP_EOL;
}
echo "Type YES to confirm: ";
$confirm = trim(fgets(STDIN));
if ($confirm !== 'YES') {
    echo "Aborted." . PHP_EOL;
    exit(0);
}

// --- Setup ---
$base_url = "https://{$server}.api.mailchimp.com/3.0";
$auth     = base64_encode("anystring:{$api_key}");

$guzzle = new Client([
    'headers' => [
        'Authorization' => "Basic {$auth}",
        'Content-Type'  => 'application/json',
    ],
    'http_errors' => false,
]);

$mailchimp = new MailchimpMarketing\ApiClient();
$mailchimp->setConfig(['apiKey' => $api_key, 'server' => $server]);

// --- Archive function ---
function archive_audience(
    string $audience_id,
    string $audience_name,
    int    $max,
    Client $guzzle,
    object $mailchimp,
    string $base_url,
    int    $batch_size,
    int    $concurrency,
    int    $max_retries
): void {
    $archived    = 0;
    $failed      = 0;
    $round       = 0;
    $start       = microtime(true);
    $failed_ids  = [];
    $shown_fails = [];
    $max_label   = $max > 0 ? $max : 'ALL';
    $verbose     = $max > 0;

    try {
        $list_info    = $mailchimp->lists->getList($audience_id);
        $total        = $list_info->stats->member_count;
        $unsubscribed = $list_info->stats->unsubscribe_count;
    } catch (Exception $e) {
        echo "Could not fetch list info: " . $e->getMessage() . PHP_EOL;
        return;
    }

    echo PHP_EOL;
    echo "=== Mailchimp Bulk Archiver ===" . PHP_EOL;
    echo "Audience:       {$audience_name} ({$audience_id})" . PHP_EOL;
    echo "Subscribed:     {$total}" . PHP_EOL;
    echo "Unsubscribed:   {$unsubscribed}" . PHP_EOL;
    echo "Max to archive: {$max_label}" . PHP_EOL;
    echo "Concurrency:    {$concurrency}" . PHP_EOL;
    echo "Batch size:     {$batch_size}" . PHP_EOL;
    echo "Max retries:    {$max_retries} (for 429 rate-limit responses)" . PHP_EOL;
    echo str_repeat('-', 50) . PHP_EOL;

    while (true) {
        if ($max > 0 && $archived >= $max) {
            echo "Reached max of {$max}." . PHP_EOL;
            break;
        }

        $round++;
        $round_start = microtime(true);

        $fetch_count = $batch_size;
        if ($max > 0) {
            $fetch_count = min($batch_size, $max - $archived);
        }

        $offset = count($failed_ids);

        try {
            $members = $mailchimp->lists->getListMembersInfo(
                $audience_id,
                'members.id,members.email_address',
                null,
                $fetch_count,
                $offset
            );
        } catch (Exception $e) {
            echo "FETCH ERROR: " . $e->getMessage() . PHP_EOL;
            break;
        }

        if (empty($members->members)) {
            echo "No more contacts to process." . PHP_EOL;
            break;
        }

        $to_process = [];
        foreach ($members->members as $m) {
            if (!isset($failed_ids[$m->id])) {
                $to_process[$m->id] = $m->email_address;
            }
        }

        if (empty($to_process)) {
            echo "Batch was all known-failed; advancing offset." . PHP_EOL;
            continue;
        }

        $count     = count($to_process);
        $remaining = max(0, ($total + $unsubscribed) - $archived - count($failed_ids));

        echo PHP_EOL . "[Round {$round}] Fetched {$count} (offset {$offset}) | Archived: {$archived} | Stuck: " . count($failed_ids) . " | Remaining: ~{$remaining}" . PHP_EOL;

        $current_queue  = $to_process;
        $attempt        = 0;
        $batch_archived = 0;
        $batch_failed   = 0;

        while (!empty($current_queue) && $attempt < $max_retries) {
            $attempt++;

            if ($attempt > 1) {
                $wait = pow(2, $attempt - 1);
                echo "  Retry attempt {$attempt}: " . count($current_queue) . " rate-limited records (waiting {$wait}s)..." . PHP_EOL;
                sleep($wait);
            }

            $retry_queue = [];

            $requests = function () use ($current_queue, $base_url, $audience_id) {
                foreach ($current_queue as $id => $email) {
                    yield $id => new Request('DELETE', "{$base_url}/lists/{$audience_id}/members/{$id}");
                }
            };

            $pool = new Pool($guzzle, $requests(), [
                'concurrency' => $concurrency,
                'fulfilled' => function ($response, $id) use (
                    &$archived, &$batch_archived, &$failed, &$batch_failed,
                    &$failed_ids, &$retry_queue, &$shown_fails,
                    $current_queue, $verbose, $start
                ) {
                    $status = $response->getStatusCode();
                    $email  = $current_queue[$id] ?? $id;

                    if ($status >= 200 && $status < 300) {
                        $archived++;
                        $batch_archived++;
                        if ($verbose) {
                            echo "  [{$archived}] Archived: {$email}" . PHP_EOL;
                        } elseif ($archived % 250 === 0) {
                            $elapsed = round(microtime(true) - $start, 1);
                            $rate    = round($archived / max($elapsed, 0.1), 1);
                            echo "  [{$elapsed}s] Archived: {$archived} ({$rate}/sec)" . PHP_EOL;
                        }
                    } elseif ($status === 429) {
                        $retry_queue[$id] = $email;
                    } else {
                        $failed++;
                        $batch_failed++;
                        $failed_ids[$id] = true;
                        if (!isset($shown_fails[$id])) {
                            $shown_fails[$id] = true;
                            echo "  STUCK ({$status}): {$email}" . PHP_EOL;
                        }
                    }
                },
                'rejected' => function ($reason, $id) use ($current_queue, &$retry_queue) {
                    $email = $current_queue[$id] ?? $id;
                    $retry_queue[$id] = $email;
                },
            ]);

            $pool->promise()->wait();

            $current_queue = $retry_queue;

            if (!empty($current_queue)) {
                echo "  After attempt {$attempt}: " . count($current_queue) . " still rate-limited" . PHP_EOL;
            }
        }

        foreach ($current_queue as $id => $email) {
            $failed++;
            $batch_failed++;
            $failed_ids[$id] = true;
            if (!isset($shown_fails[$id])) {
                $shown_fails[$id] = true;
                echo "  GAVE UP (still rate-limited after {$max_retries} attempts): {$email}" . PHP_EOL;
            }
        }

        $round_time = round(microtime(true) - $round_start, 1);
        echo "  Round {$round}: +{$batch_archived} archived, +{$batch_failed} failed ({$round_time}s)" . PHP_EOL;
    }

    $elapsed = round(microtime(true) - $start, 1);
    $rate    = $archived > 0 ? round($archived / $elapsed, 1) : 0;

    echo PHP_EOL . str_repeat('=', 50) . PHP_EOL;
    echo "COMPLETE" . PHP_EOL;
    echo "  Audience:     {$audience_name}" . PHP_EOL;
    echo "  Archived:     {$archived}" . PHP_EOL;
    echo "  Stuck/Failed: " . count($failed_ids) . PHP_EOL;
    echo "  Time:         {$elapsed}s" . PHP_EOL;
    echo "  Rate:         {$rate}/sec" . PHP_EOL;
    echo str_repeat('=', 50) . PHP_EOL;

    if (!empty($failed_ids)) {
        echo PHP_EOL . "Stuck records (couldn't be archived via DELETE):" . PHP_EOL;
        print_r(array_keys($failed_ids));
    }
}

// --- Run ---
foreach ($to_archive as $name => $id) {
    archive_audience(
        $id,
        strtoupper($name),
        $max,
        $guzzle,
        $mailchimp,
        $base_url,
        $batch_size,
        $concurrency,
        $max_retries
    );
}
