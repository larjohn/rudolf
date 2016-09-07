<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 07/09/2016
 * Time: 03:10:23
 */
///from https://github.com/AKSW/SparqlProxyPHP/blob/master/sparql-proxy.php
namespace App\Http\Controllers;


use EasyRdf_Http_Client;
use EasyRdf_Sparql_Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Input;
use Illuminate\Http\Response;

class SparqlProxyController extends Controller
{

    public function index(Request $request, Response $response){

        $allowedParams = array("format", "query", "timeout", "default-graph-uri");






        $endpoint = config("sparql.endpoint");
        $args = $request->all();
        $validArgs = [];
        foreach($args as $key=>$value) {
            if(in_array($key, $allowedParams)) {
                $validArgs[$key] = $value;
            }
        }
        $client = new \EasyRdf_Http_Client(null, ['maxredirects'    => 5,
            'useragent'       => 'EasyRdf_Http_Client',
            'timeout'         => isset($validArgs["timeout"])?$validArgs["timeout"]:150]);

        if($request->isMethod("get")){

            $queryString = http_build_query($validArgs);
            $client->setUri("$endpoint?$queryString");
        }
        else{
                    $client->setUri("$endpoint");
            $client->setRawData(file_get_contents('php://input'));

        }
        foreach ($request->headers as $name=>$header){
            $client->setHeaders($name, $header);

        }

        $sparqlResponse = $client->request($request->getMethod());

        foreach ($sparqlResponse->getHeaders() as $name=>$header) {
            $response->header($name, $header);
        }

        $response->setContent($sparqlResponse->getRawBody());


        return $response;



    }


    function validateProxyUrl($url) {
        $u = parse_url($url);
        $scheme = isset($u["scheme"]) ? $u["scheme"] : "";
        if(strcmp($scheme, "http") != 0) {
            echo "Only http scheme is allowed for proxying";
            die;
        }
        // TODO Check for user, pass, query; rather than just discarding them silently
        $host = $u["host"];
        $port = isset($u["port"]) ? (":" . $u["port"]) : "";
        $path = $u["path"];
        $result = "$scheme://$host$port$path";
        return $result;
    }


}