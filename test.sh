#!/bin/bash

#put the server in a 5 second loop:
(curl "http://localhost:3000/brick"; echo) &

sleep 0.1

#run php:
echo
echo "should fail:"
php main.php
echo

sleep 5

echo
echo "should succeed:"
php main.php
echo
