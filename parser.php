#!/usr/bin/php
<?php

require_once 'test.php';

class Parser {
    const FAILED = [];
    # function(string): FAILED | [x,string]
    private $parse; 
    function __construct(callable $parse) {
        $this->parse = $parse;
    }
    function __invoke(string $s): array {
        return ($this->parse)($s);
    }
    use ParserM;
    use ParserRep;
    use ParserOr;
}

# Simple parsers

function parser($f, $scope = null) { 
    return new Parser($f->bindTo($scope)); 
}

function just($x): Parser {
    return parser(function($s) use ($x) {
        return [ $x, $s ];
    });
}

test(just(42), '123', [42,'123']);

function none(): Parser {
    return parser(function($s) {
        return Parser::FAILED;
    });
}

function take(int $n): Parser {
    return parser(function($s) use ($n) {
        return strlen($s) < $n ? Parser::FAILED : [ substr($s, 0, $n), substr($s, $n) ];
    });
}

test(take(2), 'abc', ['ab','c']);
test(take(4), 'abc', Parser::FAILED);

# Combinator`s

trait ParserOr {
    function orElse(Parser $alternative): Parser {
        return parser(function($s) use ($alternative) {
            $result = $this($s);
            if ($result === Parser::FAILED) {
                $result = $alternative($s);
            }
            return $result;
        }, $this);
    }
}

test(take(10)->orElse(take(2)), '123', ['12','3']);


trait ParserM {

    function flatMap(callable $f): Parser {
        return parser(function($s) use ($f) {
            $result = $this($s);
            if ($result != Parser::FAILED) {
                list ($x, $rest) = $result;
                $next = $f($x);
                $result = $next($rest);
            }
            return $result;
        }, $this);
    }

    function onlyIf(callable $predicate): Parser {
        return $this->flatMap(function($x) use ($predicate) {
            return $predicate($x) ? just($x) : none();
        });
    }
    
    function prefixedWith(Parser $prefix): Parser {
        $self = $this;
        return $prefix->flatMap(function() use ($self) {
            return $self;
        });
    }
    
    function followedBy(Parser $after): Parser {
        return $this->flatMap(function($x) use ($after) {
            return $after->flatMap(function() use ($x) {
                return just($x);
            });
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

test(take(1)->onlyIf(function($x) { return false; }), '123', []);
test(take(1)->prefixedWith(take(1)), '123', ['2','3']);
test(take(1)->followedBy(take(1)),   '123', ['1','3']);


function literal(string $value): Parser {
    return take(strlen($value))->onlyIf(function($actual) use ($value) {
        return $actual === $value;
    });
}

test(literal('test'), 'test1', ['test','1']);
test(literal('test'), 'some1', []);

# DO notation

function _do(Closure $gen, $scope = null) {
    $step = function ($body) use (&$step) {
        if (! $body->valid()) {
            $result = $body->getReturn();
            return is_null($result) ? none() : just($result);
        } else {
            return $body->current()->flatMap(
                function($x) use (&$step, $body) {
                    $body->send($x);
                    return $step($body);
                });
        }
    };
    $gen = $gen->bindTo($scope);
    return parser(function($text) use ($step,$gen) {
        return $step($gen())($text);
    });
}

test(
    _do(function() {
        $x = yield take(1);
        $y = yield take(2);
        return "$x~$y";
    }),
    '1234',
    ['1~23','4']
);

# Combined parsers

function takeWhile(callable $predicate): Parser {
    return _do(function() use ($predicate) {
        $c = yield take(1)->onlyIf($predicate)->orElse(just(''));
        if ($c !== '') {
            $rest = yield takeWhile($predicate);
            return $c.$rest;
        } else {
            return '';
        }
    });
}

function takeOf(string $pattern): Parser {
    return takeWhile(function($c) use ($pattern) {
        return preg_match("/^$pattern$/", $c);
    });
}

test(takeOf('[0-9]'), '123abc', ['123','abc'   ]);
test(takeOf('[a-z]'), '123abc', [   '','123abc']);

trait ParserRep {
    function repeated(): Parser {
        $atLeastOne = _do(function() {
            $first = yield $this;
            $rest = yield $this->repeated();
            return array_merge([$first],$rest);
        },$this);
        return $atLeastOne->orElse(just([]));
    }
    function separatedBy(Parser $separator): Parser {
        $self = $this;
        $atLeastOne = _do(function() use ($separator) {
            $first = yield $this;
            $rest = yield $this->prefixedWith($separator)->repeated();
            return array_merge([$first], $rest);
        },$this);
        return $atLeastOne->orElse(just([]));
    }
}

test(take(2)->repeated(),                  '123456', [ ['12','34','56'], '' ]);
test(take(2)->separatedBy(literal(',')), '12,34,56', [ ['12','34','56'], '' ]);


