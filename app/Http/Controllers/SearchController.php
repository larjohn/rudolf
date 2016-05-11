<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 13/04/2016
 * Time: 00:03:07
 */

namespace App\Http\Controllers;


use App\Http\Controllers\Controller;
use App\Model\SearchResult;

class SearchController extends Controller
{
    public function index(){
        return response()->json((new SearchResult())->packages);

    }


  
}