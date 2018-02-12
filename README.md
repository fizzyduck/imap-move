# IMAP Move

This will move messages from one IMAP system to another

Usage:
        php ./imap-move.php \
            --source <URI> \
            --target <URI> \
            [ --wipe --fake --once ]

        --fake to just list what would be copied
        --wipe to remove messages after they are copied
        --once to only copy one message then exit

        URI = (imap-ssl | imap-tls)://user:password@imap.example.com:993/[ folder ]
              (file: | file://<FULLPATH>)filename.db3

Note that password is base64 encoded


## Shell Wrapper

Included is a shell wrapper to make life a bit easier, see `imap-move.sh`
