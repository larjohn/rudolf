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
    public function index($ver,$name, $dimension){
        if(request()->has("order"))
            $orders = explode(',', request('order'));
        else
            $orders = [];

        return response()->json(new MembersResult($name, $dimension, intval(request("page",0)), intval(request("pagesize",100)),$orders));
        
    }

}