<?php
$MAX_FILESIZE=512;         //max. filesize in MiB
$MAX_FILEAGE=180;           //max. age of files in days
$MIN_FILEAGE=31;            //min. age of files in days
$DECAY_EXP=2;               //high values penalise larger files more

$UPLOAD_TIMEOUT=5*60;       //max. time an upload can take before it times out
$ID_LENGTH=3;               //length of the random file ID
$STORE_PATH="files/";       //directory to store uploaded files in
$LOG_PATH=null;             //path to log uploads + resulting links to
$DOWNLOAD_PATH="%s";        //the path part of the download url. %s = placeholder for filename
$HTTP_PROTO="https";        //protocol to use in links
$MAX_EXT_LEN=7;             //max. length for file extensions
$EXTERNAL_HOOK=null;
$AUTO_FILE_EXT=false;

$ADMIN_EMAIL="admin@example.com";  //address for inquiries


// generate a random string of characters with given length
function rnd_str($len)
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    $max_idx = strlen($chars) - 1;
    $out = '';
    while ($len--)
    {
        $out .= $chars[mt_rand(0,$max_idx)];
    }
    return $out;
}

// check php.ini settings and print warnings if anything's not configured properly
function check_config()
{
    global $MAX_FILESIZE;
    global $UPLOAD_TIMEOUT;
    warn_config_value('upload_max_filesize', "MAX_FILESIZE", $MAX_FILESIZE);
    warn_config_value('post_max_size', "MAX_FILESIZE", $MAX_FILESIZE);
    warn_config_value('max_input_time', "UPLOAD_TIMEOUT", $UPLOAD_TIMEOUT);
    warn_config_value('max_execution_time', "UPLOAD_TIMEOUT", $UPLOAD_TIMEOUT);
}

function warn_config_value($ini_name, $var_name, $var_val)
{
    $ini_val = intval(ini_get($ini_name));
    if ($ini_val < $var_val)
        printf("<pre>Warning: php.ini: %s (%s) set lower than %s (%s)\n</pre>",
            $ini_name,
            $ini_val,
            $var_name,
            $var_val);
}

//extract extension from a path (does not include the dot)
function ext_by_path($path)
{
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    //special handling of .tar.* archives
    $ext2 = pathinfo(substr($path,0,-(strlen($ext)+1)), PATHINFO_EXTENSION);
    if ($ext2 === 'tar')
    {
        $ext = $ext2.'.'.$ext;
    }
    return $ext;
}

function ext_by_finfo($path)
{
    $finfo = finfo_open(FILEINFO_EXTENSION);
    $finfo_ext = finfo_file($finfo, $path);
    finfo_close($finfo);
    if ($finfo_ext != "???")
    {
        return explode("/", $finfo_ext, 2)[0];
    }
    else
    {
        $finfo = finfo_open();
        $finfo_info = finfo_file($finfo, $path);
        finfo_close($finfo);
        if (strstr($finfo_info, "text") !== false)
        {
            return "txt";
        }
    }
    return "";
}

// store an uploaded file, given its name and temporary path (e.g. values straight out of $_FILES)
// files are stored wit a randomised name, but with their original extension
//
// $name: original filename
// $tmpfile: temporary path of uploaded file
// $formatted: set to true to display formatted message instead of bare link
function store_file($name, $tmpfile, $formatted = false)
{
    global $STORE_PATH;
    global $ID_LENGTH;
    global $HTTP_PROTO;
    global $DOWNLOAD_PATH;
    global $MAX_FILESIZE;
    global $EXTERNAL_HOOK;
    global $LOG_PATH;
    global $MAX_EXT_LEN;
    global $AUTO_FILE_EXT;

    //create folder, if it doesn't exist
    if (!file_exists($STORE_PATH))
    {
        mkdir($STORE_PATH, 0750, true); //TODO: error handling
    }

    //check file size
    $size = filesize($tmpfile);
    if ($size > $MAX_FILESIZE * 1024 * 1024)
    {
        header("HTTP/1.0 413 Payload Too Large");
        printf("Error 413: Max File Size (%d MiB) Exceeded\n", $MAX_FILESIZE);
        return;
    }
    if ($size == 0)
    {
        header("HTTP/1.0 400 Bad Request");
        printf("Error 400: Uploaded file is empty\n", $MAX_FILESIZE);
        return;
    }

    $ext = ext_by_path($name);
    if (empty($ext) && $AUTO_FILE_EXT)
    {
        $ext = ext_by_finfo($tmpfile);
    }
    $ext = substr($ext, 0, $MAX_EXT_LEN);
    $tries_per_len=3; //try random names a few times before upping the length
    for ($len = $ID_LENGTH; ; ++$len)
    {
        for ($n=0; $n<=$tries_per_len; ++$n)
        {
            $id = rnd_str($len);
            $basename = $id . (empty($ext) ? '' : '.' . $ext);
            $target_file = $STORE_PATH . $basename;

            if (!file_exists($target_file))
                break 2;
        }
    }

    $res = move_uploaded_file($tmpfile, $target_file);
    if ($res)
    {
        if ($EXTERNAL_HOOK !== null)
        {
            putenv("REMOTE_ADDR=".$_SERVER['REMOTE_ADDR']);
            putenv("ORIGINAL_NAME=".$name);
            putenv("STORED_FILE=".$target_file);
            $ret = -1;
            $out = exec($EXTERNAL_HOOK, $_ = null, $ret);
            if ($out !== false && $ret !== 0)
            {
                unlink($target_file);
                header("HTTP/1.0 400 Bad Request");
                print("Error: ".$out."\n");
                return;
            }
        }

        //print the download link of the file
        $url = sprintf('%s://%s/'.$DOWNLOAD_PATH,
                       $HTTP_PROTO,
                       $_SERVER["SERVER_NAME"], 
                       $basename);
        if ($formatted)
        {
            printf('<pre>Access your file here: <a href="%s">%s</a></pre>', $url, $url);
        }
        else
        {
            printf($url."\n");
        }

        // log uploader's IP, original filename, etc.
        if ($LOG_PATH)
        {
            file_put_contents(
                $LOG_PATH,
                implode("\t", array(
                    date('c'),
                    $_SERVER['REMOTE_ADDR'],
                    filesize($tmpfile),
                    escapeshellarg($name),
                    $basename
                )) . "\n",
                FILE_APPEND
            );
        }
    }
    else
    {
        //TODO: proper error handling?
        header("HTTP/1.0 520 Unknown Error");
    }
}

