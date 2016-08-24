<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 22/03/2016
 * Time: 18:18:15
 */

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Http\Requests\Request;
use App\Model\AggregateResult;
use App\Model\Globals\GlobalAggregateResult;
use Illuminate\Support\Facades\Input;

class AggregatesController extends Controller
{

    public function index($ver, $name){
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
        return response()->json(new AggregateResult($name, intval(request("page")), intval(request("pagesize",10000)), $aggregates, $drilldown, $orders, $cuts));
    }
  public function global($ver){

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
        return response()->json(new GlobalAggregateResult(intval(request("page")), intval(request("pagesize",10000)), $aggregates, $drilldown, $orders, $cuts));
    }

}