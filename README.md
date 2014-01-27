varnish-log-shipper
===================

Uses varnishncsa to dump logs for configured domains, rotating at configurable intervals, logs are remotely uploaded using http put

Requires php 5.5+
To run install deps using composer
./composer.phar install

copy config.php.example to config.php, edit as needed, then run
./shipper ncsa
