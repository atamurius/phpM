#!/usr/bin/php
<?php

ini_set('zend.assertions',  1);
ini_set('assert.exception', 1);

interface Parser {
    const FAILED = [];
    function __invoke(string $s): array; # FAILED | [x,String]
}

abstract class BaseParser implements Parser {
    use ParserM;
    use ParserRep;
    use ParserOr;
}

# Simple parsers

class ParserF extends BaseParser {
    private $f;

    function __construct(callable $f) {
        $this->f = $f;
    }
    function __invoke(string $s): array {
        return ($this->f)($s);
    }
}

function just($x): Parser {
    return new ParserF(function($s) use ($x) {
        return [ $x, $s ];
    });
}

test(just(42), '123', [42,'123']);

function none(): Parser {
    return new ParserF(function($s) {
        return Parser::FAILED;
    });
}

function splitAt(int $n, string $s): array {
    return [ substr($s, 0, $n), substr($s, $n) ];
}

function take(int $n): Parser {
    return new ParserF(function($s) use ($n) {
        return strlen($s) < $n ? [] : splitAt($n, $s);
    });
}

test(take(2), 'abc', ['ab','c']);
test(take(4), 'abc', Parser::FAILED);

# Combinator`s

trait ParserOr {
    function orElse(Parser $alternative): Parser {
        $parser = $this;
        return new ParserF(function($s) use ($parser, $alternative) {
            $result = $parser($s);
            if ($result === Parser::FAILED) {
                $result = $alternative($s);
            }
            return $result;
        });
    }
}

test(take(10)->orElse(take(2)), '123', ['12','3']);


trait ParserM {

    function flatMap(callable $f): Parser { # f: mixed -> Parser
        $parser = $this;
        return new ParserF(function($s) use ($parser, $f) {
            $result = $parser($s);
            if ($result != Parser::FAILED) {
                list ($x, $rest) = $result;
                $next = $f($x);
                $result = $next($rest);
            }
            return $result;
        });
    }

    function map(callable $f): Parser {
        return $this->flatMap(function($x) use ($f) {
            return just($f($x));
        });
    }   
    
    function filter(callable $predicate): Parser {
        return $this->flatMap(function($x) use ($predicate) {
            return $predicate($x) ? just($x) : none();
        });
    }
}

test(
    take(1)->flatMap(function($x) {
        return take(2)->flatMap(function($y) use ($x) {
            return just("$x~$y");
        });
    }),
    '1234',
    ['1~23','4']
);

# DO notation

class ParserDo extends BaseParser {
    private $generatorSource;
    
    function __construct(Closure $source) { # source: () => Generator of Parser
        $this->generatorSource = $source;
    }

    function __invoke(string $text): array {
        $generator = ($this->generatorSource)();
        while ($generator->valid()) {
            $parser = $generator->current();
            $result = $parser($text);
            if ($result === Parser::FAILED) {
                return Parser::FAILED;
            } else {
                list($value, $text) = $result;
                $generator->send($value);
            }
        }
        $value = $generator->getReturn();
        return is_null($value) ? Parser::FAILED : [ $value, $text ];
    }
}

test(
    new ParserDo(function() {
        $x = yield take(1);
        $y = yield take(2);
        return "$x~$y";
    }),
    '1234',
    ['1~23','4']
);

# Combined parsers

function match(string $pattern): callable {
    return function($c) use ($pattern) {
        return preg_match("/^$pattern$/", $c);
    };
}

function takeWhile(callable $predicate): Parser {
    return new ParserDo(function() use ($predicate) {
        $c = yield take(1)->filter($predicate)->orElse(just(''));
        if ($c !== '') {
            $rest = yield takeWhile($predicate);
            return $c.$rest;
        } else {
            return '';
        }
    });
}

function takeOf(string $pattern): Parser {
    return takeWhile(match($pattern));
}

test(takeOf('[0-9]'), '123abc', ['123','abc'   ]);
test(takeOf('[a-z]'), '123abc', [   '','123abc']);

function literal(string $value): Parser {
    return new ParserDo(function() use ($value) {
        $actual = yield take(strlen($value));
        return ($actual == $value) ? $actual : null;
    });
}

