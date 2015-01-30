RedIPGeo
=================

IP Geolocation with Redis.

Thanks to [Vladislav Ross](https://github.com/rossvs) for his [ipgeobase.php](https://github.com/rossvs/ipgeobase.php).
Thanks to [IpGeoBase](http://ipgeobase.ru) and RU-Center for their CIDR data.
And, of course, thanks to [Redis](http://redis.io) for their cool stuff.

How to use
-----------

Just clone...
```bash
git clone git://github.com/Yurist-85/RedIPGeo.git
```
... and use!
```php
<?php
  $ip = new RedIPGeo\Locator( new Redis() );
  
  $location = $ip
                ->clear() // if we need to clear Redis DB from CIDR data
                ->load()  // if we need to load CIDR data into Redis DB
                ->check('89.31.113.214');
?>
```
