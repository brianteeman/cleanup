<?php
/**
 * Joomla Image Cleanup Script
 * ---------------------------
 * Moves or deletes unused images from /images that are not referenced in the database.
 *
 * Features:
 *  - Handles images in HTML fields and JSON fields
 *  - Supports Joomla tables: content, categories, menu, modules, fields_values
 *  - Extracts image keys: image_intro, image_fulltext, image, menu_image, backgroundimage, imagefile
 *  - Whitelisted folders (banners, headers, sampledata) are never touched
 *  - Dry-run mode (--dry-run) shows actions without changing files
 *  - Delete mode (--delete) deletes unused images instead of moving
 *  - Quiet mode (--quiet) suppresses console output for cron jobs
 *  - Logs all actions to cleanup-log.txt
 *  - Maintains subfolder structure in /unused
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

/***************************************
 * CONFIG DEFAULTS (CLI options override)
 ***************************************/
$dryRun = false;               // Show actions but do not move/delete
$deleteInsteadOfMove = false;  // Delete unused files instead of moving
$quiet = false;                // Suppress console output (cron-friendly)

// Paths
$rootPath   = __DIR__;
$imagesPath = $rootPath . '/images';
$unusedPath = $rootPath . '/unused';
$logFile    = $rootPath . '/cleanup-log.txt';

// Folders inside /images to never touch
$whitelistFolders = ['banners', 'headers', 'sampledata'];

// Joomla configuration file
$dbConfig = $rootPath . '/configuration.php';
require_once $dbConfig;
$config = new JConfig();

/***************************************
 * CLI ARGUMENT PARSER
 ***************************************/
if (php_sapi_name() === 'cli') {
    global $argv;
    foreach ($argv as $arg) {
        switch ($arg) {
            case '--dry-run':
                $dryRun = true;
                break;
            case '--delete':
                $deleteInsteadOfMove = true;
                break;
            case '--quiet':
                $quiet = true;
                break;
        }
    }
}

/***************************************
 * LOGGING FUNCTION
 ***************************************/
function logMessage($msg)
{
    global $quiet, $logFile;
    if (!$quiet) echo $msg . "\n"; // Console output if not quiet
    file_put_contents($logFile, "[".date("Y-m-d H:i:s")."] $msg\n", FILE_APPEND);
}

logMessage("=== Joomla Image Cleanup Started ===");

// Ensure /unused folder exists
if (!is_dir($unusedPath)) {
    mkdir($unusedPath, 0755, true);
}

/***************************************
 * DATABASE CONNECTION
 ***************************************/
$mysqli = new mysqli($config->host, $config->user, $config->password, $config->db);
if ($mysqli->connect_errno) {
    logMessage("DB connection failed: " . $mysqli->connect_error);
    die();
}
$mysqli->set_charset("utf8mb4");

/***************************************
 * TABLES CONFIGURATION
 * Specify columns and JSON columns
 ***************************************/
$tablesToScan = [
    ['table' => '#__content',        'columns' => ['introtext', 'fulltext', 'images'], 'jsonColumns' => ['images']],
    ['table' => '#__categories',     'columns' => ['description', 'params'], 'jsonColumns' => ['params']],
    ['table' => '#__contact_details','columns' => ['image']],
    ['table' => '#__menu',           'columns' => ['params'], 'jsonColumns' => ['params']],
    ['table' => '#__modules',        'columns' => ['content', 'params'], 'jsonColumns' => ['params']], // content may contain HTML images
    ['table' => '#__fields_values',  'columns' => ['value'], 'jsonColumns' => ['value']], // value may contain JSON with imagefile
];

// Replace Joomla table prefix
foreach ($tablesToScan as &$entry) {
    $entry['table'] = str_replace('#__', $config->dbprefix, $entry['table']);
}

/***************************************
 * IMAGE EXTRACTION FUNCTION
 * Supports HTML and JSON formats
 ***************************************/

