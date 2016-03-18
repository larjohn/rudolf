<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 16/03/2016
 * Time: 03:35:45
 */

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Model\BabbageModel;
use App\Model\BabbageModelResult;
use Asparagus\QueryBuilder;
use Cache;
use EasyRdf_Sparql_Result;

class ModelController extends Controller
{
    /**
     * @param $name
     * @return array
     */
    public function index($name){


        return response()->json(new BabbageModelResult($name));
    }

}