test(literal('test'), 'test1', ['test','1']);
test(literal('test'), 'some1', []);

trait ParserRep {
    function repeated(): Parser {
        $parser = $this;
        return new ParserDo(function() use ($parser) {
            $items = [];
            $NONE = new stdClass;
            while (true) {
                $item = yield $parser->orElse(just($NONE));
                if ($item === $NONE) {
                    break;
                } else {
                    $items[] = $item;
                }
            }
            return $items;
        });
    }
    function separatedBy(Parser $separator): Parser {
        $single = $this;
        $afterSep = $separator->flatMap(function() use ($single) {
            return $single;
        });
        $atLeastOne = new ParserDo(function() use ($single,$afterSep) {
            $first = yield $single;
            $rest = yield $afterSep->repeated();
            return array_merge([$first],$rest);
        });
        return $atLeastOne->orElse(just([]));
    }
}
test(take(2)->repeated(), '123456', [ ['12','34','56'], '' ]);
test(take(2)->separatedBy(literal(',')), '12,34,56', [ ['12','34','56'], '' ]);

# JSON

$jNumber = new ParserDo(function() {
    $number  = yield literal('-')->orElse(literal('+'))->orElse(just(''));
    $number .= yield takeOf('[0-9]');
    if (yield literal('.')->orElse(just(false))) {
        $number .= '.'. yield takeOf('[0-9]');
    }
    if ($number !== '')
        return +$number;
});

test($jNumber,   '42', [   42,'']);
test($jNumber,  '-42', [  -42,'']);
test($jNumber, '3.14', [ 3.14,'']);
test($jNumber,'-3.14', [-3.14,'']);

$NULL = new stdClass;

$jNull = literal('null')->map(function() use ($NULL) { 
    return $NULL; 
});

test($jNull, 'null', [$NULL,'']);

$jBool = literal('true')->orElse(literal('false'))->map(function($value) {
    return ($value === 'true');
});

test($jBool, 'true', [true,'']);
test($jBool, 'false', [false,'']);

$jString = new ParserDo(function() {
    yield literal('"');
    $value = yield takeOf('[^"]');
    yield literal('"');
    return $value;
});

test($jString, '""',       ["",'']);
test($jString, '"abc"',    ["abc",'']);
test($jString, '"abc',     []);
test($jString, 'abc"',     []);

$jList = new ParserDo(function() use (&$jValue) {
    yield literal('[');
    $items = yield $jValue->separatedBy(literal(','));
    yield literal(']');
    return $items;
});

$jObject = new ParserDo(function() use (&$jValue) {
    yield literal('{');
    
    $pair = new ParserDo(function() use (&$jValue) {
        $key = yield takeOf('\\w');
        yield literal(':');
        $value = yield $jValue;
        return [$key,$value];
    });
    
    $items = yield $pair->separatedBy(literal(','));
    yield literal('}');
    
    $result = [];
    foreach ($items as list($key,$value)) {
        $result[$key] = $value;
    }
    return $result;
});

$jValue = $jNull->orElse($jBool)->orElse($jNumber)->orElse($jString)->orElse($jList)->orElse($jObject);

test($jValue, '', []);

test($jList, '[]',     [  [],'']);
test($jList, '[1]',    [ [1],'']);
test($jList, '[1,"test",true,false,null]', [ [1,"test",true,false,$NULL],'']);
test($jList, '[[[1]]]', [ [[[1]]],'']);

test($jObject, '{key:42}', [ ['key' => 42], '']);
test($jObject, '{key:42,test:{test:42}}', [ ['key' => 42, 'test' => ['test' => 42]], '']);

test($jValue,
    '{num:-3.14,str:"test",list:[1,2,3]}',
    [[
        'num' => -3.14,
        'str' => "test",
        'list' => [1,2,3]
    ],
    '']
);

## Test functions

function test(Parser $parser, string $text, array $expected) {
    $actual = $parser($text);
    assert($actual === $expected, print_r(compact('expected','actual'), true));
    echo '.';
}
