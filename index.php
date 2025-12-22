<?php
class CONFIG
{
    const MAX_FILESIZE = 512; //max. filesize in MiB
    const MAX_FILEAGE = 180; //max. age of files in days
    const MIN_FILEAGE = 31; //min. age of files in days
    const DECAY_EXP = 2; //high values penalise larger files more

    const UPLOAD_TIMEOUT = 5*60; //max. time an upload can take before it times out
    const MIN_ID_LENGTH = 3; //min. length of the random file ID
    const MAX_ID_LENGTH = 24; //max. length of the random file ID, set to MIN_ID_LENGTH to disable
    const STORE_PATH = 'files/'; //directory to store uploaded files in
    const LOG_PATH = null; //path to log uploads + resulting links to
    const DOWNLOAD_PATH = '%s'; //the path part of the download url. %s = placeholder for filename
    const MAX_EXT_LEN = 7; //max. length for file extensions
    const EXTERNAL_HOOK = null; //external program to call for each upload
    const AUTO_FILE_EXT = false; //automatically try to detect file extension for files that have none

    const FORCE_HTTPS = false; //force generated links to be https://

    const ADMIN_EMAIL = 'admin@example.com';  //address for inquiries

    public static function SITE_URL() : string
    {
        $proto = ($_SERVER['HTTPS'] ?? 'off') == 'on' || CONFIG::FORCE_HTTPS ? 'https' : 'http';
        return "$proto://{$_SERVER['HTTP_HOST']}";
    }

    public static function SCRIPT_URL() : string
    {
        return CONFIG::SITE_URL().$_SERVER['REQUEST_URI'];
    }
};


// generate a random string of characters with given length
function rnd_str(int $len) : string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    $chars_len = strlen($chars);
    $random = random_bytes($len);
    $out = '';

    for ($i = 0; $i < $len; ++$i)
    {
        $out .= $chars[ord($random[$i]) % $chars_len];
    }

    return $out;
}

// check php.ini settings and print warnings if anything's not configured properly
function check_config() : void
{
    $warn_config_value = function($ini_name, $var_name, $var_val)
    {
        $ini_val = intval(ini_get($ini_name));
        if ($ini_val < $var_val)
            print("<pre>Warning: php.ini: $ini_name ($ini_val) set lower than $var_name ($var_val)\n</pre>");
    };

    $warn_config_value('upload_max_filesize', 'MAX_FILESIZE', CONFIG::MAX_FILESIZE);
    $warn_config_value('post_max_size', 'MAX_FILESIZE', CONFIG::MAX_FILESIZE);
    $warn_config_value('max_input_time', 'UPLOAD_TIMEOUT', CONFIG::UPLOAD_TIMEOUT);
    $warn_config_value('max_execution_time', 'UPLOAD_TIMEOUT', CONFIG::UPLOAD_TIMEOUT);
}

//extract extension from a path (does not include the dot)
function ext_by_path(string $path) : string
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

function ext_by_finfo(string $path) : string
{
    $finfo = finfo_open(FILEINFO_EXTENSION);
    $finfo_ext = finfo_file($finfo, $path);
    finfo_close($finfo);
    if ($finfo_ext != '???')
    {
        return explode('/', $finfo_ext, 2)[0];
    }
    else
    {
        $finfo = finfo_open();
        $finfo_info = finfo_file($finfo, $path);
        finfo_close($finfo);
        if (strstr($finfo_info, 'text') !== false)
        {
            return 'txt';
        }
    }
    return '';
}

