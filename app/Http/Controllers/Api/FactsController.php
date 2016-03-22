<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 16/03/2016
 * Time: 04:18:25
 */

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Model\FactsResult;

class FactsController extends Controller
{
    public function index($name){

        $fields = explode(',', request('fields'));
        if(request()->has("order"))
            $orders = explode(',', request('order'));
        else
            $orders = [];
        if(request()->has("cut"))
            $cuts = explode('|', request('cut'));
        else
            $cuts = [];
        return response()->json(new FactsResult($name, intval(request("page")), intval(request("pagesize")), $fields, $orders, $cuts));
    }
}