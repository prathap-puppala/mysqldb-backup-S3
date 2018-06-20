<?php

class CreateBackup{
function CreateBackup($host,$user,$pass,$bakdir){
define('DB_HOSTNAME',$host);
define('DB_USERNAME',$user);
define('DB_PASSWORD',$pass);
define('BACK_DIRECTORY',$bakdir);
if(!is_dir(BACK_DIRECTORY)){mkdir(BACK_DIRECTORY);}
}

function create_backup($dbname){
$filename = $dbname.'-backup-'.time().'.sql.bz2';
$cmd = 'mysqldump -h ' . DB_HOSTNAME . ' -u ' . DB_USERNAME . ' -p ' . DB_PASSWORD . ' ' . $dbname . ' | bzip2 > ' . BACK_DIRECTORY . $filename;
if(exec($cmd)==0){return $filename;}else{return false;}
}
}

?>
