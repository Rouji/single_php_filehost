<?php
////////////////////////////////////////////////////////////////////////////////
// global config
////////////////////////////////////////////////////////////////////////////////
$MAX_FILESIZE=512;          //max. filesize in MiB
$MAX_FILEAGE=180;           //max. age of files in days
$MIN_FILEAGE=31;            //min. age of files in days
$DECAY_EXP=2;               //high values penalise larger files

$UPLOAD_TIMEOUT=5*60;       //max. time an upload can take before it times out
$ID_LENGTH=6;               //length of the random file ID
$STORE_PATH="files/";       //directory to store uploaded files in
$DOWNLOAD_URL="%s";         //%s = placeholder for filename
$HTTP_PROTO="https";        //protocol to use in links

$ADMIN_EMAIL="complaintsgo@here.com";  //address for inquiries


////////////////////////////////////////////////////////////////////////////////
// decide what to do, based on POST parameters etc.
////////////////////////////////////////////////////////////////////////////////
if (isset($_FILES["file"]["name"]) &&              //file was uploaded, store it
    isset($_FILES["file"]["tmp_name"]) &&
    is_uploaded_file($_FILES["file"]["tmp_name"]))
{
    $formatted = isset($_GET["formatted"]) || isset($_POST["formatted"]);
    storeFile($_FILES["file"]["name"],
              $_FILES["file"]["tmp_name"],
              $formatted);
}
else if (isset($_GET['sharex']))  //send sharex config
{
    sendShareXConfig();
}
else if (isset($argv[1]) &&       //file was called from cmd, to purge old files
         $argv[1] === 'purge')
{
    purgeFiles();
}
else //nothing special going on, print info text
{
    checkConfig(); //check for any php.ini config problems
    printInfo(); //print info page
}

////////////////////////////////////////////////////////////////////////////////
// generate a random string of characters with given length
////////////////////////////////////////////////////////////////////////////////
function rndStr($len)
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    $maxIdx = strlen($chars) - 1;
    $out = '';
    while ($len--)
    {
        $out .= $chars[mt_rand(0,$maxIdx)];
    }
    return $out;
}


////////////////////////////////////////////////////////////////////////////////
// check php.ini settings and print warnings if anything's not configured 
// properly
////////////////////////////////////////////////////////////////////////////////
function checkConfig()
{
    global $MAX_FILESIZE;
    global $UPLOAD_TIMEOUT;
    warnConfig('upload_max_filesize', "MAX_FILESIZE", $MAX_FILESIZE);
    warnConfig('post_max_size', "MAX_FILESIZE", $MAX_FILESIZE);
    warnConfig('max_input_time', "UPLOAD_TIMEOUT", $UPLOAD_TIMEOUT);
    warnConfig('max_execution_time', "UPLOAD_TIMEOUT", $UPLOAD_TIMEOUT);
}
function warnConfig($iniName, $varName, $varValue)
{
    $iniValue = intval(ini_get($iniName));
    if ($iniValue < $varValue)
        printf("<pre>Warning: php.ini: %s (%s) set lower than %s (%s)\n</pre>",
            $iniName, 
            $iniValue,
            $varName,
            $varValue);
}


////////////////////////////////////////////////////////////////////////////////
// store an uploaded file, given its name and temporary path, (e.g. values 
// straight out of $_FILES)
// files are stored wit a randomised name, but with their original extension
//
// $name: original filename
// $tmpFile: temporary path of uploaded file
// $formatted: set to true to display formatted message instead of bare link
////////////////////////////////////////////////////////////////////////////////
function storeFile($name, $tmpFile, $formatted = false)
{
    global $STORE_PATH;
    global $ID_LENGTH;
    global $HTTP_PROTO;
    global $DOWNLOAD_URL;
    global $MAX_FILESIZE;


    //create folder, if it doesn't exist
    if (!file_exists($STORE_PATH))
    {
        mkdir($STORE_PATH, 0750, true); //TODO: error handling
    }

    //check file size
    if (filesize($tmpFile) > $MAX_FILESIZE * 1024 * 1024)
    {
        header("HTTP/1.0 507 Max File Size Exceeded");
        return;
    }

    $ext = getExtension($name);
    $id = rndStr($ID_LENGTH);
    $basename = $id . '.' . $ext;
    $target_file = $STORE_PATH . $basename;
    $res = move_uploaded_file($tmpFile, $target_file);
    if ($res)
    {
        //print the download link of the file
        $url = sprintf("%s://%s/".$DOWNLOAD_URL, 
                       $HTTP_PROTO,
                       $_SERVER["SERVER_NAME"], 
                       $basename);
        if ($formatted)
        {
            printf("<pre>Access your file here:\n<a href=\"%s\">%s</a></pre>",
                $url,$url);
        }
        else
        {
            printf($url);
        }
    }
    else
    {
        //TODO: proper error handling
        header("HTTP/1.0 520 Unknown Error");
    }
}

