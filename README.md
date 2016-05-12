# Single .php Filehost
Simple PHP script, mainly for sharing random files with people using curl. (and thus in an easily scriptable way)

It receives files uploaded via HTTP POST, and saves them to a configured directory, with a randomised filename (but preserving the original file extension).  
On successful upload, it returns a link to the uploaded file. Serving the file to people you've shared the link with can then simply be left to apache to figure out.

There's also a mechanism for removing files over a certain age, which can be invoked by calling the script with a commandline argument.

## Config
All configuration is done using the global variables at the top of index.php. Hopefully, they're explained well enough in the short comments besides them.

To accommodate for larger uploads, you'll also need to set the following values in your php.ini:  
upload_max_filesize  
post_max_size  
max_input_time  
max_execution_time  
(The output of index.php will also warn you, if any of those are not set too small)

The code responsible for the default info text can be found at the very bottom of index.php, in case you want to reword anything.


## Purging Old Files
To check for any files, that exceed their max age, and delete them, you need to call index.php with the argument "purge"  
```
php index.php purge
```

To automate this, simply create a cron job:
```
0 0 * * * cd /path/to/the/root; php index.php purge > /dev/null
```
If you specify **$STORE_PATH** using an absolute path, you can omit the **cd**


### Max. File Age
The max age of a file is computed using the following formula:
```
$fileMaxAge = $MIN_FILEAGE +  
              ($MAX_FILEAGE - $MIN_FILEAGE) *  
              pow(1-($fileSize/$MAX_FILESIZE),$DECAY_EXP);
```
...which is a basic exponential decay curve that favours smaller files, meaning small files are kept longer and really big ones are deleted relatively quickly.  
**$DECAY_EXP** is one of the configurable globals and basically makes the curve more or less exponential-looking. Set to 1 for a completely linear relationship.  
**NOTE:** $DECAY_EXP should always be an uneven number
