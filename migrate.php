<?php
declare(strict_types=1);
// CLI only. Copies preview data into an empty MySQL installation, preserving existing records.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/lib.php';
try {
    $db=database();
    if (!$db) throw new RuntimeException('Set mysql configuration in config.php first.');
    $db->exec((string)file_get_contents(__DIR__.'/schema.sql'));
    $count=0;
    foreach(glob(DATA_DIR.'/*.json.php') as $path) {
        $name=basename($path,'.json.php');
        if (store_read($name)!==[]) continue;
        $raw=(string)file_get_contents($path);
        if(!str_starts_with($raw,STORE_GUARD))continue;
        $data=json_decode(substr($raw,strlen(STORE_GUARD)),true);
        if(is_array($data)&&store_write($name,$data))$count++;
    }
    echo "MySQL ready; migrated $count records. Existing MySQL data preserved.\n";
} catch(Throwable $e) { fwrite(STDERR,"Migration failed. Check server configuration and database permissions.\n"); exit(1); }
