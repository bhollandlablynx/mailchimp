<?php
/**
 * One-time migration: moves non-essential fields into a 'meta' sub-document
 * on both 2026_EMM.lablynx_newsletter and 2026_EMM.jartech_newsletter.
 *
 * Idempotent — documents that already have a 'meta' field are skipped.
 *
 * Usage: php migrate_to_meta.php
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
        putenv(trim($key) . '=' . $value);
    }
}

$mongo_uri = getenv('MONGO_URI');
if (!$mongo_uri) {
    fwrite(STDERR, "Missing MONGO_URI in .env" . PHP_EOL);
    exit(1);
}

// Fields that remain at the top level
$TOP_LEVEL = [
    '_id'                        => true,
    'first_name'                 => true,
    'last_name'                  => true,
    'title'                      => true,
    'company'                    => true,
    'email'                      => true,
    'mailchimp_campaign'         => true,
    'mailchimp_status'           => true,
    'mailchimp_status_updated_at'=> true,
    'meta'                       => true,  // already migrated — skip
];

$BULK_SIZE   = 500;
$COLLECTIONS = ['lablynx_newsletter', 'jartech_newsletter'];

$mongo = new MongoClient($mongo_uri);
$db    = $mongo->selectDatabase('2026_EMM');

foreach ($COLLECTIONS as $coll_name) {
    $collection = $db->selectCollection($coll_name);
    $total      = $collection->countDocuments();

    echo PHP_EOL . "=== {$coll_name} ({$total} documents) ===" . PHP_EOL;

    $migrated  = 0;
    $skipped   = 0;
    $bulk      = [];

    $cursor = $collection->find(
        ['meta' => ['$exists' => false]],  // only docs not yet migrated
        ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]
    );

    foreach ($cursor as $doc) {
        $meta  = [];
        $unset = [];

        foreach ($doc as $field => $value) {
            if (isset($TOP_LEVEL[$field])) continue;
            $meta[$field]  = $value;
            $unset[$field] = '';
        }

        if (empty($meta)) {
            $skipped++;
            continue;
        }

        $bulk[] = [
            'updateOne' => [
                ['_id' => $doc['_id']],
                [
                    '$set'   => ['meta' => $meta],
                    '$unset' => $unset,
                ],
            ],
        ];

        if (count($bulk) >= $BULK_SIZE) {
            $collection->bulkWrite($bulk);
            $migrated += count($bulk);
            $bulk      = [];
            echo "  Migrated: {$migrated}" . PHP_EOL;
        }
    }

    // Flush remaining
    if (!empty($bulk)) {
        $collection->bulkWrite($bulk);
        $migrated += count($bulk);
    }

    echo "  Done — Migrated: {$migrated} | Already had meta (skipped): {$skipped}" . PHP_EOL;
}

echo PHP_EOL . "Migration complete." . PHP_EOL;