//extract extension from a path (does not include the dot)
function getExtension($path)
{
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    //special handling of .tar.* archives
    $ext2 = pathinfo(substr($path,0,-(strlen($ext)+1)), PATHINFO_EXTENSION);
    if ($ext2 === 'tar')
    {
        $ext = $ext2.'.'.$ext;
    }
    //trim extension to max. 7 chars
    $ext = substr($ext,0,7);
    return $ext;
}


////////////////////////////////////////////////////////////////////////////////
// purge all files older than their retention period allows.
////////////////////////////////////////////////////////////////////////////////
function purgeFiles()
{
    global $STORE_PATH;
    global $MAX_FILEAGE;
    global $MAX_FILESIZE;
    global $MIN_FILEAGE;
    global $DECAY_EXP;

    $numDel = 0;    //number of deleted files
    $totalSize = 0; //total size of deleted files

    //for each stored file
    foreach (scandir($STORE_PATH) as $file)
    {
        //skip virtual . and .. files
        if ($file == '.' ||
            $file == '..' ||
            $file == '.htaccess' ||
            $file == '.htpasswd')
        {
            continue;
        }

        $file = $STORE_PATH . $file;

        $fileSize = filesize($file) / (1024*1024); //size in MiB
        $fileAge = (time()-filemtime($file)) / (60*60*24); //age in days

        //keep all files below the min age
        if ($fileAge < $MIN_FILEAGE)
        {
            continue;
        }

        //calculate the maximum age, in days, for this file
        //minage + (maxage-minage) * (1-(size/maxsize))^exp;
        $fileMaxAge = $MIN_FILEAGE + 
                      ($MAX_FILEAGE - $MIN_FILEAGE) *
                      pow(1-($fileSize/$MAX_FILESIZE),$DECAY_EXP);

        //delete if older
        if ($fileAge > $MIN_FILEAGE)
        {
            unlink($file);

            printf("deleted \"%s\", %d MiB, %d days old\n",
                   $file,
                   $fileSize,
                   $fileAge);

            $numDel += 1;
            $totalSize += $fileSize;
        }
    }
    printf("Purge finished. Deleted %d files totalling %d MB\n",
           $numDel,
           $totalSize);
}

////////////////////////////////////////////////////////////////////////////////
// send a ShareX custom uploader config as .json
////////////////////////////////////////////////////////////////////////////////
function sendShareXConfig()
{
    global $HTTP_PROTO;
    $host = $_SERVER["HTTP_HOST"];
    $filename =  $host.".json";
    $content = <<<EOT
{
  "Name": "$host",
  "RequestType": "POST",
  "RequestURL": "$HTTP_PROTO://$host/",
  "FileFormName": "file",
  "ResponseType": "Text"
}
EOT;
    header("Content-type: application/octet-stream");
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header("Content-Length: ".strlen($content));
    print($content);
}


////////////////////////////////////////////////////////////////////////////////
// print a plaintext info page, explaining what this script does and how to
// use it, how to upload, etc.
// essentially the homepage
////////////////////////////////////////////////////////////////////////////////
function printInfo()
{
    global $ADMIN_EMAIL;
    global $HTTP_PROTO; global $MAX_FILEAGE;
    global $MAX_FILESIZE;
    global $MIN_FILEAGE;
    global $DECAY_EXP;

    $url = $HTTP_PROTO."://".$_SERVER["HTTP_HOST"].$_SERVER['REQUEST_URI'];
    $sharexUrl = $url."?sharex";

echo <<<EOT
<pre>
 === How To Upload ===
You can upload files to this site via a simple HTTP POST, e.g. using curl:
curl -F "file=@/path/to/your/file.jpg" $url

On Windows, you can use <a href="https://getsharex.com/">ShareX</a> and import <a href="$sharexUrl">this</a> custom uploader.
On Android, you can use an app called <a href="https://play.google.com/store/apps/details?id=eu.imouto.hupl">Hupl</a>.


Or simply choose a file and click "Upload" below:
(Hint: If you're lucky, your browser may support drag-and-drop onto the file 
selection input.)
</pre>
<form id="frm" action="" method="post" enctype="multipart/form-data">
<input type="file" name="file" id="file" />
<input type="hidden" name="formatted" value="true" />
<input type="submit" value="Upload"/>
</form>
<pre>


 === File Sizes etc. ===
The maximum allowed file size is $MAX_FILESIZE MiB.

Files are kept for a minimum of $MIN_FILEAGE, and a maximum of $MAX_FILEAGE Days.

How long a file is kept, depends on its size. Larger files are deleted earlier 
than small ones. This relation is non-linear and skewed in favour of small 
files.

The exact formula for determining the maximum age for a file is:

MIN_AGE + (MAX_AGE - MIN_AGE) * (1-(FILE_SIZE/MAX_SIZE))^$DECAY_EXP


 === Source ===
The PHP script used to provide this service is open source and available on 
<a href="https://github.com/Rj48/single_php_filehost">GitHub</a>


 === Contact ===
If you want to report abuse of this service, or have any other inquiries, 
please write an email to $ADMIN_EMAIL
</pre>
EOT;
}
?>
