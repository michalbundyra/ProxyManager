set -x
if [ "$TRAVIS_PHP_VERSION" = 'hhvm' ] ; then
    curl -sS https://getcomposer.org/installer > composer-installer.php
    hhvm composer-installer.php
    hhvm -v ResourceLimit.SocketDefaultTimeout=30 -v Http.SlowQueryThreshold=30000 composer.phar update --prefer-source

    if [ "$DEPENDENCIES" = 'low' ] ; then
        hhvm -v ResourceLimit.SocketDefaultTimeout=30 -v Http.SlowQueryThreshold=30000 composer.phar update --prefer-source --prefer-lowest --prefer-stable
    fi
else
    composer self-update
    composer update --prefer-source

    if [ "$DEPENDENCIES" = 'low' ] ; then
        composer update --prefer-source --prefer-lowest --prefer-stable
    fi
fi
