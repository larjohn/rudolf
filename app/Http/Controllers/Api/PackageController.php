<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 07/04/2016
 * Time: 11:11:02
 */

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Model\PackageResult;

class PackageController extends Controller
{

    public function index($ver){
        return response()->json(new PackageResult()) ;
    }
}