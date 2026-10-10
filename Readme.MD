# PHP Web Debugger
## Project goal
The project aims to be able to debug PHP code on the same instance with the Apache web server.

## Setup
- Install Apache2
- Install proxy modules
- Install xdebug
- Install minimum php7 version
- Configure Apache like in [example.conf](example.conf)
- Install [service](php-debugpanel.service) on your server

## Using
- Locate to [SCHEME]://[YOUR_ADDRESS]/dbg, and it should display your panel 
- Right-click on the end of line in your code, select "Copy -> Copy Reference" option 
and paste it in first input on the debug panel page
