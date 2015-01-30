<?php
namespace RedIPGeo;

use \Redis,
    \DateTime,
    \DateInterval;

class Locator {

    /** @var Redis  */
    protected $redis;

    /** @var string */
    protected $prefix;

    /** @var int */
    protected $use_index;

    /** @var int */
    protected $current_index = 0;

    /** @var bool */
    protected $_ready = false;

    /** @var bool|DateTime */
    protected $_loaded = false;
    // TODO check _loaded Date to notice about data actuality

    /** @var bool */
    protected $_connected = false;

    public function __construct(Redis $redis, $prefix = 'RedIPGeo:', $use_index = 7, $current_index = 0) {
        $this->redis = $redis;
        $this->prefix = $prefix;
        $this->use_index = $use_index;
        $this->current_index = $current_index;
        $this->isConnected();
        $this->isReady();
    }

    /**
     * Load CIDR data into Redis, so we could check our IPs later.
     *
     * @param string $datafile
     * @return $this
     */
    public function load($datafile = '') {
        if ( !$this->isConnected() || $this->isReady() )
            return $this;

        $datafile = $datafile ?: __DIR__ . '/src/geo_files.tar.gz';

        if( !is_file( $datafile ) ) {
            $src = 'http://ipgeobase.ru/files/db/Main/geo_files.tar.gz';

            if( !is_dir(dirname($datafile)) )
                mkdir( dirname($datafile), 0777, true );

            @exec('wget -P "'.dirname($datafile).'" "'.$src.'"');
        }

        @exec('tar -xf "'.$datafile.'" -C"' . dirname($datafile) . '"');

        if ( !is_file(dirname($datafile).'/cidr_optim.txt') || !is_file(dirname($datafile).'/cities.txt') )
            return $this;

        $cidr = fopen( dirname($datafile).'/cidr_optim.txt', 'r' );
        $cities = fopen( dirname($datafile).'/cities.txt', 'r' );

        $this->redis->select( $this->use_index );

        $prev_range_end = $unique_range = 0;
        while ( !feof($cidr) ) {
            $ip = explode("\t", trim( fgets($cidr) ));
            if ( is_array($ip) && sizeof($ip) > 0 && isset($ip[1]) ) {
                $prev_range_end = $prev_range_end ?: $ip[0]-1; //set once

                if( $prev_range_end < $ip[0]-1 ) {
                    // add missed range with default data
                    $unique_range++;
                    $this->redis->zAdd( $this->prefix . 'cidr', $ip[0]-1, $unique_range.'-XX-0');
                }
                $unique_range++;
                $this->redis->zAdd( $this->prefix . 'cidr', $ip[1], $unique_range . '-' . $ip[3] . '-' . (int)$ip[4] );
                $prev_range_end = $ip[1];
            }
        }

        while ( !feof($cities) ) {
            $city = explode("\t", trim( iconv('cp1251','utf-8',fgets($cities)) ));
            if ( is_array($city) && sizeof($city) > 0 && isset($city[1]) ) {
                $this->redis->hSet( $this->prefix . 'cities:' . $city[0], 'name', $city[1]);
                $this->redis->hSet( $this->prefix . 'cities:' . $city[0], 'reg', $city[2]);
                $this->redis->hSet( $this->prefix . 'cities:' . $city[0], 'dist', $city[3]);
                $this->redis->hSet( $this->prefix . 'cities:' . $city[0], 'lat', $city[4]);
                $this->redis->hSet( $this->prefix . 'cities:' . $city[0], 'lng', $city[5]);
            }
        }

        fclose($cidr);
        fclose($cities);
        unlink($datafile);
        unlink(dirname($datafile).'/cidr_optim.txt');
        unlink(dirname($datafile).'/cities.txt');

        $this->_loaded = new DateTime();
        $this->redis->set( $this->prefix . 'loaded', $this->_loaded->format('Y-m-d H:i:s'));
        $this->_ready = true;

        return $this;
    }

    /**
     * Clear RedIPGeo data from storage.
     * If CIDR data stored in a separate DB, simply flush it.
     * Otherwise, try to delete only prefixed keys, not affecting other data.
     *
     * @return $this
     */
    public function clear() {
        if ( !$this->isConnected() )
            return $this;

        if ( $this->use_index == $this->current_index ) {
            $keys = $this->redis->keys( $this->prefix . '*' );
            foreach ($keys as $key) {
                $this->redis->del($key);
            }
        } else {
            $this->redis->select( $this->use_index );
            $this->redis->flushDB();
        }
        $this->_ready = false;
        $this->_loaded = false;

        return $this;
    }

    /**
     * Check IP
     * That's why we all gathered here tonight.
     * @return array|bool
     */
    public function check($ip) {
        if ( !$this->isReady() )
            return false;

        if ( !$cidr = ip2long($ip) )
            return false;

        $this->redis->select( $this->use_index );
        if ( $cc = $this->redis->zRangeByScore( $this->prefix . 'cidr', sprintf("%u",$cidr),'+inf', [ 'limit' => [0,1]]) ) {
            list( $uid, $country_id, $city_id ) = explode('-', $cc[0]);

            $city = ( isset($city_id) ) ? $this->redis->hGetAll( $this->prefix . 'cities:' . $city_id ) : [];

            return [ 'country' => $country_id, 'city' => $city ];
        } else
            return [];
    }

    /**
     * Are we actually able to talk with Redis?
     * @return bool
     */
    public function isConnected() {
        if ( $this->_connected )
            return true;

        if ( $this->redis instanceof Redis)
            try {
                if ( $this->redis->ping() == '+PONG' )
                    return $this->_connected = true;
            } catch (\RedisException $e) {
                return false;
            }
        return false;
    }

    /**
     * Check if we can do what we want here...
     * @return bool
     */
    public function isReady() {
        if ( !$this->isConnected() )
            return $this->_ready = false;
        if ( $this->_ready )
            return true;

        if ( $this->redis instanceof Redis)
            try {
                $this->redis->select( $this->use_index );
                if ( $status = $this->redis->get( $this->prefix . 'loaded' ) ) {
                    $this->_loaded = new DateTime($status);
                    return $this->_ready = true;
                }
            } catch (\RedisException $e) {
                return $this->_ready = false;
            }
        return $this->_ready = false;
    }

}