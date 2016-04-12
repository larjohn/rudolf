<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 16/03/2016
 * Time: 01:23:21
 */

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;

class StatusController extends Controller
{

    public function index($ver){
        return [
            "status"=>"ok",
            "message" => " Babbage, an OLAP-like, light-weight database analytical engine. ",
            "version" => "0.1.1",
            "api" => "babbage",
            "cubes_index_url" => route("api.cubes")
        ];
    }

}