# Webity REST Api Framework

## Setting up Oauth

Oauth Server Documentation: http://bshaffer.github.io/oauth2-server-php-docs/

To begin, a client needs to be inserted into the client table. The password should be hashed using the simple Joomla method:

    md5($password.$salt):$salt

To get a token, submit a request to the url `[base]/oauth/token` with the following items set:

- grant_type = 'password' (literally the word password)
- username = --username--
- password = --password

Basic auth should be used to set the client credentials as well.
