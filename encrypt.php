#!/usr/bin/php
<?php
const TIMESTAMP_FILE = '/path/to/the/timestamp/file';
const PRIVATE_DIR = 'path/to/folder/with/files';
const ARCHIVE_DIR = 'path/to/folder/with/archives';
const ENCRYPTED_DIR = 'path/to/folder/with/encrypted/archives';
const PUBLIC_KEY_FILE = '/path/to/your/public/key/as/pem';

$archive_name = basename(PRIVATE_DIR);



// Finding files

$timestamp_new = time();
if(is_readable(TIMESTAMP_FILE)) {
    $timestamp_old = trim(file_get_contents(TIMESTAMP_FILE));
}
else {
    fwrite(STDERR, 'ERROR: Unable to open timestamp file');
    die(1);
}

if($timestamp_old === false) {
    fwrite(STDERR, 'ERROR: Unknown error while reading timestamp file');
    die(2);
}
if (!is_numeric($timestamp_old)) {
    fwrite(STDERR, "ERROR: Invalid timestamp format in file\n");
    die(13);
}
$timestamp_old = (int)$timestamp_old;

if($timestamp_new < $timestamp_old) {
    fwrite(STDERR, 'ERROR: Timestamp provided in the file was wrong');
    die(3);
}

$system_timezone = shell_exec("timedatectl show --value --property=Timezone");
if($system_timezone === false) {
    fwrite(STDERR, 'ERROR: Unknown error while executing timedatectl');
    die(5);
}
date_default_timezone_set(trim($system_timezone));

$date_old = date("Y-m-d H:i:s", $timestamp_old);
$date_new = date("Y-m-d H:i:s", $timestamp_new);

echo "Finding files that were modified between {$date_old} and {$date_new}\n";

$fullpaths = null;
$exec_return = null;
exec("find " . escapeshellarg(PRIVATE_DIR) . " -type f -newermt " . escapeshellarg($date_old) . " ! -newermt " . escapeshellarg($date_new), $fullpaths, $exec_return);
if($exec_return !== 0) {
    fwrite(STDERR, 'ERROR: Command find has failed!');
    die(6);
}


if(count($fullpaths) != 0) {

    // Compression

    echo "Compressing files to an archive\n";

    // Check if 7z is available
    exec("command -v 7z >/dev/null 2>&1", $output, $exec_return);
    if ($exec_return !== 0) {
        fwrite(STDERR, "ERROR: 7z is not installed or not in PATH.\n");
        exit(7);
    }

    if (!is_dir(ARCHIVE_DIR) || !is_writable(ARCHIVE_DIR)) {
        fwrite(STDERR, 'ERROR: Archive directory is unaviable');
        die(8);
    }

    $archive_fullpath = ARCHIVE_DIR . '/' . $archive_name;

    $file_path_list_name = $archive_fullpath . "-" . $timestamp_new . ".txt";
    $file_path_list = fopen($file_path_list_name, 'w');
    if (!$file_path_list) {
        fwrite(STDERR, 'ERROR: Cannot write archive file list to an archive directory');
        die(9);
    }

    // Prepare the command to create the archive
    foreach ($fullpaths as $fullpath) {
        // Ensure the file exists and is within the base folder
        if (strpos($fullpath, PRIVATE_DIR) === 0 && file_exists($fullpath)) {
            $path = substr($fullpath, strlen(PRIVATE_DIR) + 1);
            fwrite($file_path_list, $path . "\n");
        } else {
            echo "WARNING: Skipping invalid file: $fullpath\n";
        }
    }
    fclose($file_path_list);

    chdir(PRIVATE_DIR);

    // Build and execute the 7z command
    $command = "7z a -v4g -m0=lzma2 " . escapeshellarg("{$archive_fullpath}-{$timestamp_new}.7z") . " @" . escapeshellarg($file_path_list_name);
    exec($command, $output, $exec_return);

    if ($exec_return === 0) {
        echo "Archive created successfully.\n";
    } else {
        fwrite(STDERR, "Failed to create archive. Output:\n" . implode("\n", $output));
        die(10);
    }

	unlink($file_path_list_name);

    // Encryption

    $fullpaths_7z = null;
    $exec_return = null;
    exec("find '". escapeshellarg(ARCHIVE_DIR) ."' -type f -name " . escapeshellarg("{$archive_name}-{$timestamp_new}*"), $fullpaths_7z, $exec_return);
    if($exec_return !== 0) {
        fwrite(STDERR, 'ERROR: Command find has failed!');
        die(6);
    }

    $symmetric_key = openssl_random_pseudo_bytes(32); // 256-bit key
    foreach ($fullpaths_7z as $fullpath) {
        // Ensure the file exists and is within the base folder
        if (strpos($fullpath, ARCHIVE_DIR) === 0 && file_exists($fullpath)) {
            $encrypted_path = ENCRYPTED_DIR . substr($fullpath, strlen(ARCHIVE_DIR)) . '.enc';
            encryptFile($fullpath, $encrypted_path, $symmetric_key);
        } else {
            echo "WARNING: Skipping invalid file: $fullpath\n";
        }
    }
    encryptSymmetricKey($symmetric_key, PUBLIC_KEY_FILE, ENCRYPTED_DIR . '/' . $archive_name . '-' . $timestamp_new . '.key');

} else {
    echo "There were no new files found\n";
}

if(file_put_contents(TIMESTAMP_FILE, $timestamp_new) === false) {
    fwrite(STDERR, 'ERROR: Unable to write new timestamp to the file');
    die(4);
}
echo "Timestamp updated\n";


function encryptFile($inputFile, $outputFile, $key) {
    // Open the input file
    $inHandle = fopen($inputFile, 'rb');
    if (!$inHandle) {
        die("Unable to open input file.\n");
    }

    // Open the output file
    $outHandle = fopen($outputFile, 'wb');
    if (!$outHandle) {
        fclose($inHandle);
        die("Unable to open output file.\n");
    }

    // Generate a random IV
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-ctr'));
    // Write the IV to the beginning of the output file
    fwrite($outHandle, $iv);

    // Encrypt the file in chunks to handle large files
    $chunkSize = 8192; // 8KB chunks
    while ($chunk = fread($inHandle, $chunkSize)) {
        $encryptedChunk = openssl_encrypt($chunk, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, $iv);
        if ($encryptedChunk === false) {
            fwrite(STDERR, "Encryption failed: " . openssl_error_string() . "\n");
            die(11);
        }
        fwrite($outHandle, $encryptedChunk);
    }

    fclose($inHandle);
    fclose($outHandle);

    echo "File encrypted successfully: $outputFile\n";
}

function encryptSymmetricKey($symmetricKey, $publicKeyFile, $outputKeyFile) {
    // Load the public key
    $publicKey = file_get_contents($publicKeyFile);
    if (!$publicKey) {
        die("Unable to read public key.\n");
    }

    // Encrypt the symmetric key
    if (!openssl_public_encrypt($symmetricKey, $encryptedKey, $publicKey)) {
        die("Key encryption failed " . openssl_error_string() . "\n");
    }

    // Save the encrypted symmetric key to a file
    file_put_contents($outputKeyFile, $encryptedKey);

    echo "Symmetric key encrypted successfully: $outputKeyFile\n";
}

?>
