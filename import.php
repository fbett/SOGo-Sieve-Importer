<?php

require 'rcube_sieve_script.php';

const TEMP_FILE_PATH = __DIR__ . DIRECTORY_SEPARATOR . '.temp';
const CREDENTIALS_FILE_PATH = __DIR__ . DIRECTORY_SEPARATOR . 'credentials';

if(!file_exists(CREDENTIALS_FILE_PATH)) {
    die('Credentails file not found');
}

if(!isset($argv)) {
    die('Missing Arguments');
}

if(!isset($argv[1])) {
    die('Missing argument 1: sieve file');
}

if(!isset($argv[2])) {
    die('Missing argument 1: user');
}

if(!file_exists($argv[1])) {
    die('Input file could not be found');
}

$script = new rcube_sieve_script(file_get_contents($argv[1]));

$sogoSieveForward = [];

$result = [
    'SOGoSieveFilters' => []
];

foreach($script->as_array() as $entry) {

    if($entry[ 'type' ] != 'if' ) {
        throw new RuntimeException('Invalid type: ' . $entry[ 'type' ]);
    }

    $actions = array_map(function($action) use(&$sogoSieveForward) {

        if($action['type'] == 'redirect') {

            $sogoSieveForward[] = [
                'forwardAddress' => $action['target'],
                'enabled' => 1,
                'keepCopy' => 0,
            ];
        }

        if($action['type'] == 'fileinto' || $action['type'] == 'redirect') {
            return [
                'method' => $action['type'],
                'argument' => $action['target'],
            ];
        }

        if($action['type'] == 'setflag' || $action['type'] == 'addflag') {
            $replaceFlages = [
                '\Answered' => 'answered',
                '\Deleted' => 'deleted',
                '\Draft' => 'draft',
                '\Flagged' => 'flagged',
                'Junk' => 'junk',
                'NotJunk' => 'not_junk',
                '\Seen' => 'seen',
            ];

            if($action['type'] == 'setflag') {
                $action['type'] = 'addflag';
            }

            if(!isset($replaceFlages[$action['target']])) {
                throw new RuntimeException('Flag not found: ' . $action['target']);
            }

            return [
                'method' => $action['type'],
                'argument' => $replaceFlages[$action['target']],
            ];
        }

        if($action['type'] == 'stop' || $action['type'] == 'discard' || $action['type'] == 'keep') {
            return [
                'method' => $action['type'],
            ];
        }

        throw new RuntimeException('Unsupported action: ' . var_export($action, true));

    }, $entry['actions']);

    $rules = array_map(function($rule) {

        if($rule['test'] == 'header' || $rule['test'] == 'exists') {

            if($rule['test'] == 'exists') {

                if($rule[ 'not' ]) {
                    throw new RuntimeException('"exists not" not supprted');
                }

                $rule['type'] = 'is_not';
                $rule['arg1'] = $rule['arg'];
                $rule['arg2'] = '';
            }

            $defaultHeaders = [
                'subject', 'to_or_cc', 'from', 'to', 'cc'
            ];

            $result = [
                'operator' => $rule[ 'type' ] . ( $rule[ 'not' ] ? '_not' : '' ),
                'value' => $rule[ 'arg2' ] ?? null,
            ];

            if( array_search($rule[ 'arg1' ], $defaultHeaders) !== false ) {
                $result[ 'field' ] = $rule[ 'arg1' ];
            } else {
                $result[ 'field' ] = 'header';
                $result[ 'custom_header' ] = $rule[ 'arg1' ];
            }

            return $result;
        }

        if($rule['test'] == 'body') {

            return [
                'field' => 'body',
                'operator' => $rule[ 'type' ] . ( $rule[ 'not' ] ? '_not' : '' ),
                'value' => $rule[ 'arg' ],
            ];
        }

        if($rule['test'] == 'true') {
            return null;
        }

        throw new RuntimeException('Unsupported rule test:' . $rule['test']);

    }, $entry['tests']);
    
    // Remove empty rules
    $rules = array_filter($rules, function($result) { return $result !== null; });

    $filter = [
        'active' => !$entry['disabled'],
        'actions' => $actions,
        'match' => $entry['join'] ? 'all' : 'any',
        'name' => $entry['name'],
    ];

    if(count($rules) > 0) {
        $filter['rules'] = $rules;
    } else {
        $filter['match'] = 'allmessages';
    }

    $result['SOGoSieveFilters'][] = $filter;
}

file_put_contents(TEMP_FILE_PATH, json_encode($result));

$command = 'sogo-tool user-preferences set defaults ' . $argv[2] . ' -p "' . CREDENTIALS_FILE_PATH . '" SOGoSieveFilters -f "' . TEMP_FILE_PATH . '"';

$output = [];
$resultCode = null;
exec($command, $output, $resultCode);
var_dump([
    $output,
    $result
]);

unlink(TEMP_FILE_PATH);

//var_dump(json_encode($result, true));
//var_dump($sogoSieveForward);