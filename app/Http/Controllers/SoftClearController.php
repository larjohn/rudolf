<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 06/04/2016
 * Time: 00:43:31
 */

namespace App\Http\Controllers;


use Artisan;

class SoftClearController extends Controller
{
    public function clear($name){
        return   Artisan::call('soft:clear', [
            'name' => $name
        ]);

    }
}