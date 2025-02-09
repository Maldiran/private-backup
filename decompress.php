#!/usr/bin/php
<?php
const DECRYPTED_DIR = 'path/to/folder/with/decrypted/archives';
const OUTPUT_DIR = '/path/to/output/directory/where/the/files/will/be/extracted';


$fullpaths_7z = null;
$exec_return = null;
exec("find '". escapeshellarg(DECRYPTED_DIR) ."' -type f", $fullpaths_7z, $exec_return);
if($exec_return !== 0) {
    fwrite(STDERR, 'ERROR: Command find has failed!');
    die(1);
}
sort($fullpaths_7z);


foreach($fullpaths_7z as $fullpath_7z) {
    if (preg_match('/\.7z$/', $fullpath_7z) || preg_match('/\.7z\.001$/', $fullpath_7z)) {
		$output = null;
		$exec_return = null;
		echo "Started decompression of $fullpath_7z\n";
		exec("7z x -y -o". escapeshellarg(OUTPUT_DIR) . ' ' . escapeshellarg($fullpath_7z), $output, $exec_return);
		if($exec_return !== 0) {
			fwrite(STDERR, 'ERROR: Command find has failed!');
			die(1);
		}
		echo "Completed decompression of $fullpath_7z\n";
	}
}

echo "Script finished\n";
?>
