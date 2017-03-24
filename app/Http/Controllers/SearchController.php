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
use Illuminate\Support\Facades\Input;

class SearchController extends Controller
{
    public function index(){
        dd(Input::get("q"));
        return response()->json((new SearchResult(request()->query("q"), intval(request()->query("size", 10000))))->packages);

    }


  
}