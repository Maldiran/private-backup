#!/usr/bin/php
<?php
const ENCRYPTED_DIR = 'path/to/folder/with/encrypted/archives';
const DECRYPTED_DIR = 'path/to/folder/with/decrypted/archives';
const PRIVATE_KEY_FILE = '/path/to/your/private/key/as/pem';


$fullpaths_enc = null;
$exec_return = null;
exec("find '". escapeshellarg(ENCRYPTED_DIR) ."' -type f -name '*.enc'", $fullpaths_enc, $exec_return);
if($exec_return !== 0) {
    fwrite(STDERR, 'ERROR: Command find has failed!');
    die(1);
}

$fullpaths_key = null;
$exec_return = null;
exec("find '". escapeshellarg(ENCRYPTED_DIR) ."' -type f -name '*.key'", $fullpaths_key, $exec_return);
if($exec_return !== 0) {
    fwrite(STDERR, 'ERROR: Command find has failed!');
    die(2);
}
sort($fullpaths_key);

$total_index = 0;
foreach($fullpaths_key as $fullpath_key) {
	$symmetric_key = decryptSymmetricKey($fullpath_key, PRIVATE_KEY_FILE);
	$filename = substr($fullpath_key, 0, -4);
	$index = 0;
	foreach($fullpaths_enc as $fullpath_enc) {
		if(strpos($fullpath_enc, $filename) === 0) {
			$decrypted_path = DECRYPTED_DIR . '/' . substr(basename($fullpath_enc), 0, -4);
			decryptFile($fullpath_enc, $decrypted_path, $symmetric_key);
			$index++;
		}
	}
	$total_index += $index;
	echo "Decrypted {$index} files with {$fullpath_key}\n";
}

$total_files_enc = count($fullpaths_enc);
if($total_files_enc !== $total_index) {
	fwrite(STDERR, 'ERROR: Total number of encrypted ({$total_files_enc}) files is different than the number of files decrypted ({$total_index})');
	die(3);
}





function decryptSymmetricKey($encryptedKeyFile, $privateKeyFile) {
    // Load the encrypted symmetric key
    $encryptedKey = file_get_contents($encryptedKeyFile);
    if (!$encryptedKey) {
        die("Unable to read encrypted key.\n");
    }

    // Load the private key
    $privateKey = file_get_contents($privateKeyFile);
    if (!$privateKey) {
        die("Unable to read private key.\n");
    }

    // Decrypt the symmetric key
    if (!openssl_private_decrypt($encryptedKey, $decryptedKey, $privateKey)) {
        die("RSA key decryption failed.\n");
    }

    return $decryptedKey;
}

function decryptFile($inputFile, $outputFile, $key) {
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

    // Read the IV from the beginning of the input file
    $ivLength = openssl_cipher_iv_length('aes-256-ctr');
    $iv = fread($inHandle, $ivLength);
    // Decrypt the file in chunks
    $chunkSize = 8192; // 8KB chunks
    while ($chunk = fread($inHandle, $chunkSize)) {
        $decryptedChunk = openssl_decrypt($chunk, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, $iv);
        if ($decryptedChunk === false) {
            die("Decryption failed.\n");
        }
        fwrite($outHandle, $decryptedChunk);
    }

    fclose($inHandle);
    fclose($outHandle);

    echo "File decrypted successfully: $outputFile\n";
}

?>