// store an uploaded file, given its name and temporary path (e.g. values straight out of $_FILES)
// files are stored wit a randomised name, but with their original extension
//
// $name: original filename
// $tmpfile: temporary path of uploaded file
// $formatted: set to true to display formatted message instead of bare link
function store_file(string $name, string $tmpfile, bool $formatted = false) : void
{
    //create folder, if it doesn't exist
    if (!file_exists(CONFIG::STORE_PATH))
    {
        mkdir(CONFIG::STORE_PATH, 0750, true); //TODO: error handling
    }

    //check file size
    $size = filesize($tmpfile);
    if ($size > CONFIG::MAX_FILESIZE * 1024 * 1024)
    {
        header('HTTP/1.0 413 Payload Too Large');
        print("Error 413: Max File Size ({CONFIG::MAX_FILESIZE} MiB) Exceeded\n");
        return;
    }
    if ($size == 0)
    {
        header('HTTP/1.0 400 Bad Request');
        print('Error 400: Uploaded file is empty\n');
        return;
    }

    $ext = ext_by_path($name);
    if (empty($ext) && CONFIG::AUTO_FILE_EXT)
    {
        $ext = ext_by_finfo($tmpfile);
    }
    $ext = substr($ext, 0, CONFIG::MAX_EXT_LEN);
    $tries_per_len=3; //try random names a few times before upping the length

    $id_length=CONFIG::MIN_ID_LENGTH;
    if(isset($_POST['id_length']) && ctype_digit($_POST['id_length'])) {
        $id_length = max(CONFIG::MIN_ID_LENGTH, min(CONFIG::MAX_ID_LENGTH, $_POST['id_length']));
    }

    for ($len = $id_length; ; ++$len)
    {
        for ($n=0; $n<=$tries_per_len; ++$n)
        {
            $id = rnd_str($len);
            $basename = $id . (empty($ext) ? '' : '.' . $ext);
            $target_file = CONFIG::STORE_PATH . $basename;

            if (!file_exists($target_file))
                break 2;
        }
    }

    $res = move_uploaded_file($tmpfile, $target_file);
    if (!$res)
    {
        //TODO: proper error handling?
        header('HTTP/1.0 520 Unknown Error');
        return;
    }

    if (CONFIG::EXTERNAL_HOOK !== null)
    {
        putenv('REMOTE_ADDR='.$_SERVER['REMOTE_ADDR']);
        putenv('ORIGINAL_NAME='.$name);
        putenv('STORED_FILE='.$target_file);
        $ret = -1;
        $out = null;
        $last_line = exec(CONFIG::EXTERNAL_HOOK, $out, $ret);
        if ($last_line !== false && $ret !== 0)
        {
            unlink($target_file);
            header('HTTP/1.0 400 Bad Request');
            print("Error: $last_line\n");
            return;
        }
    }

    //print the download link of the file
    $url = sprintf(CONFIG::SITE_URL().'/'.CONFIG::DOWNLOAD_PATH, $basename);

    if ($formatted)
    {
        print("<pre>Access your file here: <a href=\"$url\">$url</a></pre>");
    }
    else
    {
        print("$url\n");
    }

    // log uploader's IP, original filename, etc.
    if (CONFIG::LOG_PATH)
    {
        file_put_contents(
            CONFIG::LOG_PATH,
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

// purge all files older than their retention period allows.
function purge_files() : void
{
    $num_del = 0;    //number of deleted files
    $total_size = 0; //total size of deleted files

    //for each stored file
    foreach (scandir(CONFIG::STORE_PATH) as $file)
    {
        //skip virtual . and .. files
        if ($file === '.' ||
            $file === '..')
        {
            continue;
        }

        $file = CONFIG::STORE_PATH . $file;

        $file_size = filesize($file) / (1024*1024); //size in MiB
        $file_age = (time()-filemtime($file)) / (60*60*24); //age in days

        //keep all files below the min age
        if ($file_age < CONFIG::MIN_FILEAGE)
        {
            continue;
        }

        //calculate the maximum age in days for this file
        $file_max_age = CONFIG::MIN_FILEAGE +
                        (CONFIG::MAX_FILEAGE - CONFIG::MIN_FILEAGE) *
                        pow(1 - ($file_size / CONFIG::MAX_FILESIZE), CONFIG::DECAY_EXP);

        //delete if older
        if ($file_age > $file_max_age)
        {
            unlink($file);

            print("deleted $file, $file_size MiB, $file_age days old\n");
            $num_del += 1;
            $total_size += $file_size;
        }
    }
    print("Deleted $num_del files totalling $total_size MiB\n");
}

function send_text_file(string $filename, string $content) : void
{
    header('Content-type: application/octet-stream');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header('Content-Length: '.strlen($content));
    print($content);
}

// send a ShareX custom uploader config as .json
function send_sharex_config() : void
{
    $name = $_SERVER['SERVER_NAME'];
    $site_url = str_replace("?sharex", "", CONFIG::SCRIPT_URL());
    send_text_file($name.'.sxcu', <<<EOT
{
  "Name": "$name",
  "DestinationType": "ImageUploader, FileUploader",
  "RequestType": "POST",
  "RequestURL": "$site_url",
  "FileFormName": "file",
  "ResponseType": "Text"
}
EOT);
}

// send a Hupl uploader config as .hupl (which is just JSON)
function send_hupl_config() : void
{
    $name = $_SERVER['SERVER_NAME'];
    $site_url = str_replace("?hupl", "", CONFIG::SCRIPT_URL());
    send_text_file($name.'.hupl', <<<EOT
{
  "name": "$name",
  "type": "http",
  "targetUrl": "$site_url",
  "fileParam": "file"
}
EOT);
}

// print a plaintext info page, explaining what this script does and how to
// use it, how to upload, etc.
function print_index() : void
{
    $site_url = CONFIG::SCRIPT_URL();
    $sharex_url = $site_url.'?sharex';
    $hupl_url = $site_url.'?hupl';
    $decay = CONFIG::DECAY_EXP;
    $min_age = CONFIG::MIN_FILEAGE;
    $max_size = CONFIG::MAX_FILESIZE;
    $max_age = CONFIG::MAX_FILEAGE;
    $mail = CONFIG::ADMIN_EMAIL;
    $max_id_length = CONFIG::MAX_ID_LENGTH;

    $length_info = "\nTo use a longer file ID (up to $max_id_length characters), add -F id_length=&lt;number&gt;\n";
    if (CONFIG::MIN_ID_LENGTH == CONFIG::MAX_ID_LENGTH)
    {
        $length_info  = "";
    }

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
curl -F "file=@/path/to/your/file.jpg" $site_url

Or if you want to pipe to curl *and* have a file extension, add a "filename":
echo "hello" | curl -F "file=@-;filename=.txt" $site_url
$length_info
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
The maximum allowed file size is $max_size MiB.

Files are kept for a minimum of $min_age, and a maximum of $max_age Days.

How long a file is kept depends on its size. Larger files are deleted earlier 
than small ones. This relation is non-linear and skewed in favour of small 
files.

The exact formula for determining the maximum age for a file is:

MIN_AGE + (MAX_AGE - MIN_AGE) * (1-(FILE_SIZE/MAX_SIZE))^$decay


 === Source ===
The PHP script used to provide this service is open source and available on 
<a href="https://github.com/Rouji/single_php_filehost">GitHub</a>


 === Contact ===
If you want to report abuse of this service, or have any other inquiries, 
please write an email to $mail
</pre>
</body>
</html>
EOT;
}


// decide what to do, based on POST parameters etc.
if (isset($_FILES['file']['name']) &&
    isset($_FILES['file']['tmp_name']) &&
    is_uploaded_file($_FILES['file']['tmp_name']))
{
    //file was uploaded, store it
    $formatted = isset($_REQUEST['formatted']);
    store_file($_FILES['file']['name'],
              $_FILES['file']['tmp_name'],
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
else if ($argv[1] ?? null === 'purge')
{
    purge_files();
}
else
{
    check_config();
    print_index();
}
