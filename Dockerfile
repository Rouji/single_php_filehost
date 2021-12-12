FROM alpine:latest

RUN apk --update \
    add lighttpd && \
    rm -rf /var/cache/apk/*

ADD lighttpd.conf /etc/lighttpd/lighttpd.conf
RUN adduser www-data -G www-data -H -s /bin/false -D

RUN apk --update add \
    php \
    php-common \
    php-cgi \
    php-dom && \
    rm -rf /var/cache/apk/*
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

RUN mkdir -p /run/lighttpd/ && \
    chown www-data. /run/lighttpd/

ADD lighttpd.conf /etc/lighttpd/lighttpd.conf
ADD index.php /var/www/index.php
ADD php.ini /etc/php7/php.ini

EXPOSE 80

VOLUME /var/www
WORKDIR /var/www

CMD  lighttpd -D -f /etc/lighttpd/lighttpd.conf
