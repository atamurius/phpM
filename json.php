#!/usr/bin/php
<?php

require_once 'test.php';
require_once 'parser.php';

# JSON

$jNumber = _do(function() {
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

$jNull = literal('null')->flatMap(function() use ($NULL) { 
    return just($NULL); 
});

test($jNull, 'null', [$NULL,'']);

$jBool = literal('true')->orElse(literal('false'))->flatMap(function($value) {
    return just($value === 'true');
});

test($jBool, 'true', [true,'']);
test($jBool, 'false', [false,'']);

$jString = _do(function() {
    yield literal('"');
    $value = yield takeOf('[^"]');
    yield literal('"');
    return $value;
});

test($jString, '""',       ["",'']);
test($jString, '"abc"',    ["abc",'']);
test($jString, '"abc',     []);
test($jString, 'abc"',     []);

$jList = _do(function() use (&$jValue) {
    yield literal('[');
    $items = yield $jValue->separatedBy(literal(','));
    yield literal(']');
    return $items;
});

$jObject = _do(function() use (&$jValue) {
    yield literal('{');
    
    $result = [];
    $pair = _do(function() use (&$jValue,&$result) {
        $key = yield takeOf('\\w');
        yield literal(':');
        $value = yield $jValue;
        $result[$key] = $value;
        return true;
    });
    yield $pair->separatedBy(literal(','));
    yield literal('}');
    
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

