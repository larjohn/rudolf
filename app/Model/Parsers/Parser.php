<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 20/07/2016
 * Time: 01:19:58
 */

namespace App\Model\Parsers;


use Exception;
use Ferno\Loco\ConcParser;
use Ferno\Loco\EmptyParser;
use Ferno\Loco\Grammar;
use Ferno\Loco\GreedyMultiParser;
use Ferno\Loco\GreedyStarParser;
use Ferno\Loco\LazyAltParser;
use Ferno\Loco\ParseFailureException;
use Ferno\Loco\RegexParser;
use Ferno\Loco\StringParser;
use File;
use Storage;

class Parser
{
    private $grammar;
    private $babbageGrammar;
    public function __construct()
    {



        $this->grammar = new Grammar(
            "<syntax>",
            array(
                "<syntax>" => new ConcParser(
                    array("<space>", "<rules>"),
                    function($space, $rules) {
                        return $rules;
                    }
                ),
                "<rules>" => new GreedyStarParser("<rule>"),
                "<rule>" => new ConcParser(
                    array("<bareword>", "<space>", new StringParser("="), "<space>", "<alt>", new StringParser(";"), "<space>"),
                    function($bareword, $space1, $equals, $space2, $alt, $semicolon, $space3) {
                        return array(
                            "rule-name"  => $bareword,
                            "expression" => $alt
                        );
                    }
                ),
                "<alt>" => new ConcParser(
                    array("<conc>", "<pipeconclist>"),
                    function($conc, $pipeconclist) {
                        array_unshift($pipeconclist, $conc);
                        return new LazyAltParser($pipeconclist);
                    }
                ),
                "<pipeconclist>" => new GreedyStarParser("<pipeconc>"),
                "<pipeconc>" => new ConcParser(
                    array(new StringParser("|"), "<space>", "<conc>"),
                    function($pipe, $space, $conc) {
                        return $conc;
                    }
                ),
                "<conc>" => new ConcParser(
                    array("<term>", "<commatermlist>"),
                    function($term, $commatermlist) {
                        array_unshift($commatermlist, $term);
                        // get array key numbers where multiparsers are located
                        // in reverse order so that our splicing doesn't modify the array
                        $multiparsers = array();
                        foreach($commatermlist as $k => $internal) {
                            if(is_a($internal, "GreedyMultiParser")) {
                                array_unshift($multiparsers, $k);
                            }
                        }
                        // We do something quite advanced here. The inner multiparsers are
                        // spliced out into the list of arguments proper instead of forming an
                        // internal sub-array of their own
                        return new ConcParser(
                            $commatermlist,
                            function() use ($multiparsers) {
                                $args = func_get_args();
                                foreach($multiparsers as $k) {
                                    array_splice($args, $k, 1, $args[$k]);
                                }
                                return $args;
                            }
                        );
                    }
                ),
                "<commatermlist>" => new GreedyStarParser("<commaterm>"),
                "<commaterm>" => new ConcParser(
                    array(new StringParser(","), "<space>", "<term>"),
                    function($comma, $space, $term) {
                        return $term;
                    }
                ),
                "<term>" => new LazyAltParser(
                    array("<bareword>", "<sq>", "<dq>", "<group>", "<repetition>", "<optional>")
                ),
                "<bareword>" => new ConcParser(
                    array(
                        new RegexParser(
                            "#^([a-z][a-z ]*[a-z]|[a-z])#",
                            function($match0) {
                                return $match0;
                            }
                        ),
                        "<space>"
                    ),
                    function($bareword, $space) {
                        return $bareword;
                    }
                ),
                "<sq>" => new ConcParser(
                    array(
                        new RegexParser(
                            "#^'([^']*)'#",
                            function($match0, $match1) {
                                if($match1 === "") {
                                    return new EmptyParser();
                                }
                                return new StringParser($match1);
                            }
                        ),
                        "<space>"
                    ),
                    function($string, $space) {
                        return $string;
                    }
                ),
                "<dq>" => new ConcParser(
                    array(
                        new RegexParser(
                            '#^"([^"]*)"#',
                            function($match0, $match1) {
                                if($match1 === "") {
                                    return new EmptyParser();
                                }
                                return new StringParser($match1);
                            }
                        ),
                        "<space>"
                    ),
                    function($string, $space) {
                        return $string;
                    }
                ),
                "<group>" => new ConcParser(
                    array(
                        new StringParser("("),
                        "<space>",
                        "<alt>",
                        new StringParser(")"),
                        "<space>"
                    ),
                    function($left_paren, $space1, $alt, $right_paren, $space2) {
                        return $alt;
                    }
                ),
                "<repetition>" => new ConcParser(
                    array(
                        new StringParser("{"),
                        "<space>",
                        "<alt>",
                        new StringParser("}"),
                        "<space>"
                    ),
                    function($left_brace, $space1, $alt, $right_brace, $space2) {
                        return new GreedyStarParser($alt);
                    }
                ),
                "<optional>" => new ConcParser(
                    array(
                        new StringParser("["),
                        "<space>",
                        "<alt>",
                        new StringParser("]"),
                        "<space>"
                    ),
                    function($left_bracket, $space1, $alt, $right_bracket, $space2) {
                        return new GreedyMultiParser($alt, 0, 1);
                    }
                ),
                "<space>" => new GreedyStarParser("<whitespace/comment>"),
                "<whitespace/comment>" => new LazyAltParser(
                    array("<whitespace>", "<comment>")
                ),
                "<whitespace>" => new RegexParser("#^[ \t\r\n]+#"),
                "<comment>" => new RegexParser("#^(\(\* [^*]* \*\)|\(\* \*\)|\(\*\*\))#")
            ),
            function($syntax) {
                $parsers = array();
                foreach($syntax as $rule) {
                    if(count($parsers) === 0) {
                        $top = $rule["rule-name"];
                    }
                    $parsers[$rule["rule-name"]] = $rule["expression"];
                }
                if(count($parsers) === 0) {
                    throw new Exception("No rules.");
                }
                return new Grammar($top, $parsers);
            }
        );



        $string = $contents = Storage::get("grammar/parser.ebnf");
        $string="
all = cuts | drilldowns | fields | ordering ;
cuts = cut { \"|\" cut } ;
cut = ref \":\" value ;

drilldowns = dimension { '|' dimension } ;
dimension = ref ;

fields = field { ',' field } ;
field = ref ;

aggregates = aggregate { '|' aggregate } ;
aggregate = ref ;

ordering = order { ',' order } ;
order = ref [ ':' direction ] ;
direction = 'asc' | 'desc' ;

ref = ?/[A-Za-z0-9\._]*[A-Za-z0-9]/? ;

value = date_set | int_set | string_set ;
date_value = ?/[0-9]{4}-[0-9]{2}-[0-9]{2}/? ;
date_set = ';'.{ >date_value } ;
int_value = ?/[0-9]+/? !/[^0-9|;]+/ ;
int_set =  ';'.{ >int_value } ;
string_value = escaped_string | {?/[^|]*/?} ;
string_set = ';'.{ >string_value } ;
escaped_string = ESCAPED_STRING ;
ESCAPED_STRING = '\"'  @:{?/[^\"\\\\]*/?|ESC} '\"' ;
ESC = ?/\\\\['\"\\\\nrtbfv]/? | ?/\\\\u[a-fA-F0-9]{4}/? ;

        ";
//var_dump($string);die;
       /* $string = "
	(* a simple program syntax in EBNF - Wikipedia *)
	program = 'PROGRAM' , white space , identifier , white space ,
						 'BEGIN' , white space ,
						 { assignment , \";\" , white space } ,
						 'END.' ;
	identifier = alphabetic character , { alphabetic character | digit } ;
	number = [ \"-\" ] , digit , { digit } ;
	string = '\"' , { all characters } , '\"' ;
	assignment = identifier , \":=\" , ( number | identifier | string ) ;
	alphabetic character = \"A\" | \"B\" | \"C\" | \"D\" | \"E\" | \"F\" | \"G\"
											 | \"H\" | \"I\" | \"J\" | \"K\" | \"L\" | \"M\" | \"N\"
											 | \"O\" | \"P\" | \"Q\" | \"R\" | \"S\" | \"T\" | \"U\"
											 | \"V\" | \"W\" | \"X\" | \"Y\" | \"Z\" ;
	digit = \"0\" | \"1\" | \"2\" | \"3\" | \"4\" | \"5\" | \"6\" | \"7\" | \"8\" | \"9\" ;
	white space = ( \" \" | \"\n\" ) , { \" \" | \"\n\" } ;
	all characters = \"H\" | \"e\" | \"l\" | \"o\" | \" \" | \"w\" | \"r\" | \"d\" | \"!\" ;
";*/
        $this->babbageGrammar = $this->grammar->parse($string);
        var_dump(true);


    }

    public function parse(string $text){

        if($text!=null){
            try{
                $this->babbageGrammar->parse($text);
                return true;
            }
            catch (Exception $e){
                return false;
            }
        }
        else{
            return [];
        }




    }

}