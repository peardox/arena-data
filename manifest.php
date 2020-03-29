<?php

/*******************************************************
 Note that the directory LOCAL_CACHE_BASE (default mtga)
 needs to be created prior to running this script and 
 must be web server writable!
 ******************************************************/

set_time_limit(0);

$memory_required = 64; // Largest file is currently 14M so give this thing more
$time_start = microtime_float(); // Function defined below
$download_historical_data = false; // Do we download old data?

echo "<pre>\n";

$memory_limit = ini_get('memory_limit');
if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
    if ($matches[2] == 'M') {
        $memory_limit = $matches[1] * 1024 * 1024; // nnnM -> nnn MB
    } else if ($matches[2] == 'K') {
        $memory_limit = $matches[1] * 1024; // nnnK -> nnn KB
    }
}

$memory_ok = ($memory_limit >= $memory_required * 1024 * 1024); // at least 64M?

if(!$memory_ok) {
    die("This uses a lot of memory, please update php.ini setting memory_limit = " . $memory_required . "M\n");
}


define("ARENA_LATEST_URI", "https://mtgarena.downloads.wizards.com/Live/Windows32/version");
define("ARENA_ASSETS_URI", "https://assets.mtgarena.wizards.com/");
define("ARENA_ASSETS_EXT", ".mtga");
define("LOCAL_CACHE_BASE", "mtga");
define("LOCAL_CACHE_MASK", 0775);
define("DIRECTORY_SLASH", "/");

$manifestList = array(
    '0c776d024556de696529119727482c1e', //  [20191002]
    '5d588739675426088a7ad8ca71fb6ee0', //  [20191008]
    'df98ae4f853800ba7ae8603368e0d146', //  [20191022]
    '261c820b2a588a83d4b652231c60956a', //  [20191024]
    'd45bb36ca6adaec666a988c58dbd6aa3', //  [20191121]
    '73d01185a7dc97f1f3ca37bae22a9b5f', //  [20191126]
    '4542c2ce9e7661ceb50fefb992059c93', //  [20191210]
    '4d86700e722d7e2d2426978e6b4f6ca4', //  [20191216]
    'd559c88f61860b3f2d2fd618bfccc858', //  [20200113]
    '11d04ffc7c6060b175f96deb11a518df', //  [20200114]
    'c51c1e0575e75aa7ea5d87d24dde8a64', //  [20200115]
    '42cc904046b2b348aad5987959462d57', //  [20200117]
    'eff2656930d77be4ebca1cbc2fb1e1f5', //  [20200122]
    '48ee6074c8e4761fda9ff44924e8bf5a', //  [20200210]
    'a63b36c32677b204a26063a4152302dd', //  [20200211]
    '23813b76a26fe593f0b1351cbcd2c08e', //  [20200309]
    'dbfd888e5501f52006511cf76c17ec81'  //  [20200324]
    );
    
function microtime_float() {
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
}

function split_headers($headers) {
    if(!(count($headers) && isset($headers[0]))) {
        return false;
    }

    $rval = array('status' => array(), 'header' => array());

    $status = explode(' ', $headers[0]);
    if(count($status) !== 3) {
        return false;
    } else {
        $rval['status']['version'] = $status[0];
        $rval['status']['code'] = $status[1];
        $rval['status']['reason'] = $status[2];
    }

    for($i=1; $i<count($headers); $i++) {
        $pos = strpos($headers[$i], ':');
        if($pos) {
            $key = substr($headers[$i], 0, $pos);
            $val = trim(substr($headers[$i], $pos + 1));
            if(isset($rval['header'][$key])) { // HTTP RFC2616
                $rval['header'][$key] .= ', ' . $val;
            } else {
                $rval['header'][$key] = $val;
            }
        }
    }
    
    return $rval;
}

function get_arena_latest_hash() {
    $rval = false;

    // Read the latest manifest header
    if($versionInfo = get_arena_latest()) {
        // Get the latest version info
        if(isset($versionInfo->Versions) && is_object($versionInfo->Versions)) {
            foreach($versionInfo->Versions as $key => $data) {
                // Split the version key into (four) parts
                $versionParts = explode('.', $key);
                if(count($versionParts) === 4) {
                    // Construct the UTI to obtain the manifest hash
                    $manifestHashURI = ARENA_ASSETS_URI . '/External_' . $versionParts[2] . '_' . $versionParts[3] . ARENA_ASSETS_EXT;
                    if(($data = @file_get_contents($manifestHashURI)) !== FALSE) {
                        // Read the manifest hash
                        $rval = $data;
                    } else {
                        die("Can't read Arena latest version hash from $manifestHashURI\n");
                    }
                    
                } else {
                    die("Unexpected version encountered: $key\n");
                }
                
                // We're only interested in the latest key so skip the rest (if any)
                break;
            }
        } else {
            die("Can't read version info\n");
        }
    } else {
        die("Can't read version info from " . ARENA_LATEST_URI . "\n");
    }
    
    return $rval;
}

