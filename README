SOGoSync
========

Description
-----------
sogosync is z-push (svn upstream) with caldav and cardav backend.

Features
--------
* Add ActiveSync caldav support with multiple calendar using a caldav server
* Add ActiveSync carddav support with multiple addressbook using a carddav server

Requirement
-----------
* A working caldav/carddav server (e.g. SOGo)
- Did not test but should work with any caldav/cardav groupware, feedback welcome.
* An ActiveSync compatible mobile device
	* [Comparison of Exchange ActiveSync clients](http://en.wikipedia.org/wiki/Comparison_of_Exchange_ActiveSync_clients)
* PHP5 with the following library for a Debian system:

    $ apt-get install php5-curl php5-ldap php5-imap php5-mail libawl-php

Thanks
------
SOGoSync is possible thanks to the following projects:
* [Open Groupware](http://www.sogo.nu/)
* [ActiveSync implementation](http://z-push.sourceforge.net/soswp)
* [CardDAV-PHP](https://github.com/graviox/CardDAV-PHP)

See also
------
* Cardav and Caldav RFC
- http://tools.ietf.org/html/rfc6350
- http://tools.ietf.org/html/rfc2425
- http://tools.ietf.org/html/rfc4791
- http://tools.ietf.org/html/rfc2426
* ActiveSync Contact and Calendar Protocol Specification
- http://msdn.microsoft.com/en-us/library/cc425499%28EXCHG.80%29.aspx
	- http://msdn.microsoft.com/en-us/library/dd299451(v=exchg.80).aspx
	- http://msdn.microsoft.com/en-us/library/dd299440(v=exchg.80).aspx
	- http://msdn.microsoft.com/en-us/library/cc463911(v=exchg.80).aspx

Library used
------------
* [CardDAV-Client](https://github.com/graviox/CardDAV-PHP/)
	* Thanks to Christian Putzke for updating is library
* [s-push](https://github.com/dekkers/s-push)
	* Thanks to Jeroen Dekkers for the original caldav support
* [vCard-parser](https://github.com/nuovo/vCard-parser/)

Installation
------------
$ cd /var/www
$ git clone git://github.com/xbgmsharp/sogosync.git
$ cd sogosync

Read z-push install instruction into INSTALL file or [Configure Z-Push (Remote ActiveSync for Mobile Devices)](http://doc.zarafa.com/7.0/Administrator_Manual/en-US/html/_zpush.html)

Configuration
-------------
File 'config.php' is the original file from z-push svn repository:

    $ cp config.php config.php.org
    $ cp config.inc.php config.php

### Edit config.php
 * Set TimeZone
 * Configure the BackendIMAP settings section
 * Configure the BackendCARDDAV settings section
 * Configure the BackendCALDAV settings section

### File 'backend/combined/config.inc.php' is the original file from z-push svn repository:

    $ cp backend/combined/config.php backend/combined/config.php.org
    $ cp backend/combined/config.inc.php backend/combined/config.php

Nothing more to edit.

The configuration is pre-configure to work with the [SOGo Online Demo](http://www.sogo.nu/english/tour/online_demo.html)

Edit 'backend/searchldap/config.php' to support GAL search into the company LDAP tree.

Test
----
Using a browser, you should get:
Login to https://sogo.mydomain.com/Microsoft-Server-ActiveSync
You need to see:
"""
Z-Push - Open Source ActiveSync
Version SVN checkout
GET not supported
"""
If so, congratulations!

Update
------
To update to the latest version:

    $ cd /var/www/sogosync
    $ git pull

Contributing
------------

1. Fork it.
2. Create a branch (`git checkout -b my_markup`)
3. Commit your changes (`git commit -am "Added Snarkdown"`)
4. Push to the branch (`git push origin my_markup`)
5. Create an [Issue][1] with a link to your branch

[1]: https://github.com/xbgmsharp/sogosync/issues
