<?php
/**
 * MG Sao Luu offline recovery helper (no WordPress bootstrap / no database).
 *
 * CLI only. Verifies ZIP entry hashes, or decrypts AES-256-GCM .mgbackup then
 * verifies ZIP hashes. HMAC provenance depends on WordPress salts/custom key
 * and is deliberately NOT claimed by this standalone inspection.
 *
 * Usage:
 *   php recovery-verify.php /private/backup.zip
 *   php recovery-verify.php /private/backup.mgbackup
 *   php recovery-verify.php /private/backup.mgbackup --keep=/private/recovery.zip
 * Recovery Key: stdin / interactive TTY; never a CLI argument.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
function mgsl_recovery_fail($why) { throw new RuntimeException($why); }
function mgsl_recovery_read_exact($fp, $n) {
    if ($n < 1 || $n > 8 * 1024 * 1024 + 32) mgsl_recovery_fail('Invalid read length.');
    $buf = '';
    while (strlen($buf) < $n) {
        $chunk = fread($fp, $n - strlen($buf));
        if ($chunk === false || $chunk === '') mgsl_recovery_fail('Truncated or unreadable encrypted backup.');
        $buf .= $chunk;
    }
    return $buf;
}
function mgsl_recovery_read_key() {
    // Do not accept key via process arguments/environment. Stdin or hidden TTY.
    $tty = function_exists('stream_isatty') && stream_isatty(STDIN);
    $hidden = false;
    if ($tty && PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec')) {
        @shell_exec('stty -echo 2>/dev/null');
        $hidden = true;
    }
    fwrite(STDERR, 'Recovery Key (stdin; process arguments are never accepted): ');
    try { $line = fgets(STDIN); }
    finally {
        if ($hidden) @shell_exec('stty echo 2>/dev/null');
        fwrite(STDERR, "\n");
    }
    if (!is_string($line) || strlen(rtrim($line,"\r\n")) < 12) mgsl_recovery_fail('Missing/short Recovery Key.');
    return rtrim($line, "\r\n");
}
function mgsl_recovery_decrypt($source, $target, $password) {
    $in = fopen($source, 'rb');
    if (!$in) mgsl_recovery_fail('Cannot open source.');
    $out = null;
    try {
        if (mgsl_recovery_read_exact($in,8) !== 'MGSLENC1') mgsl_recovery_fail('Not a MGSLENC1 archive.');
        $header_len = unpack('N', mgsl_recovery_read_exact($in,4))[1];
        if ($header_len < 50 || $header_len > 65536) mgsl_recovery_fail('Invalid encrypted header size.');
        $header_json = mgsl_recovery_read_exact($in,$header_len);
        $h = json_decode($header_json,true);
        if (!is_array($h) || ($h['format'] ?? '') !== 'mg-saoluu-encrypted' ||
            ($h['version'] ?? null) !== 1 || ($h['cipher'] ?? '') !== 'aes-256-gcm' ||
            ($h['kdf'] ?? '') !== 'pbkdf2-sha256') mgsl_recovery_fail('Unsupported encrypted format.');
        $salt = base64_decode((string)($h['salt'] ?? ''),true);
        $rounds = (int)($h['iterations'] ?? 0);
        $chunk_size = (int)($h['chunk_size'] ?? 0);
        $expected_size = (int)($h['original_size'] ?? -1);
        if ($salt===false || strlen($salt)!==32 || $rounds<100000 || $rounds>2000000 ||
            $chunk_size<4096 || $chunk_size>8*1024*1024 || $expected_size<1 || $expected_size>16*1024*1024*1024) {
            mgsl_recovery_fail('Invalid encrypted parameters.');
        }
        $key = hash_pbkdf2('sha256', $password, $salt, $rounds, 32, true);
        $header_hash = hash('sha256', $header_json, true);
        $out = fopen($target,'xb');
        if (!$out) mgsl_recovery_fail('Cannot create private recovery ZIP.');
        @chmod($target,0600);
        $index=0;$total=0;
        while (!feof($in)) {
            $length_raw = fread($in,4);
            if ($length_raw === '') break;
            if (!is_string($length_raw) || strlen($length_raw)!==4) mgsl_recovery_fail('Encrypted data truncated.');
            $len = unpack('N',$length_raw)[1];
            if ($len<1 || $len>$chunk_size+32 || $total+$len>$expected_size) mgsl_recovery_fail('Unexpected encrypted record length.');
            $iv=mgsl_recovery_read_exact($in,12);
            $tag=mgsl_recovery_read_exact($in,16);
            $cipher=mgsl_recovery_read_exact($in,$len);
            $aad='MGSLENC1'.$header_hash.pack('N',$index);
            $plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$aad);
            if ($plain===false || strlen($plain)!==$len) mgsl_recovery_fail('Authentication failed: wrong key or corrupted backup.');
            if (fwrite($out,$plain)!==$len) mgsl_recovery_fail('Failed to write recovered bytes.');
            $total+=$len;$index++;
        }
        if ($total!==$expected_size || $index<1) mgsl_recovery_fail('Recovered size mismatch.');
        fflush($out);fclose($out);$out=null;
    } catch (Throwable $e) {
        if (is_resource($out)) fclose($out);
        @unlink($target);
        throw $e;
    } finally { fclose($in); }
    return true;
}
function mgsl_recovery_check_zip($path) {
    if (!class_exists('ZipArchive')) mgsl_recovery_fail('PHP ZipArchive extension is required.');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CHECKCONS)!==true) mgsl_recovery_fail('Invalid ZIP container.');
    try {
        if ($zip->numFiles>100000) mgsl_recovery_fail('Too many files.');
        $body=$zip->getFromName('mg-backup-manifest.json');
        $manifest=json_decode((string)$body,true);
        if (!is_array($manifest)||($manifest['format']??'')!=='mg-saoluu' || !is_array($manifest['hashes']??null)) mgsl_recovery_fail('Missing/invalid backup manifest.');
        $expected=$manifest['hashes'];$seen=[];$total=0;
        for($i=0;$i<$zip->numFiles;$i++) {
            $stat=$zip->statIndex($i);if(!$stat)mgsl_recovery_fail('Unreadable ZIP entry.');
            $name=str_replace('\\','/',(string)$stat['name']);
            if ($name===''||$name[0]==='/'||preg_match('/^[A-Za-z]:\//',$name)||in_array('..',explode('/',$name),true))mgsl_recovery_fail('Unsafe ZIP path.');
            $os=0;$attr=0;
            if ($zip->getExternalAttributesIndex($i,$os,$attr) && (($attr>>16)&0xf000)===0xa000)mgsl_recovery_fail('ZIP symlink blocked.');
            $size=(int)$stat['size'];$compressed=max(1,(int)$stat['comp_size']);$total+=$size;
            if($total>8*1024*1024*1024 || ($size>10*1024*1024 && $size/$compressed>250))mgsl_recovery_fail('ZIP expansion limit.');
            if(substr($name,-1)==='/')continue;
            if(isset($seen[$name]))mgsl_recovery_fail('Duplicate ZIP entry.');
            if($name==='mg-backup-manifest.json')continue;
            if(!isset($expected[$name]))mgsl_recovery_fail('Unexpected ZIP entry.');
            $stream=$zip->getStream($name);if(!$stream)mgsl_recovery_fail('Cannot inspect ZIP entry.');
            $ctx=hash_init('sha256');
            while(!feof($stream)){$part=fread($stream,1024*1024);if($part===false){fclose($stream);mgsl_recovery_fail('Cannot read ZIP entry.');}hash_update($ctx,$part);}
            fclose($stream);
            if(!hash_equals((string)$expected[$name],hash_final($ctx)))mgsl_recovery_fail('ZIP content checksum mismatch.');
            $seen[$name]=true;
        }
        if(!isset($seen['database_backup.sql']) || count($seen)!==count($expected))mgsl_recovery_fail('Missing SQL or declared entries.');
        return ['files'=>count($seen),'backup_type'=>$manifest['backup_type']??'unknown','source_host'=>$manifest['source_host']??'unknown'];
    } finally { $zip->close(); }
}
function mgsl_recovery_main($argv) {
    if (!in_array(count($argv),[2,3],true)) mgsl_recovery_fail('Usage: php recovery-verify.php /private/backup.[mgbackup|zip] [--keep=/private/recovered.zip]');
    $source=realpath($argv[1]);
    if (!$source||!is_file($source)||!is_readable($source)||is_link($argv[1]))mgsl_recovery_fail('Cannot read a regular source file.');
    $keep=null;
    if(isset($argv[2])) {
        if(strncmp($argv[2],'--keep=',7)!==0)mgsl_recovery_fail('Unknown option.');
        $keep=substr($argv[2],7);
        if(substr($keep, -4) !== '.zip' || file_exists($keep)||is_link($keep))mgsl_recovery_fail('Output must be an unused .zip path.');
        $parent=realpath(dirname($keep));
        if(!$parent||!is_dir($parent)||!is_writable($parent)||($parent==='/'||$parent===realpath(getcwd())))mgsl_recovery_fail('Use an existing private output directory outside the webroot.');
        $perms=fileperms($parent)&0777;
        if(($perms&0077)!==0)mgsl_recovery_fail('Output directory must have 0700-style private permissions.');
        $keep=$parent.'/'.basename($keep);
    }
    $encrypted = file_get_contents($source,false,null,0,8)==='MGSLENC1';
    if($keep!==null && !$encrypted) mgsl_recovery_fail('--keep is for decrypted .mgbackup only.');
    $temp=null;
    try {
        if($encrypted) {
            $dir=$keep ? dirname($keep) : sys_get_temp_dir();
            $temp=tempnam($dir,'mgsl-recovery-');
            if(!$temp)mgsl_recovery_fail('Cannot create recovery temp file.');
            @chmod($temp,0600);
            @unlink($temp); // decrypt creates new file exclusively with fopen xb.
            mgsl_recovery_decrypt($source,$temp,mgsl_recovery_read_key());
        }
        $result=mgsl_recovery_check_zip($temp ?: $source);
        echo 'ZIP_HASHES_OK | entries='. $result['files'].' | backup_type='.$result['backup_type'].' | source='.$result['source_host']."\n";
        echo "SIGNATURE_NOT_VERIFIED | RESTORE_NOT_TESTED (requires original WP signing secret and independent WP/DB restore)\n";
        if($keep!==null){if(!@rename($temp,$keep))mgsl_recovery_fail('Failed to keep recovered ZIP.');$temp=null;echo 'PRIVATE_RECOVERED_ZIP: '.$keep."\n";}
    } finally {if($temp && file_exists($temp))@unlink($temp);}
}
// Defining this constant allows isolated tests to exercise decryption without CLI input.
if (!defined('MGSL_RECOVERY_LIB_ONLY')) {
    try { mgsl_recovery_main($argv); }
    catch(Throwable $e) { fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1); }
}
