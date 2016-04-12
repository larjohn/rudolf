<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 06/04/2016
 * Time: 00:43:31
 */

namespace App\Http\Controllers;


class PermitController extends Controller
{
    public function lib(){
        return redirect("lib.js");
    }
}