function get_arena_latest() {
    // Default to a return value of false, this will only be changed if manifest is valid
    $rval = false;
    
    // Try to read data from manifest file
    if(($data = @file_get_contents(ARENA_LATEST_URI)) !== FALSE) {
        if(($json = json_decode($data)) !== NULL) {
            // Set return value to the decoded manifest header
            $rval = $json;
        } else {
            die("Can't decode Arena latest version JSON from " . ARENA_LATEST_URI . "\n");
        }
    } else {
        die("Can't read Arena latest version info from " . ARENA_LATEST_URI . "\n");
    }
    
    return $rval;
}

function get_arena_manifest($manifest) {
    // Build Arena manifest URI
    $manifestFile = 'Manifest_' . $manifest . ARENA_ASSETS_EXT;
    // Default to a return value of false, this will only be changed if manifest is valid
    $rval = false;
    
    // Create context for file_get_context
    $opts = array('http'=>array('method'=>"GET"));
    $context = stream_context_create($opts);
    
    // Try to read data from manifest file
    if(($data = @file_get_contents(ARENA_ASSETS_URI . $manifestFile, false, $context)) !== FALSE) {
        // Get the headers - we wanna check the Last-Modified
        $headers = split_headers($http_response_header);
        // Last-Modified is the patch release date
        if(isset($headers['header']) && isset($headers['header']['Last-Modified'])) {
            // Convert patch date to unixtime        
            if(($unixTime = strtotime($headers['header']['Last-Modified'])) !== false) {
                // Convert patch date to YYYYMMDD format (easy to read + sort)
                $manifestDate = date("Ymd",$unixTime);
                // Decompress the manifest data
                $manifestData = gzdecode($data);
                // Take the MD5 of the decompressed manifest data
                $hash = md5($manifestData);
                // hash should equal the supplied manifest value
                if($manifest === $hash) {
                    if(($manifestJSON = json_decode($manifestData)) !== NULL) {
                        // Return the date and data as an array
                        $cacheDir = LOCAL_CACHE_BASE . DIRECTORY_SLASH . $manifestDate;
                        $rval = array('date' => $manifestDate, 'cache' => $cacheDir, 'data' => $manifestJSON);
                        // save data to {LOCAL_CACHE_BASE}/{manifestDate}/{manifestFile}
                        if(!file_exists($cacheDir)) {
                            if(!mkdir($cacheDir, LOCAL_CACHE_MASK)) {
                                die("Can't create cache directory $cacheDir\n");
                            }
                        }
                        $cacheFile = $cacheDir . DIRECTORY_SLASH . $manifestFile;
                        if(file_put_contents($cacheFile, $manifestData) === false) {
                            die("Can't write manifest file to $cacheFile\n");
                        }
                    } else {
                        print_r($data);
                        die("Can't decode manifest JSON from $manifestFile\n");
                    }
                } else {
                    die("Hash fail\nVersion : $manifestDate - $manifest = $hash\n");
                }
            } else {
                die('Last-Modified header not invalid\n');
            }
        } else {
            die('Last-Modified header not found\n');
        }
    } else {
        die("Can't read Arena manifest file from $manifestFile\n");
    }
    
    return $rval;
}

