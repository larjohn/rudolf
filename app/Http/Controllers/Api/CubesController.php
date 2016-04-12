<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 16/03/2016
 * Time: 01:37:15
 */

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Model\CubesResult;
use Asparagus\QueryBuilder;
use EasyRdf_Sparql_Result;

class CubesController extends Controller
{
    public function index($ver){
       return response()->json(new CubesResult());
    }

}