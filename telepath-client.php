<?php
//config
$siteUrl = 'http://site2.dev/';
$serverFileUrl = 'telepath-server.php';
$serverPassword = '1234';

//init vars
$serverUrl = $siteUrl.$serverFileUrl;
$hashedPassword = password_hash($serverPassword,PASSWORD_DEFAULT);
$cwd = getcwd().'/';

//init env
error_reporting(-1);
ini_set('display_startup_errors',true);
ini_set('display_errors','stdout');


//functions
function getFilesMap($dir,$exclude=[]){
	$rii = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	$files = []; 
	foreach($rii as $file) {
		$path = $file->getPathname();
		$path = substr($path,strlen($dir));
		if(in_array($file->getPathname(),$exclude)||in_array($path,$exclude)) continue;
		$files[$path] = $file->isDir()?:sha1_file($path);
	}
	return $files;	
}
function progressiveRenderEnable(){
	header("Expires: Mon, 26 Jul 1997 05:00:00 GMT" ); 
	header("Last-Modified: " . gmdate("D, d M Y H:i:s" ) . " GMT" );
	header("Pragma: no-cache");
	header("Cache-Control: no-cache");
	header("Expires: -1");
	header("Cache-Control: post-check=0, pre-check=0", false);
	header("Cache-Control: no-store, no-cache, must-revalidate");
	ob_implicit_flush(true);
	@ob_end_flush();
	echo str_repeat(" ",1024);
	echo '<pre>';
}
function curl_custom_postfields($ch, array $assoc = array(), array $files = array()) {
	
    // invalid characters for "name" and "filename"
    static $disallow = array("\0", "\"", "\r", "\n");
   
    // build normal parameters
    foreach ($assoc as $k => $v) {
        $k = str_replace($disallow, "_", $k);
        $body[] = implode("\r\n", array(
            "Content-Disposition: form-data; name=\"{$k}\"",
            "",
            filter_var($v),
        ));
    }
   
    // build file parameters
    foreach ($files as $k => $v) {
        switch (true) {
            case false === $v = realpath(filter_var($v)):
            case !is_file($v):
            case !is_readable($v):
                continue; // or return false, throw new InvalidArgumentException
        }
        $data = file_get_contents($v);
        $x = explode(DIRECTORY_SEPARATOR, $v);
        $v = end($x);
        $k = str_replace($disallow, "_", $k);
        $v = str_replace($disallow, "_", $v);
        $body[] = implode("\r\n", array(
            "Content-Disposition: form-data; name=\"{$k}\"; filename=\"{$v}\"",
            "Content-Type: application/octet-stream",
            "",
            $data,
        ));
    }
   
    // generate safe boundary
    do {
        $boundary = "---------------------" . md5(mt_rand() . microtime());
    } while (preg_grep("/{$boundary}/", $body));
   
    // add boundary for each parameters
    array_walk($body, function (&$part) use ($boundary) {
        $part = "--{$boundary}\r\n{$part}";
    });
   
    // add final boundary
    $body[] = "--{$boundary}--";
    $body[] = "";
   
    // set options
    curl_setopt_array($ch, array(
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => implode("\r\n", $body),
        CURLOPT_HTTPHEADER => array(
            "Expect: 100-continue",
            "Content-Type: multipart/form-data; boundary={$boundary}", // change Content-Type
        ),
    ));
    return $ch;
}
$post = function($assoc=[],$files=[])use($serverUrl,$hashedPassword){
	$assoc['pass'] = $hashedPassword;
	$ch = curl_init($serverUrl);
	$ch = curl_custom_postfields($ch,$assoc,$files);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	echo curl_exec($ch);
	curl_close($ch);
};


//run
progressiveRenderEnable();

$filesMap = getFilesMap($cwd,[__FILE__]);
$filesMapRemote = json_decode(file_get_contents($serverUrl.'?method=read&pass='.$hashedPassword.'&_='.time()),true);
$deleteFiles = [];
$updateFiles = [];
$createDirs = [];
foreach($filesMap as $file=>$hash){
	if(!isset($filesMapRemote[$file])||$filesMapRemote[$file]!==$hash){
		if($hash===true)
			$createDirs[] = $file;
		else
			$updateFiles[] = $file;
	}
}
foreach($filesMapRemote as $file=>$hash){
	if(!isset($filesMap[$file])){
		$deleteFiles[] = $file;
	}
}
if(!empty($deleteFiles))
	$post(['method'=>'delete','delete'=>json_encode($deleteFiles)]);
if(!empty($createDirs))
	$post(['method'=>'mkdir','dirs'=>json_encode($createDirs)]);

if(!empty($updateFiles)){
	$tmpDir = sys_get_temp_dir();
	//$tmpDir = __DIR__;
	$zip = new ZipArchive();
	$zipFile = tempnam($tmpDir,'telepath-zip-client');
	if(!$zip->open($zipFile, ZIPARCHIVE::CREATE)) return;
	foreach($updateFiles as $file){
		$zip->addFile($file);
	}
	$zip->close();
	
	$maxsize = file_get_contents($serverUrl.'?method=maxsize&pass='.$hashedPassword.'&_='.time());
	$size = filesize($zipFile);
	$send = [];
	$maxsize = 4096;
	if($size>$maxsize){
		$j = (int)($size/$maxsize);
		$send = array_fill(0,$j,$maxsize);
		$rest = $size%$maxsize;
		if($rest) $send[] = $rest;
		$handle = fopen($zipFile,'r');
		$c = count($send);
		echo "uploading $c zip parts\n";
		foreach($send as $i=>$s){
			$zipPart = tempnam($tmpDir,'telepath-zip-part');
			file_put_contents($zipPart,fread($handle,$s));
			echo "uploading ".($i+1)." of $c zip parts\n";
			$post(['method'=>'update'],['file'=>$zipPart]);
			unlink($zipPart);
		}
	}
	else{
		$post(['method'=>'update'],['file'=>$zipFile]);
	}
	unlink($zipFile);
	$post(['method'=>'extract']);
}

