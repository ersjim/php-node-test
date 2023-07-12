#!/bin/bash

#put the server in a 5 second loop:
(curl "http://localhost:3000/brick"; echo) &

sleep 0.1

#run php:
echo
echo "should fail:"
php module.php
echo

sleep 5

echo
echo "should succeed:"
php module.php
echo
