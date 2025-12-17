# FritzBox data usage monitor
A simple data usage monitor for FritzBox routers. The value is updated every 5 seconds.

## About
This script uses the "Online Monitor" function on the Internet connection of individual devices, added in FritzOs version 8.

## How to use
In FritzBox router, internet traffic is not automatically recorded for each network device. You must enable this on the Details page of the corresponding device in the “Home Network > Network > Network Connections” menu.

After that you just have to fill in the data in the conf.php file, such as:
- IP address of the router
- access password

You need to put a crontab on the "scan.php" script, so that it runs every 5 minutes.
For example by adding this code with the "contab -e" command:
```
*/5 * * * * cd /var/www/fritzbox && /usr/bin/php scan.php
```
## Version v.0.0.2
Updated scripts for working with FRITZ!OS version 8.21
