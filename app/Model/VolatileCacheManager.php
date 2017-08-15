<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 16/08/2017
 * Time: 01:16:19
 */

namespace App\Model;


use App\Model\Globals\BabbageGlobalModelResult;
use Cache;

class VolatileCacheManager
{


    public static function addKey($key)
    {
        if(!Cache::has("_keys")){
            Cache::forever("_keys", [$key]);
        }
        else{
            $keys = Cache::get("_keys");
            $keys[] = $key;
            Cache::forever("_keys",$keys);
        }

    }

    public static function clear(){
        if(!Cache::has("_keys")){
            Cache::forever("_keys", []);
            return;
        }
        else{
            $keys = Cache::get("_keys");
            Cache::forever("_keys", []);

            foreach ($keys as $key) {
                Cache::forget($key);
            }

        }
    }

    public static function reset(){
        Cache::forget("global");
        self::clear();
        $model = (new BabbageGlobalModelResult())->model;

    }
}