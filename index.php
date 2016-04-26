<?php
////////////////////////////////////////////////////////////////////////////////
// global config
////////////////////////////////////////////////////////////////////////////////
$MAX_FILESIZE=512;          //max. filesize in MB
$MAX_FILEAGE=180;           //max. age of files in days
$MIN_FILEAGE=31;            //min. age of files in days
$DECAY_EXP=5;               //high values favour smaller files (must be uneven)

$UPLOAD_TIMEOUT=5*60;       //max. time an upload can take before this times out
$ID_LENGTH=6;               //length of the random file ID
$STORE_PATH="files/";       //directory to store uploaded files in
$DOWNLOAD_URL="%s";         //%s = placeholder for filename
$HTTP_PROTO="https";        //protocol to use in links

//TODO: do something with this address,
//but in a way, that doesn't get it spammed by bots
$ADMIN_EMAIL="complaintsgo@here.com";  //address for inquiries


////////////////////////////////////////////////////////////////////////////////
// set php parameters
//
// NOTE: you also have to set "upload_max_filesize" and "post_max_size" manually
// in your php.ini. this is only here to limit things, should the webserver
// config allow even higher values.
//
// //TODO: check the currently set values and throw a warning, if they're too
// low?
////////////////////////////////////////////////////////////////////////////////
ini_set('upload_max_filesize', $MAX_FILESIZE."M");
ini_set('post_max_size', $MAX_FILESIZE."M");
ini_set('max_input_time', $UPLOAD_TIMEOUT);
ini_set('max_execution_time', $UPLOAD_TIMEOUT);


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
else if (isset($argv[1]) &&       //file was called from cmd, to purge old files
         $argv[1] === 'purge')
{
    purgeFiles();
}
else //nothing special going on, print info text
{
    printInfo();
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


    //create folder, if it doesn't exist
    if (!file_exists($STORE_PATH))
    {
        mkdir($STORE_PATH, 0750, true); //TODO: error handling
    }

    $ext = pathinfo($name, PATHINFO_EXTENSION);
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
        printf("An error occurred while uploading file.");
    }
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
            $file == '..')
        {
            continue;
        }

        $file = $STORE_PATH . $file;

        $fileSize = filesize($file) / (1000*1000); //size in MB
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

            printf("deleted \"%s\", %d MB, %d days old\n",
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

    echo <<<EOT
<html>
<head></head>
<body>
<pre>
========== How To Upload ==========
You can upload files to this site via a simple HTTP POST, 
e.g. using curl:
curl -F "file=@/path/to/your/file.jpg" $url

Or by choosing a file and clicking "Upload" below:
</pre>
<form id="frm" action="" method="post" 
enctype="multipart/form-data">
<input type="file" name="file" id="file" />
<input type="hidden" name="formatted" value="true" />
<input type="submit" value="Upload"/>
</form>
<pre>
(Hint: If you're lucky, your browser may support drag-
and-drop onto the file selection input.)


========== File Sizes etc. ==========
The maximum allowed file size is $MAX_FILESIZE MB.

Files are kept for a minimum of $MIN_FILEAGE, and a maximum 
of $MAX_FILEAGE Days.

How long a file is kept, depends on its size. Larger 
files are deleted earlier than small ones. This relation 
is non-linear and skewed in favour of small files.

The exact formula for determining the maximum age for 
a file is:

MIN_AGE + (MAX_AGE - MIN_AGE) * (1-(FILE_SIZE/MAX_SIZE))^$DECAY_EXP
</pre>
</body>
</html>
EOT;
}
?>
