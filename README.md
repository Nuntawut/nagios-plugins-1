Check plugins for nagios
========================

face.com rate limit check
-------------------------

Checks your face.com api rate limit and reports average usage and remaining call count.

Options

* key - face.com api key
* secret - face.com api secret
* crit - percentual crit limit

Example

    ./check_face-com-rate.php --key=<yourApiKey> --secret=<yourApiSecret> --crit=5

