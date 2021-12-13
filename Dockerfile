FROM alpine:latest

RUN apk --update --no-cache \
    add lighttpd \
    php \
    php-common \
    php-cgi \
    php-fileinfo
    #php-dom \
    #php-iconv \
    #php-json \
    #php-gd \
    #php-curl \
    #php-xml \
    #php-simplexml \
    #php-pgsql \
    #php-imap \
    #fcgi \
    #php-pdo \
    #php-pdo_pgsql \
    #php-soap \
    #php-xmlrpc \
    #php-posix \
    #php-gettext \
    #php-ldap \
    #php-ctype \

RUN adduser www-data -G www-data -H -s /bin/false -D

RUN mkdir -p /run/lighttpd/ && \
    chown www-data. /run/lighttpd/
RUN mkdir -p /var/www/

ADD lighttpd.conf /etc/lighttpd/lighttpd.conf
ADD index.php /var/www/index.php
RUN sed -i 's/post_max_size[^;]*$/post_max_size = 512M/' /etc/php7/php.ini
RUN sed -i 's/upload_max_filesize[^;]*$/upload_max_filesize = 512M/' /etc/php7/php.ini
RUN sed -i 's/max_input_time[^;]*$/max_input_time = 300/' /etc/php7/php.ini
RUN sed -i 's/max_execution_time[^;]*$/max_execution_time = 300/' /etc/php7/php.ini

EXPOSE 80

VOLUME /var/www
WORKDIR /var/www

CMD  lighttpd -D -f /etc/lighttpd/lighttpd.conf
