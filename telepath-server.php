<?php
$serverPassword = '1234';

if(!isset($_REQUEST['method'])||!isset($_REQUEST['pass'])||!password_verify($serverPassword,$_REQUEST['pass'])) return;

$cwd = getcwd().'/';

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
function rrmdir($dir) { 
	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($files as $fileinfo) {
		$todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
		$path = $fileinfo->getRealPath();
		if($todo($path)) echo "$todo $path\n";
	}
	if(rmdir($dir)) echo "rmdir $dir\n";
}
function parse_size($size){
	$unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
	$size = preg_replace('/[^0-9\.]/', '', $size);
	return $unit?round($size * pow(1024, stripos('bkmgtpezy', $unit[0]))):round($size);
}
function merge_file($merged_file_name,$parts_num){
	$content='';
	for($i=0;$i<$parts_num;$i++){
		$file_size = filesize('splited_'.$i);
		$handle = fopen('splited_'.$i, 'rb');
		$content .= fread($handle, $file_size);
	}
	$handle=fopen($merged_file_name, 'wb');
	fwrite($handle, $content);
}

$filesMap = getFilesMap($cwd,[__FILE__]);

switch($_REQUEST['method']){
	case 'maxsize':
		$max_size = parse_size(ini_get('post_max_size'));
		$upload_max = parse_size(ini_get('upload_max_filesize'));
		if($upload_max > 0 && $upload_max < $max_size) $max_size = $upload_max;
		echo $max_size;
	break;
	case 'read':
		header('Content-type:application/javascript;charset=utf-8');
		echo json_encode($filesMap,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
		return;
	break;
	case 'delete':
		if(!isset($_REQUEST['delete'])) return;
		foreach(json_decode($_REQUEST['delete'],true) as $delete){
			if(file_exists($delete)){
				if(is_dir($delete)){
					rrmdir($delete);
				}
				else{
					if(unlink($delete))
						echo "unlink $delete\n";
				}
			}
		}
	break;
	case 'mkdir':
		if(!isset($_REQUEST['dirs'])) return;
		foreach(json_decode($_REQUEST['dirs'],true) as $dir){
			if(mkdir($dir,0777,true))
				echo "mkdir $dir\n";
		}
	break;
	case 'update':
		$tmpDir = sys_get_temp_dir();
		//$tmpDir = __DIR__;
		$tmp = tempnam($tmpDir,'telepath-zip-server');
		foreach($_FILES as $file){
			move_uploaded_file($file['tmp_name'],$tmp);
			file_put_contents($cwd.'.telepath-tmp-zip-part',"$tmp\n",FILE_APPEND);
			echo "uploaded\n";
		}
	break;
	case 'extract':
		if(!file_exists($cwd.'.telepath-tmp-zip-part')) return;
		$parts = file_get_contents($cwd.'.telepath-tmp-zip-part');
		$parts = explode("\n",$parts);
		foreach($parts as $part){
			file_put_contents($cwd.'.telepath-tmp-zip.zip',file_get_contents($part),FILE_APPEND);
			unlink($part);
		}
		$zip = new ZipArchive();
		$zip->open($cwd.'.telepath-tmp-zip.zip');
		$zip->extractTo($cwd);
		for ($i = 0; $i < $zip->numFiles; $i++) {
			echo 'extracted '.$zip->getNameIndex($i)."\n";
		}
		unlink($cwd.'.telepath-tmp-zip-part');
		unlink($cwd.'.telepath-tmp-zip.zip');
	break;
}