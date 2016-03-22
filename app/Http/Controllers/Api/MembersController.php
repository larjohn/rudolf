<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 22/03/2016
 * Time: 10:44:43
 */

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Model\MembersResult;

class MembersController extends Controller
{
    public function index($name, $dimension){
        return response()->json(new MembersResult($name, $dimension));
        
    }

}