// purge all files older than their retention period allows.
function purge_files()
{
    global $STORE_PATH;
    global $MAX_FILEAGE;
    global $MAX_FILESIZE;
    global $MIN_FILEAGE;
    global $DECAY_EXP;

    $num_del = 0;    //number of deleted files
    $total_size = 0; //total size of deleted files

    //for each stored file
    foreach (scandir($STORE_PATH) as $file)
    {
        //skip virtual . and .. files
        if ($file === '.' ||
            $file === '..')
        {
            continue;
        }

        $file = $STORE_PATH . $file;

        $file_size = filesize($file) / (1024*1024); //size in MiB
        $file_age = (time()-filemtime($file)) / (60*60*24); //age in days

        //keep all files below the min age
        if ($file_age < $MIN_FILEAGE)
        {
            continue;
        }

        //calculate the maximum age in days for this file
        $file_max_age = $MIN_FILEAGE +
                      ($MAX_FILEAGE - $MIN_FILEAGE) *
                      pow(1-($file_size/$MAX_FILESIZE),$DECAY_EXP);

        //delete if older
        if ($file_age > $file_max_age)
        {
            unlink($file);

            printf("deleted \"%s\", %d MiB, %d days old\n", $file, $file_size, $file_age);
            $num_del += 1;
            $total_size += $file_size;
        }
    }
    printf("Deleted %d files totalling %d MiB\n", $num_del, $total_size);
}

// send a ShareX custom uploader config as .json
function send_sharex_config()
{
    global $HTTP_PROTO;
    $host = $_SERVER["HTTP_HOST"];
    $filename =  $host.".sxcu";
    $content = <<<EOT
{
  "Name": "$host",
  "DestinationType": "ImageUploader, FileUploader",
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

// send a Hupl uploader config as .hupl (which is just JSON)
function send_hupl_config()
{
    global $HTTP_PROTO;
    $host = $_SERVER["HTTP_HOST"];
    $filename =  $host.".hupl";
    $content = <<<EOT
{
  "name": "$host",
  "type": "http",
  "targetUrl": "$HTTP_PROTO://$host/",
  "fileParam": "file"
}
EOT;
    header("Content-type: application/octet-stream");
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header("Content-Length: ".strlen($content));
    print($content);
}

// print a plaintext info page, explaining what this script does and how to
// use it, how to upload, etc.
function print_index()
{
    global $ADMIN_EMAIL;
    global $HTTP_PROTO;
    global $MAX_FILEAGE;
    global $MAX_FILESIZE;
    global $MIN_FILEAGE;
    global $DECAY_EXP;

    $url = $HTTP_PROTO."://".$_SERVER["HTTP_HOST"].$_SERVER['REQUEST_URI'];
    $sharex_url = $url."?sharex";
    $hupl_url = $url."?hupl";

echo <<<EOT
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Filehost</title>
    <meta name="description" content="Minimalistic service for sharing temporary files." />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
</head>
<body>
<pre>
 === How To Upload ===
You can upload files to this site via a simple HTTP POST, e.g. using curl:
curl -F "file=@/path/to/your/file.jpg" $url

Or if you want to pipe to curl *and* have a file extension, add a "filename":
echo "hello" | curl -F "file=@-;filename=.txt" $url

On Windows, you can use <a href="https://getsharex.com/">ShareX</a> and import <a href="$sharex_url">this</a> custom uploader.
On Android, you can use an app called <a href="https://github.com/Rouji/Hupl">Hupl</a> with <a href="$hupl_url">this</a> uploader.


Or simply choose a file and click "Upload" below:
(Hint: If you're lucky, your browser may support drag-and-drop onto the file 
selection input.)
</pre>
<form id="frm" method="post" enctype="multipart/form-data">
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
<a href="https://github.com/Rouji/single_php_filehost">GitHub</a>


 === Contact ===
If you want to report abuse of this service, or have any other inquiries, 
please write an email to $ADMIN_EMAIL
</pre>
</body>
</html>
EOT;
}


// decide what to do, based on POST parameters etc.
if (isset($_FILES["file"]["name"]) &&
    isset($_FILES["file"]["tmp_name"]) &&
    is_uploaded_file($_FILES["file"]["tmp_name"]))
{
    //file was uploaded, store it
    $formatted = isset($_GET["formatted"]) || isset($_POST["formatted"]);
    store_file($_FILES["file"]["name"],
              $_FILES["file"]["tmp_name"],
              $formatted);
}
else if (isset($_GET['sharex']))
{
    send_sharex_config();
}
else if (isset($_GET['hupl']))
{
    send_hupl_config();
}
else if (isset($argv[1]) && $argv[1] === 'purge')
{
    purge_files();
}
else
{
    check_config();
    print_index();
}
