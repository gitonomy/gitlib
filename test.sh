#!/bin/bash
if [ ! -d test-sandbox ]; then
    git clone --bare https://github.com/gitonomy/gitlib.git test-sandbox
fi

phpunit
exit $?
