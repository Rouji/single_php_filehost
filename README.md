# Single .php Filehost
Simple PHP script, mainly for sharing random files with people using curl. (and thus in an easily scriptable way)

Puts a file sent via POST into a configured directory with a randomised filename but preserving the original file extension and returns a link to it.  
Actually serving the file to people is left to apache to figure out.

There's also a mechanism for removing files over a certain age, which can be invoked by calling the script with a commandline argument.

# Config
All configuration is done using the global variables at the top of **index.php**. Hopefully they're explained well enough in the short comments besides them.

To accommodate for larger uploads, you'll also need to set the following values in your php.ini:  
upload_max_filesize  
post_max_size  
max_input_time  
max_execution_time  
(The output of index.php will also warn you, if any of those are set too small)

The code responsible for the default info text can be found at the very bottom of index.php, in case you want to reword anything.

## Apache
Pretty straight forward, I use something like this:  

```
<Directory /path/to/webroot/>
    Options +FollowSymLinks -MultiViews -Indexes
    AddDefaultCharset UTF-8
    AllowOverride None

    RewriteEngine On
    RewriteCond "%{ENV:REDIRECT_STATUS}" "^$"
    RewriteRule "^/?$" "index.php" [L,END]
    RewriteRule "^(.+)$" "files/$1" [L,END]
</Directory>

<Directory /path/to/webroot/files>
    Options -ExecCGI
    php_flag engine off
    SetHandler None
    AddType text/plain .php .php5 .html .htm .cpp .c .h .sh
</Directory>
```

## Nginx
```
root /path/to/webroot;
index index.php;

location ~ /(.+)$ {
    root /path/to/webroot/files;
}

location = / {
    include fastcgi_params;
    fastcgi_param HTTP_PROXY "";
    fastcgi_intercept_errors On;
    fastcgi_param SCRIPT_NAME index.php;
    fastcgi_param SCRIPT_FILENAME /path/to/webroot/index.php;
    fastcgi_param QUERY_STRING $query_string;
    fastcgi_pass 127.0.0.1:9000;
}
```

# Purging Old Files
To check for any files that exceed their max age and delete them, you need to call index.php with the argument "purge"  
```bash
php index.php purge
```

To automate this, simply create a cron job:
```
0 0 * * * cd /path/to/the/root; php index.php purge > /dev/null
```
If you specify **$STORE_PATH** using an absolute path, you can omit the **cd**


## Max. File Age
The max age of a file is computed using the following formula:
```
$file_max_age = $MIN_FILEAGE +  
                ($MAX_FILEAGE - $MIN_FILEAGE) *  
                pow(1-($fileSize/$MAX_FILESIZE),$DECAY_EXP);
```
...which is a basic exponential decay curve that favours smaller files, meaning small files are kept longer and really big ones are deleted relatively quickly.  
**$DECAY_EXP** is one of the configurable globals and basically makes the curve more or less exponential-looking. Set to 1 for a completely linear relationship.  

# Related Things
- [ssh2p](https://github.com/Rouji/ssh2p) and [nc2p](https://github.com/Rouji/nc2p) for adding the ability to upload via `ssh` and `netcat`.  
- [Docker container](https://github.com/Rouji/single_php_filehost_docker)

# FAQ
**Q:** Can you add this or that feature?  
**A:** This is mostly just a snapshot of what I'm doing on [x0.at](https://x0.at/). But I'm open to suggestions and PRs, as long as they do something useful that can't be done outside of the script itself (e.g. auth could be done in a .htaccess, malware scanning can be done in the `EXTERNAL_HOOK`, ...) and they don't go against the KISS principle.  

**Q:** Why is the index page so ugly? (And PRs regarding styling)  
**A:** To some degree because of KISS, but also because I'm not trying to make the next super flashy, super popular Megaupload clone. This is more aimed at a minority of nerds with command line fetishes.

**Q:** OMG hosting this without user accounts or logins is so dangerous! Change that now!!1  
**A:** I've been running x0.at for *years* now and like to think I know what I'm doing. I'll maybe consider changing how I run it, should it become a problem. *But* I also don't see that as a concern to be dealt with inside this script. If you want to run your copy of this with logins, use basic auth on top of it or something.  
