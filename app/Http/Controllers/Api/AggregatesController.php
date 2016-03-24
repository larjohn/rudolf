<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 22/03/2016
 * Time: 18:18:15
 */

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Model\AggregateResult;

class AggregatesController extends Controller
{

    public function index($name){
        
        $aggregates = explode("|",request("aggregates"));
        $drilldown = explode("|", request("drilldown"));
        if(request()->has("order"))
            $orders = explode(',', request('order'));
        else
            $orders = [];

        if(request()->has("cut"))
            $cuts = explode('|', request('cut'));
        else
            $cuts = [];
        return response()->json(new AggregateResult($name, intval(request("page")), intval(request("pagesize")), $aggregates, $drilldown, $orders, $cuts));
    }

}