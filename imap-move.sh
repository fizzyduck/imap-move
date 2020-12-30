#!/bin/bash

declare -a users
declare -a passwords
users+=("userAUsername")
passwords+=("userAPassword")
users+=("userBUsername")
passwords+=("userBPassword")

declare -i i
for (( i=0; i< ${#users[@]}; i++ )); do
    password=$(php -r 'echo base64_encode("'${passwords[i]}'");')
    echo $password

    php imap-move.php \
            --fake \
	    --source 'imap-tls://'${users[i]}':'$password'@mail.example1.dev:143/' \
	    --target 'imap-tls://'${users[i]}':'$password'@mail.example2.dev:143/'

done