function extractImages($text, $isJson = false)
{
    $results = [];
    if (!is_string($text) || trim($text) === '') return $results;

    // Helper to normalize filenames
    $normalize = function($img) {
        // Remove Joomla suffix (#joomlaImage://…)
        $img = explode('#', $img)[0];

        // URL decode (%20 → space, etc)
        $img = urldecode($img);

        // Normalize leading slash
        return ltrim($img, '/');
    };

    if ($isJson) {
        $data = json_decode($text, true);
        if (is_array($data)) {
            $keys = [
                'image_intro',
                'image_fulltext',
                'image',
                'menu_image',
                'backgroundimage',
                'imagefile'
            ];
            foreach ($keys as $key) {
                if (!empty($data[$key])) {
                    $results[] = $normalize($data[$key]);
                }
            }
        }
        return $results;
    }

    // HTML/Plain-text extraction
    preg_match_all('/images\/[a-zA-Z0-9_\-\s\/\.%]+/i', $text, $matches);
    foreach ($matches[0] as $match) {
        $results[] = $normalize($match);
    }

    return $results;
}

/***************************************
 * STEP 1 – COLLECT REFERENCED DATABASE IMAGES
 ***************************************/
$dbImages = [];
logMessage("Scanning database...");

foreach ($tablesToScan as $entry) {
    $jsonCols = isset($entry['jsonColumns']) ? $entry['jsonColumns'] : [];
    foreach ($entry['columns'] as $column) {
        $sql = "SELECT `$column` FROM `{$entry['table']}`";
        $res = $mysqli->query($sql);
        if (!$res) {
            logMessage("Failed query: $sql");
            continue;
        }

        while ($row = $res->fetch_assoc()) {
            // Determine if column is JSON
            $isJson = in_array($column, $jsonCols);
            $dbImages = array_merge($dbImages, extractImages($row[$column], $isJson));
        }
    }
}

$dbImages = array_unique($dbImages);

/***************************************
 * STEP 2 – SCAN DISK FOR ALL IMAGES
 ***************************************/
logMessage("Scanning /images directory...");

$diskImages = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($imagesPath, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile()) continue;

    $relative = str_replace($rootPath . '/', '', $file->getPathname());
    $relative = str_replace('\\', '/', $relative);

    // Skip whitelisted folders
    foreach ($whitelistFolders as $folder) {
        if (strpos($relative, "images/$folder") === 0) {
            continue 2;
        }
    }

    // Skip /unused folder
    if (strpos($relative, "images/unused/") === 0) continue;

    $diskImages[] = $relative;
}

sort($diskImages);

/***************************************
 * STEP 3 – DETERMINE UNUSED IMAGES
 ***************************************/
$unused = array_diff($diskImages, $dbImages);
$unused = array_unique($unused);

logMessage("Unused images detected: " . count($unused));

/***************************************
 * STEP 4 – MOVE OR DELETE UNUSED IMAGES
 ***************************************/
foreach ($unused as $img) {
    $source = "$rootPath/$img";

    // Maintain folder structure in /unused
    $relativeSub = substr($img, strlen('images/'));
    $target = "$unusedPath/$relativeSub";
    $targetDir = dirname($target);

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    if (!file_exists($source)) {
        logMessage("Missing (skipped): $img");
        continue;
    }

    if ($dryRun) {
        logMessage("[DRY RUN] Would " . ($deleteInsteadOfMove ? "delete" : "move") . ": $img");
        continue;
    }

    if ($deleteInsteadOfMove) {
        if (unlink($source)) {
            logMessage("Deleted: $img");
        } else {
            logMessage("FAILED to delete: $img");
        }
    } else {
        if (rename($source, $target)) {
            logMessage("Moved: $img -> unused/$relativeSub");
        } else {
            logMessage("FAILED to move: $img");
        }
    }
}

logMessage("=== Joomla Image Cleanup Complete ===");

if (!$quiet) echo "Done.\n";