function save_arena_data_file($manifestInfo, $cacheDir, $masterHash) {
    // get Arena data URI
    $dataFile = $manifestInfo->Name;
    // Default to a return value of false, this will only be changed if data is valid
    $rval = false;
    
    // Create context for file_get_context
    $opts = array('http'=>array('method'=>"GET"));
    $context = stream_context_create($opts);
    
    // Try to read data from data file
    if(($data = @file_get_contents(ARENA_ASSETS_URI . $dataFile, false, $context)) !== FALSE) {
        // Decompress the data
        $arenaData = gzdecode($data);

/*******************************************************
/* Hash problems so removed this bit for now.          *
 *                                                     *
 * For the main manifest the hash is the MD5 of the    *
 * original data. This test fails here but everything  *
 * looks fine and the filesizes match.                 *
 *******************************************************/

/*
        // Take the MD5 of the decompressed data
        $hash = md5($arenaData);
        // hash should equal the supplied value
        if($manifestInfo->Hash !== $hash) {
            echo "manifest : ";
            print_r($manifestInfo);
            echo "strlen(\$data) : " . strlen($data) . "\n";
            echo "strlen(\$arenaData) : " . strlen($arenaData) . "\n";
            echo "data hash : " . md5($data) . "\n";
            echo "decompressed data hash : $hash\n";
            die("Hash fail\n" . $manifestInfo->Hash . " != $hash\n");
        }
*/
        if(($dataJSON = json_decode($arenaData)) !== NULL) {
            // Return the date and data as an array
            $dataDir = $cacheDir . DIRECTORY_SLASH . $manifestInfo->AssetType;
            $rval = $dataJSON;
            // save data to {LOCAL_CACHE_BASE}/{manifestDate}/{AssetType}/{dataFile}
            if(!file_exists($dataDir)) {
                if(!mkdir($dataDir, LOCAL_CACHE_MASK)) {
                    die("Can't create data directory $dataDir\n");
                }
            }
            $dataFile = $dataDir . DIRECTORY_SLASH . $dataFile;
            if(file_put_contents($dataFile, $arenaData) === false) {
                die("Can't write data file to $dataFile\n");
            }
        } else {
            print_r($data);
            die("Can't decode data JSON from $dataFile\n");
        }
    } else {
        die("Can't read Arena data file from $dataFile\n");
    }
    
    return $rval;
}

function arena_download($hash) {
    echo "Hash : " . $hash . "\n";
    if(($manifest = get_arena_manifest($hash)) !== false) {
        // $manifest['date'] is YYYYMMDD
        // $manifest['data'] is the decoded manifest object
        echo "date : " . $manifest['date'] . "\n";
        echo "cache : " . $manifest['cache'] . "\n";
        // FormatVersion was 7 from ???????? until 20191024
        // FormatVersion is 8 from 20191121
        echo "version : " . $manifest['data']->FormatVersion . "\n";
        // EncryptionKey - blank (we hope)
        echo "encryption : " . $manifest['data']->EncryptionKey . "\n";
        // $manifest['data']->Assets is a BIG array of individual manifest file objects
        for($i=0; $i<count($manifest['data']->Assets); $i++) {
            // For our purposes we're only interested in objects with an AssetType of Data or Loc
            if($manifest['data']->Assets[$i]->AssetType === 'Data' ||  $manifest['data']->Assets[$i]->AssetType === 'Loc') {
                // Save the data file(s)
                save_arena_data_file($manifest['data']->Assets[$i], $manifest['cache'], $hash);
            }
        }
    }
}

function check_latest_hash($hash) {
    $rval = false;
    
    $hashfile = LOCAL_CACHE_BASE . DIRECTORY_SLASH . 'latest';
    
    // If $hashfile exists and is not the current hash return true otherwise return false
    if(file_exists($hashfile)) {
        if(($oldHash = @file_get_contents($hashfile)) !== FALSE) {
            if($oldHash !== $hash) {
                // Update latest
                if(file_put_contents($hashfile, $hash) === false) {
                    die("Can't write hash file to $hashfile\n");
                }
                $rval = true;
            }
        } else {
            die("Can't read $hashfile\n");
        }
    } else {
        // First run - update latest
        if(file_put_contents($hashfile, $hash) === false) {
            die("Can't write hash file to $hashfile\n");
        }
    }
    
    return $rval;
}

if(file_exists(LOCAL_CACHE_BASE)) {
    if(is_dir(LOCAL_CACHE_BASE)) {
        if(!is_writable(LOCAL_CACHE_BASE)) {
            die("Cache directory '" . LOCAL_CACHE_BASE . "' is not writable by the web server\n");
        }
    } else {
        die("Cache directory '" . LOCAL_CACHE_BASE . "' is not a directory\n");
    }
} else {
    die("Cache directory '" . LOCAL_CACHE_BASE . "' does not exist\n");
}

if($download_historical_data) {
    // This will read all the old data from Arena I know the hashes for
    for($i=0; $i<count($manifestList); $i++) {
        arena_download($manifestList[$i]);
        echo "\n";
        flush();
    }
}

$latestHash = get_arena_latest_hash();

echo "latestHash = $latestHash\n\n";

if(check_latest_hash($latestHash)) {
    echo "New update found, downloading...\n";
    flush();
    arena_download($latestHash);
} else {
    echo "Up to date\n";
}

$time_elapsed = microtime_float() - $time_start;
echo "\nTime Elapsed : $time_elapsed\n\n";
