<?php
header( 'content-type: text/html; charset:utf-8' );
session_start();

# +-----------------------------------+
# |     C O N F I G U R A T I O N     |
# +-----------------------------------+

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require './Exception.php';
require './PHPMailer.php';
require './SMTP.php';



# whether or not to ask for a password, and if yes, the array of allowed passwords to access directory/playlist contents
$usepassword = true;
$passwords = array('123', 'abc');

# files with the following extensions will be displayed (case-insensitive)
# note that it depends on your browser whether or not these will actually play
$allowedextensions = array( 'mp3', 'flac', 'wav', 'ogg', 'opus', 'webm' );

# the following directories and files will not be displayed (case-sensitive)
$excluded = array( '.', '..', '.git', '.htaccess', '.htpasswd', 'backgrounds', 'cgi-bin', 'docs', 'getid3', 'logs', 'usage' );

# the width of the player (in desktop mode)
$width = '40%';

# different themes given by their background image and element colours
    # "shore"
$backgroundimg = './backgrounds/bg_shore.jpg';
$background = '#222';
$accentfg = '#000';
$accentbg = '#fc0';
$menubg = '#eee';
$menushadow = '#ddd';
$gradient1 = '#1a1a1a';
$gradient2 = '#444';
$filebuttonfg = '#bbb';

$serverDB = "localhost";
$usernameDB = "root";
$passwordDB = "";
$nameDB = "usea";

$user_id = 0;

// Create connection
$conn = new mysqli($serverDB, $usernameDB, $passwordDB, $nameDB);

// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

if( isset( $_POST['password'] ) ) {

// Define your SQL query
$sql = "SELECT * FROM users where password = '".$_POST['password']."';"; 
// Execute the query
$result = $conn->query($sql);

// Check if there are any results
if ($result->num_rows > 0) {

    // Output data for each row
    while ($row = $result->fetch_assoc()) {
        $user_id = $row['id'];

        if (htmlspecialchars( $_POST['password'] )) {
            $_SESSION['authenticated'] = 'yes';
            header( "Location: {$_SERVER['HTTP_REFERER']}" );
        } else {
            loadPage('', 'Incorrect password', '');
        }
    }
}
else
{
    loadPage('', 'Incorrect password', '');
}
 // Close the connection
$conn->close();

} elseif( isset( $_GET['play'] ) ) {
    ### playing the indicated song
    $song = sanitizeGet( $_GET['play'] );
    
    if ( is_file( $song ) ) {
        # obtaining song info
        $songinfo = getsonginfo( $song );

        # getting list of songs in this directory
        $dirsonglist = getDirContents( dirname( $song ) );
        foreach ($dirsonglist['files'] as &$file) {
            $file = dirname( $song ) . '/' . $file;
        } unset($file);

        # setting cookies
        setcookie( 'nm_nowplaying', rawurlencode( $song ), strtotime( '+1 day' ) );
        setcookie( 'nm_songs_currentsongdir', json_encode( $dirsonglist['files'] ), strtotime ( '+1 week' ) );
        
        # updating active song list and active song index
        if ( !isset( $_COOKIE['nm_songs_active'] ) ) {
            # if no active song list set: setting current dir as active song list 
            setcookie( 'nm_songs_active', json_encode( $dirsonglist['files'] ), strtotime ( '+1 week' ) );
            setcookie( 'nm_songs_active_idx', array_search( $song, $dirsonglist['files'] ), strtotime ( '+1 week' ) );
        } else {
            $activesonglist = json_decode( $_COOKIE['nm_songs_active'], true );
            if ( array_search( $song, $activesonglist ) === false ) {
                # if current song not in active song list: we must be in browse mode and entered a new directory
                if ( isset( $_COOKIE['nm_shuffle'] ) && $_COOKIE['nm_shuffle'] == 'on' ) {
                    # if shuffle on: first shuffling list
                    shuffle( $dirsonglist['files'] );
                    array_splice( $dirsonglist['files'], array_search( $song, $dirsonglist['files'] ), 1 );
                    array_unshift( $dirsonglist['files'], $song );
                }
                setcookie( 'nm_songs_active', json_encode( $dirsonglist['files'] ), strtotime ( '+1 week' ) );                
                setcookie( 'nm_songs_active_idx', array_search( $song, $dirsonglist['files'] ), strtotime ( '+1 week' ) );
            } else {    
                setcookie( 'nm_songs_active_idx', array_search( $song, $activesonglist ), strtotime ( '+1 week' ) );
            }
        }
        
        # no error message
        $error = '';
    } else {
        # defaulting to root directory and displaying error message
        $songinfo = array();
        $error = "Could not find file {$song}.";
        sendMail($song, '', $error);
        errorLog($song, '', $error);
        $song = '';
    }

    loadPage( $song, $error, $songinfo );
} elseif( isset( $_GET['which'] ) )  {
    ### responding to AJAX request for next/previous song in songlist
    $which = sanitizeGet( $_GET['which'] );

    if ( isset( $_COOKIE['nm_songs_active'] ) && isset( $_COOKIE['nm_songs_active_idx'] ) ) {
        $songlist = json_decode( $_COOKIE['nm_songs_active'], true );
        $currentindex = $_COOKIE['nm_songs_active_idx'];

        if ( $which === 'next' && isset( $songlist[$currentindex + 1] ) ) {
            echo rawurlencode( $songlist[$currentindex + 1] );
        } elseif ( $which === 'previous' && isset( $songlist[$currentindex - 1] ) ) {
            echo rawurlencode( $songlist[$currentindex - 1] );
        }
    }
} elseif( isset( $_GET['dir'] ) )  {
    ### responding to AJAX request for directory contents

    if ( $usepassword && !isset ( $_SESSION['authenticated'] ) ) {
        # show "Password required [             ]"
        echo <<<PASSWORDREQUEST
<div id="header"><div id="passwordrequest">
    Password required
    <form action="." method="post">
        <input type="password" name="password" id="passwordinput" />
        <input type="submit" value="Submit" />
    </form>
</div></div>
PASSWORDREQUEST;
    } else {
    
        $basedir = sanitizeGet( $_GET['dir'] );

        if ( is_dir( $basedir ) && !in_array( '..', explode( '/', $basedir ) ) ) {
            # setting currentbrowsedir cookie
            setcookie( 'nm_currentbrowsedir', rawurlencode( $basedir ), strtotime( '+1 day' ) );

            # listing directory contents
            $dircontents = getDirContents( $basedir );

            # returning header
            echo '<div id="header">';
            renderWaktu();
            echo '</div>';
            echo '<div id="header">';
            renderForm();
            echo '</div>';
            echo '<div id="header">';

            renderButtons();
            echo '<div id="breadcrumbs">';
            $breadcrumbs = explode( '/', $basedir );
            for ( $i = 0; $i != sizeof( $breadcrumbs ); $i++ ) {
                $title = $breadcrumbs[$i] == '.'  ? 'Root'  : $breadcrumbs[$i];

                if ($i == sizeof($breadcrumbs) - 1) {
                    # current directory
                    echo "<span id=\"breadcrumbactive\">{$title}</span>";
                } else {
                    # previous directories with link
                    $link = rawurlencode( implode( '/', array_slice( $breadcrumbs, 0, $i+1 ) ) );
                    echo "<span class=\"breadcrumb\" onclick=\"goToDir('{$link}');\">{$title}</span><span class=\"separator\">/</span>";
                }
            }
            echo '</div>';
            echo '</div>';
            print_r($basedir);
            if ( empty( $dircontents['dirs'] ) && empty( $dircontents['files'] ) ) {
                # nothing to show
                echo '<div id="filelist" class="list"><div>This directory is empty.</div></div>';
            } else {
                # returning directory list
                if ( !empty( $dircontents['dirs'] ) ) {
                    echo '<div id="dirlist" class="list">';
                    foreach ( $dircontents['dirs'] as $dir ) {
                        $link = rawurlencode( $basedir . '/' . $dir );
                        echo "<div class=\"dir\" onclick=\"goToDir('{$link}');\">{$dir}</div>";
                    } unset( $dir );
                    echo '</div>';
                }

                # returning file list
                if ( !empty( $dircontents['files'] ) ) {
                    echo '<div id="filelist" class="list">';
                    foreach ( $dircontents['files'] as $file ) {
                        $link = rawurlencode( $basedir . '/' . $file );
                        $song = pathinfo( $file, PATHINFO_FILENAME );
                        $jslink = str_replace( "'", "\'", $link );
                        $nowplaying = ( isset( $_COOKIE['nm_nowplaying'] ) && $_COOKIE['nm_nowplaying'] == $link ) ? ' nowplaying' : '';
                        echo "<div class=\"file{$nowplaying}\"><a href=\"?play={$link}\" onclick=\"setPlayMode('browse', '{$jslink}');\">&#x25ba; {$song}</a><div class=\"filebutton\" onclick=\"addToPlaylist('{$jslink}');\" title=\"Add to playlist\">+</div></div>";
                    } unset( $file );
                    echo '</div>';
                }
            }
        }
    }
} elseif( isset( $_GET['playlist'] ) )  {
    ### responding to AJAX request for playlist contents

    if ( $usepassword && !isset ( $_SESSION['authenticated'] ) ) {
        # show "Password required [             ]"
        echo <<<PASSWORDREQUEST
<div id="header"><div id="passwordrequest">
    Password required
    <form action="." method="post">
        <input type="password" name="password" id="passwordinput" />
        <input type="submit" value="Submit" />
    </form>
</div></div>
PASSWORDREQUEST;
    } else {
        if ( isset( $_COOKIE['nm_songs_playlist'] ) ) {
            $playlist = json_decode( $_COOKIE['nm_songs_playlist'], true );
        }

        # returning header
        echo '<div id="header">';
        renderWaktu();
        echo '</div>';
        echo '<div id="header">';
        renderForm();
        echo '</div>';
        echo '<div id="header">';
        renderButtons();
        echo '<div id="playlisttitle">Playlist</div>';
        echo '</div>';

        if ( empty( $playlist ) ) {
            # nothing to show
            echo '<div id="filelist" class="list"><div>This playlist is empty.</div></div>';
        } else {
            echo '<div id="filelist" class="list">';
            foreach ( $playlist as $link ) {
                $song = pathinfo( $link, PATHINFO_FILENAME );
                $dir = dirname( $link );
                
                $playlistdir = ( $dir == '.' ? '' : "<span class=\"playlistdirectory\">{$dir}</span><br />" );
                
                $link = rawurlencode( $link );
                $nowplaying = ( isset( $_COOKIE['nm_nowplaying'] ) && $_COOKIE['nm_nowplaying'] == $link ) ? ' nowplaying' : '';
                $jslink = str_replace( "'", "\'", $link );
                echo "<div class=\"file{$nowplaying}\"><a href=\"?play={$link}\" onclick=\"setPlayMode('playlist', '{$jslink}');\">{$playlistdir}&#x25ba; {$song}<br /></a><div class=\"filebutton\" onclick=\"moveInPlaylist('{$jslink}', -1);\"title=\"Move up\">&#x2191</div><div class=\"filebutton\" onclick=\"moveInPlaylist('{$jslink}', 1);\"title=\"Move down\">&#x2193</div><div class=\"filebutton\" onclick=\"removeFromPlaylist('{$jslink}');\" title=\"Remove from playlist\">&#x00d7</div></div>";
            } unset( $file );
            echo '</div>';
        }
    }
} else {
    ### rendering default site
    loadPage();
}


function renderButtons() {
    # toggling active class for active buttons
    $viewmode = ( isset( $_COOKIE['nm_viewmode'] ) && $_COOKIE['nm_viewmode'] == 'playlist' ) ? 'playlist' : 'browse';
    $playlistactive = ( $viewmode == 'playlist' ) ? ' active' : '';
    $browseactive = ( $viewmode == 'browse' ) ? ' active' : '';
    $shuffleactive = ( isset( $_COOKIE['nm_shuffle'] ) && $_COOKIE['nm_shuffle'] == 'on' ) ? ' active' : '';

    # setting browse directory when browse mode is activated
    if ( isset( $_COOKIE['nm_currentbrowsedir'] ) ) { $dir = $_COOKIE['nm_currentbrowsedir']; }
    elseif ( isset( $_COOKIE['nm_currentsongdir'] ) ) { $dir = $_COOKIE['nm_currentsongdir']; }
    else { $dir = '.'; }
    
    # rendering playlist buttons when in playlist mode
    if ( $viewmode == 'playlist' ) {
        $playlistbuttons = <<<PLBUTTONS
        <div class="button" onclick="clearPlaylist();"><span>Clear</span></div>
        <div class="separator"></div>
PLBUTTONS;
    } else {
        $playlistbuttons = '';
    }

    # rendering general buttons
    echo <<<BUTTONS
    <div class="buttons">
        {$playlistbuttons}
        <div class="button{$shuffleactive}" id="shufflebutton" onclick="toggleShuffle();"><span>Shuffle</span></div>
        <div class="separator"></div>
        <div class="button border{$browseactive}" onclick="goToDir('{$dir}');"><span>Browse</span></div>
        <div class="button{$playlistactive}" onclick="goToPlaylist('default')"><span>Playlist</span></div>
    </div>
BUTTONS;
}

function errorLog($boxId = '', $prayerTimeZone  = '', $errorMessage  ) {    
    // Create connection
    $conn = new mysqli($serverDB, $usernameDB, $passwordDB, $nameDB);
    
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    
    $sql_log_error = "INSERT INTO errors (user_id, error_message) VALUES ('$user_id', '$error_message')";
    
    // Execute the INSERT statement for logging errors using PHP
    if ($conn->query($sql_log_error) === TRUE) {
        echo "Error logged successfully";
    } else {
        echo "Error: " . $sql_log_error . "<br>" . $conn->error;
    }
    
    $conn->close();
    
}

function sendMail($boxId = '', $prayerTimeZone  = '', $errorMessage  ) {    
    $mail = new PHPMailer(true);

    try {
        // SMTP configuration
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'yeftalaoh@gmail.com'; // Your Gmail email address
        $mail->Password = 'oojm wjgq fmer godq'; // Your Gmail password
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
    
        // Sender and recipient settings
        $mail->setFrom('yeftalaoh@gmail.com', 'yefta laoh'); // Sender's email and name
        $mail->addAddress('phu@expressinmusic.com', 'Recipient Name'); // Recipient's email and name
    

        // Construct the email message
        $message = "Box ID: $boxId\n";
        $message .= "Prayer Time Zone: $prayerTimeZone\n";
        $message .= "Error Message: $errorMessage\n";


        // Email content
        $mail->isHTML(true);
        $mail->Subject = 'Error Report';
        $mail->Body = 'This is a test email sent from PHPMailer.';
    
        // Send email
        $mail->send();
        echo 'Email sent successfully.';
    } catch (Exception $e) {
        echo 'Email could not be sent. Error: ', $mail->ErrorInfo;
    }
}

function renderWaktu() {    
    # rendering general buttons
    echo <<<CHECKBOXWAKTU
    <div id="playlisttitle">
        <input type="checkbox" id="isP" onclick="toggleDiv();">
        <label for="isP"> Prayer time enable?</label><br>
    </div>
CHECKBOXWAKTU;
}

function renderForm() {    
    # rendering general buttons
    echo <<<FORMWAKTU
    <div id="Create" style="display:none">
    <select name='pilih_negeri' id='pilih_negeri' onchange="changePilihNegeri()">
        <option value=''>Pilih Negeri</option>
    </select>
    <select id='pilih_zone' name='pilih_zone' onchange="changePilihZone()">
        <option value=''>Pilih Zon</option>
    </select>
    </div>
FORMWAKTU;
}

function getDirContents( $dir ) {
    global $excluded, $allowedextensions;
    $allowedextensions = array_map( 'strtolower', $allowedextensions );

    $dirlist = array();
    $filelist = array();
    
    # browsing given directory
    if ( $dh = opendir( $dir ) ) {
        while ( $itemname = readdir( $dh ) ) {
            # ignoring certain files
            if ( !in_array( $itemname, $excluded ) ) {
                if ( is_file( $dir . '/' . $itemname ) ) {
                    # found a file: adding allowed files to file array
                    $info = pathinfo( $itemname );
                    if ( isset( $info['extension'] ) && in_array( strtolower( $info['extension'] ), $allowedextensions ) ) {
                        $filelist[] = $info['filename'] . '.' . $info['extension'];
                    }
                } elseif ( is_dir( $dir . '/' . $itemname ) ) {
                    # found a directory: adding to directory array
                    $dirlist[] = $itemname;
                }
            }
        }
        closedir($dh);
    }

    if ( sizeof( $dirlist ) > 1 ) { usort( $dirlist, 'compareName' ) ; }
    if ( sizeof( $filelist ) > 1 ) { usort( $filelist, 'compareName' ) ; }

    return array('dirs' => $dirlist, 'files' => $filelist);
}


function getSongInfo( $song ) {
    ### if available, using getID3 to extract song info

    if ( file_exists( './getid3/getid3.php' ) ) {
        # getting song info
        require_once( './getid3/getid3.php' );
        $getID3 = new getID3;
        $fileinfo = $getID3->analyze( $song );
        getid3_lib::CopyTagsToComments( $fileinfo );
        
        # extracting song title, or defaulting to file name
        if ( isset( $fileinfo['comments_html']['title'][0] ) && !empty( trim( $fileinfo['comments_html']['title'][0] ) ) ) {
            $title = trim( $fileinfo['comments_html']['title'][0] );
        } else {
            $title = pathinfo($song, PATHINFO_FILENAME);
        }

        # extracting song artist, or defaulting to directory name
        if ( isset( $fileinfo['comments_html']['artist'][0] ) && !empty( trim( $fileinfo['comments_html']['artist'][0] ) ) ) {
            $artist = trim( $fileinfo['comments_html']['artist'][0] );
        } else {
            $artist = str_replace( '/', ' / ', dirname( $song ) );
        }

        # extracting song album
        if ( isset( $fileinfo['comments_html']['album'][0] ) && !empty( trim( $fileinfo['comments_html']['album'][0] ) ) ) {
            $album = trim( $fileinfo['comments_html']['album'][0] );
        } else {
            $album = '';
        }

        # extracting song year/date
        if ( isset( $fileinfo['comments_html']['year'][0] ) && !empty( trim( $fileinfo['comments_html']['year'][0] ) ) ) {
            $year = trim( $fileinfo['comments_html']['year'][0] );
        } elseif ( isset($fileinfo['comments_html']['date'][0] ) && !empty( trim( $fileinfo['comments_html']['date'][0] ) ) ) {
            $year = trim( $fileinfo['comments_html']['date'][0] );
        } else {
            $year = '';
        }

        # extracting song picture
        if ( isset( $fileinfo['comments']['picture'][0] ) ) {
            $art = 'data:'.$fileinfo['comments']['picture'][0]['image_mime'].';charset=utf-8;base64,'.base64_encode( $fileinfo['comments']['picture'][0]['data'] );
        } else {
            $art = '';
        }

        return array(
            "title" => $title,
            "artist" => $artist,
            "album" => $album,
            "year" => $year,
            "art" => $art
        );
    } else {
        # defaulting to song filename and directory when getID3 is not available
        return array(
            "title" => basename( $song ),
            "artist" => dirname( $song ),
            "album" => '',
            "year" => '',
            "art" => ''
        );
    }
}


function sanitizeGet( $str ) {
    $str = stripslashes( $str );
	return $str;
}


function compareName( $a, $b ) {
    # directory name comparison for usort
    return strnatcasecmp( $a, $b );
}


function loadPage( $song = '', $error = '', $songinfo = array() ) {
    global $width, $background, $backgroundimg, $accentfg, $accentbg, $menubg, $menushadow, $gradient1, $gradient2, $filebuttonfg;

    # hiding error message div if there is no message to display
    $errordisplay = empty( $error ) ? 'none' : 'block';

    if ( isset( $_COOKIE['nm_viewmode'] ) && $_COOKIE['nm_viewmode'] == 'playlist' ) {
        # loading playlist view
        $onloadgoto = "goToPlaylist('default');";
    } else {
        # loading directory view
        if ( isset( $_COOKIE['nm_currentbrowsedir'] ) ) { $dir = $_COOKIE['nm_currentbrowsedir']; }
        elseif ( isset( $_COOKIE['nm_currentsongdir'] ) ) { $dir = $_COOKIE['nm_currentsongdir']; }
        else { $dir = '.'; }
        $onloadgoto = "goToDir('{$dir}');";
    }

    # setting player layout depending on available information
    if ( empty( $songinfo ) ) {
        # no information means no file is playing
        $songtitle = 'No file playing';
        $songinfoalign = 'center';
        $songsrc = '';
        $pagetitle = "Music";

        # hiding info elements
        $artist = '';
        $artistdisplay = 'none';

        $album = '';
        $albumdisplay = 'none';

        $year = '';
        $yeardisplay = 'none';

        $art = '';
        $artdisplay = 'none';
    } else {
        # displaying info elements where available
        $songsrc = " src=\"" . implode('/', array_map('rawurlencode', explode('/', $song))) . "\""; # encoding individual path elements while keeping separators
        $songtitle = $songinfo['title'];
        $pagetitle = $songtitle;
        if ( !empty( $songinfo['artist'] ) ) {
            $artist = $songinfo['artist'];
            $artistdisplay = 'block';
            $pagetitle = "$artist - $pagetitle";
        } else {
            $artistdisplay = 'none';
        }
        if ( !empty( $songinfo['album'] ) ) {
            $album = $songinfo['album'];
            $albumdisplay = 'block';
        } else {
            $album = '';
            $albumdisplay = 'none';
        }
        if ( !empty( $songinfo['year'] ) ) {
            $year = $songinfo['year'];
            $yeardisplay = 'inline-block';
        } else {
            $year = '';
            $yeardisplay = 'none';
        }
        if ( !empty( $songinfo['art'] ) ) {
            $art = $songinfo['art'];
            $artdisplay = 'block';
            $songinfoalign = 'left';
        } else {
            $art = '';
            $artdisplay = 'none';
            $songinfoalign = 'center';
        }
    }

    # writing page
    echo <<<HTML
<!doctype html>

<html lang="en" prefix="og: http://ogp.me/ns#">
<head>
    <meta charset="utf-8" />

    <title>{$pagetitle}</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" id="viewport" />

    <script>

        let events = [];

        // Function to add event
        function addEvent(codeZone, eventNameX, eventDateTime) {
            if(eventNameX && eventDateTime) {
                events.push({ name: eventNameX, dateTime: new Date(eventDateTime) });
                //console.log(events);
                var apiURL = "./api.php"; //my JSON API

                // Define the data to be sent in the request body                
                var data = {
                    user_id: 1,
                    code_zone: codeZone,
                    prayer_name: eventNameX,
                    prayer_time: eventDateTime
                };

                var add  = {add: data } ;

                // Define options for the fetch request
                const options = {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(add)
                };

                // Make the POST request
                fetch(apiURL, options)
                .then(response => {
                    if (!response.ok) {
                    throw new Error('Network response was not ok');
                    }
                    return response.json(); // Parse response body as JSON
                })
                .then(data => {
                    console.log('Response from server:', data);
                })
                .catch(error => {
                    console.error('There was a problem with the POST request:', error);
                });
            }
        }

        function removeEvent() {
            if (events.length > 0){
                document.getElementById("pilih_negeri").innerHTML = "";
                var textNegeri = "<option value=''>Pilih Negeri</option>";
                document.getElementById("pilih_negeri").innerHTML = textNegeri; //append list

                document.getElementById("pilih_zone").innerHTML = "";
                var textZone = "<option value=''>Pilih Zone</option>";
                document.getElementById("pilih_zone").innerHTML = textZone; //append list

                events = [];
                alert("remove all event")
            }
        }

        // Periodically check for upcoming events
        setInterval(() => {
            // Get current timestamp
            var now = new Date();
            //now.setMinutes(40);
            //now.setHours(05);

            // Set miliseconds and seconds component to 0
            now.setMilliseconds(0);
            now.setSeconds(0);

            console.log("Timestamp with seconds removed: ", now, now.getTime());
            //const rangeStartTime = new Date(now.getTime() - (30 * 60 * 1000)); // 3 minutes before now
            //const rangeEndTime = new Date(now.getTime() + (1 * 60 * 1000)); // 3 minutes after now
            //console.log("rangeStartTime: "+rangeStartTime);
            //console.log("rangeEndTime: "+rangeEndTime);

            const upcomingEvents = events.filter(event =>
                event.dateTime.getTime() == now.getTime()
            );
            
            if(upcomingEvents.length > 0) {
                event = [];
                //console.log("Upcoming events name: "+upcomingEvents);
                upcomingEvents.forEach((event, index) => {
                    console.log("Upcoming name: "+upcomingEvents[0]);
                    var audio = document.getElementById('audio');
                    audio.pause();
                    setTimeout(() => {
                        audio.play();
                    }, 15000);
                    play('Songs/Adzan.mp3', 15000); // Play 'mp3' for 15 seconds
                    events.splice(index, 1); // Removes the element at the found index
                    console.log( event.name +" - "+ event.dateTime );
                });
            } else {
                console.log("No upcoming events");
            }
            
        }, 5000); // Check every minute


        function play(audio_path, time_in_milisec) {
            let beep = new Audio(audio_path);
            beep.loop = true;
            beep.play();
            setTimeout(() => {
                beep.pause();
            }, time_in_milisec);
        }

        //event selector to detect if "pilih negeri" select box is change
        //if change, fetch and append the list of zones from zone.json (thx abam shahril) for the chosen state
        function changePilihNegeri()
        {
            document.getElementById("pilih_zone").innerHTML = "";

            var negeri = document.getElementById("pilih_negeri").value;
            // Use fetch API to fetch JSON data
            fetch("./api.php?stateName=" + negeri)
            .then(response => response.json())
            .then(data => {
                var zonelist = "";
                for (var key in data) {
                    if (data.hasOwnProperty(key)) {
                        zonelist += "<option value='" + key + "'>" + data[key] + "</option>";
                    }
                }

                var textpilih = "<option value=''>Pilih Zon</option>";
                document.getElementById("pilih_zone").innerHTML = textpilih + zonelist; //append list
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        function changePilihZone()
        {
            var codeZone = document.getElementById("pilih_zone").value;
            var apiURL = "./api.php?zon=" + codeZone; //my JSON API
                fetch(apiURL)
                .then(response => response.json())
                .then(data => {
                    //var imsak = data["waktu_imsak"];
                    //var subuh = data["waktu_subuh"];
                    //var syuruk = data["waktu_syuruk"];
                    //var zohor = data["waktu_zohor"];
                    //var asar = data["waktu_asar"];
                    //var maghrib = data["waktu_maghrib"];
                    //var isyak = data["waktu_isyak"];

                    if(!data.hasOwnProperty("data"))
                    {
                        alert("Event Added");
                        data.forEach((obj, index) => {
                            var date_add = obj["date"];
                            //console.log(date_add);
                            for (const key in obj) {
                                if (key != "date" && key != "day" && key != "hijri")
                                {
                                addEvent(codeZone, key + "_" + index, date_add + " " + obj[key]);
                                }
                            }
                        });
                    }
                    else
                    {
                        alert("No data available for the current request");
                    }
                })
                .catch(error => {
                console.error('Error:', error);
            });
        }

        function goToDir(dir) {
            setCookie('nm_viewmode', 'browse', 7);

            // getting and displaying directory contents
            xmlhttp = new XMLHttpRequest();
            xmlhttp.onreadystatechange = function() {
                if (xmlhttp.readyState == 4 && xmlhttp.status == 200){
                    document.getElementById('interactioncontainer').innerHTML = xmlhttp.responseText;
                }
            }
            xmlhttp.open('GET', '?dir=' + dir, true);
            xmlhttp.send();
        };

        function toggleDiv() {
            var x = document.getElementById('Create');
            if (x.style.display === "none") {
                x.style.display = "block";

            const pilih_negeri = document.getElementById("pilih_negeri");
            fetch("./api.php?getStates")
            .then(response => response.json())
            .then(data => {
                var stateslist = "";
                for (const key in data) {
                    const option = document.createElement('option');
                    option.value = data[key];
                    option.innerHTML = data[key];
                    pilih_negeri.appendChild(option);
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });

            } else {
                removeEvent();
                x.style.display = "none";
            }

        };

        function goToPlaylist(playlist) {
            setCookie('nm_viewmode', 'playlist', 7);

            // getting and displaying playlist contents
            xmlhttp = new XMLHttpRequest();
            xmlhttp.onreadystatechange = function() {
                if (xmlhttp.readyState == 4 && xmlhttp.status == 200){
                    document.getElementById('interactioncontainer').innerHTML = xmlhttp.responseText;
                }
            }
            xmlhttp.open('GET', '?playlist=' + playlist, true);
            xmlhttp.send();
        };

        function addToPlaylist(song) {
            song = song.replace(/\+/g, '%20');
            song = decodeURIComponent(song);
            // adding song to playlist, or initialising playlist with song
            var playlist = getCookie('nm_songs_playlist');
            if (playlist) {
                // removing song if it already exists
                playlist = JSON.parse(playlist);
                var songIdx = playlist.indexOf(song);
                if (songIdx >= 0) {
                    playlist.splice(songIdx, 1);
                }
                
                // adding song to end of playlist
                playlist.push(song);
            } else {
                var playlist = [song];
            }
            setCookie('nm_songs_playlist', JSON.stringify(playlist), 365);
            
            // if currently playing from playlist, also updating active songlist
            var playmode = getCookie('nm_playmode');
            if (playmode == 'playlist') {
                var shuffle = getCookie('nm_shuffle');
                if (shuffle == 'on') {
                    // adding new song between current and end of current shuffled songlist
                    var currentsong = getCookie('nm_nowplaying');
                    var songlist = getCookie('nm_songs_active');
                    if (songlist) {
                        songlist = JSON.parse(songlist);
                        var songIdx = songlist.indexOf(currentsong);
                        var randomIdx = Math.floor(Math.random() * (songlist.length - songIdx) + songIdx + 1);
                        songlist.splice(randomIdx, 0, song);
                        setCookie('nm_songs_active', JSON.stringify(songlist), 7);
                    }
                } else {
                    // getting current song's index in playlist
                    var currentsong = getCookie('nm_nowplaying');
                    var songIdx = playlist.indexOf(currentsong);

                    // setting cookies
                    setCookie('nm_songs_active', JSON.stringify(playlist), 7);
                    setCookie('nm_songs_active_idx', songIdx, 7);
                }
            }
            
        };

        function removeFromPlaylist(song) {
            song = song.replace(/\+/g, '%20');
            song = decodeURIComponent(song);
            var playlist = getCookie('nm_songs_playlist');
            if (playlist) {
                playlist = JSON.parse(playlist);
                var songIdx = playlist.indexOf(song);
                // moving to end if already in playlist
                if (songIdx >= 0) {
                    playlist.splice(songIdx, 1);
                }
                setCookie('nm_songs_playlist', JSON.stringify(playlist), 365);
                
                // if currently playing from playlist, also updating active songlist
                var playmode = getCookie('nm_playmode');
                if (playmode == 'playlist') {
                    var songlist = getCookie('nm_songs_active');
                    songlist = JSON.parse(songlist);
                    var currentsong = getCookie('nm_nowplaying');
                    var songIdx = songlist.indexOf(currentsong);
                    songlist.splice(songIdx, 1)
                    setCookie('nm_songs_active', JSON.stringify(songlist), 7);
                }
                    
                // showing updated playlist
                goToPlaylist('default');
            }
        };
        
        function moveInPlaylist(song, direction) {
            song = song.replace(/\+/g, '%20');
            song = decodeURIComponent(song);
            var playlist = getCookie('nm_songs_playlist');
            playlist = JSON.parse(playlist);
            var songIdx = playlist.indexOf(song);
            if (songIdx + direction >= 0 && songIdx + direction < playlist.length) {
                playlist.splice(songIdx, 1);
                playlist.splice(songIdx + direction, 0, song);
            }
            setCookie('nm_songs_playlist', JSON.stringify(playlist), 365);
                
            // if currently playing from playlist, also updating active songlist
            var playmode = getCookie('nm_playmode');
            var shuffle = getCookie('nm_shuffle');
            if (playmode == 'playlist' && shuffle != 'on') {
                var currentsong = getCookie('nm_nowplaying');
                var songIdx = playlist.indexOf(currentsong);
                setCookie('nm_songs_active', JSON.stringify(playlist), 7);           
                setCookie('nm_songs_active_idx', songIdx, 7);
            }
            
            // showing updated playlist
            goToPlaylist('default');
        };
        
        function clearPlaylist() {
            setCookie('nm_songs_playlist', '', 365);
                
            var playmode = getCookie('nm_playmode');
            if (playmode == 'playlist') {
                setCookie('nm_songs_active', '', 7);                
                setCookie('nm_songs_active_idx', '0', 7);
            }
                
            goToPlaylist('default');
        };

        function setPlayMode(mode, song) {
            setCookie('nm_playmode', mode, 7);

            // switching to appropriate songlist, shuffling where necessary
            if (mode == 'browse') {
                var songlist = getCookie('nm_songs_currentsongdir');
            } else if (mode == 'playlist') {
                var songlist = getCookie('nm_songs_playlist');
            }
            if (songlist) {
                songlist = JSON.parse(songlist)
                if (getCookie('nm_shuffle') == 'on') {
                    songlist = shuffleArray(songlist);
                    
                    // moving selected song to index 0
                    var songIdx = songlist.indexOf(song);
                    songlist[songIdx] = songlist[0];
                    songlist[0] = song;                
                }
                setCookie('nm_songs_active', JSON.stringify(songlist), 7);
            }
        };

        function advance(which) {
            // requesting next/previous song and loading it
            xmlhttp = new XMLHttpRequest();
            xmlhttp.onreadystatechange = function() {
                if (xmlhttp.readyState == 4 && xmlhttp.status == 200){
                    if (xmlhttp.responseText) {
                        window.location.href = '?play=' + xmlhttp.responseText;
                    } else if (which == 'next' && getCookie('nm_shuffle') == 'on') {
                        // end of shuffle playlist: restarting shuffle
                        toggleShuffle();
                        toggleShuffle();
                        advance('next');
                    }
                }
            }
            xmlhttp.open('GET', '?which=' + which, true);
            xmlhttp.send();
        };

        function toggleShuffle() {
            var shuffle = getCookie('nm_shuffle');
            if (shuffle == 'on') {
                // updating shuffle cookie and graphics
                setCookie('nm_shuffle', 'off', 7);
                document.getElementById('shufflebutton').classList.remove('active');

                // putting back original songlist
                var playmode = getCookie('nm_playmode');
                if (playmode == 'browse') {
                    var songlist = JSON.parse(getCookie('nm_songs_currentsongdir'));
                } else if (playmode == 'playlist') {
                    var songlist = JSON.parse(getCookie('nm_songs_playlist'));
                }

                // getting current song's index in that list
                var song = getCookie('nm_nowplaying');
                var songIdx = songlist.indexOf(song);
                
                // setting cookies
                setCookie('nm_songs_active', JSON.stringify(songlist), 7);
                setCookie('nm_songs_active_idx', songIdx, 7);
            } else {
                // updating shuffle cookie and graphics
                setCookie('nm_shuffle', 'on', 7);
                document.getElementById('shufflebutton').classList.add('active');

                // randomising active songlist
                var songlist = JSON.parse(getCookie('nm_songs_active'));
                var songlist = shuffleArray(songlist);

                // getting current song's index in that list
                var song = getCookie('nm_nowplaying');
                var songIdx = songlist.indexOf(song);

                // moving it to index 0
                songlist[songIdx] = songlist[0];
                songlist[0] = song;

                // setting cookies
                setCookie('nm_songs_active', JSON.stringify(songlist), 7);
                setCookie('nm_songs_active_idx', 0, 7);
            }
        };

        function shuffleArray(array) {
            var currentindex = array.length, temporaryValue, randomIndex;

            // While there remain elements to shuffle...
            while (0 !== currentindex) {
                // Pick a remaining element...
                randomIndex = Math.floor(Math.random() * currentindex);
                currentindex -= 1;

                // And swap it with the current element.
                temporaryValue = array[currentindex];
                array[currentindex] = array[randomIndex];
                array[randomIndex] = temporaryValue;
            }

            return array;
        };

        function setCookie(cname, cvalue, exdays) {
            var d = new Date();
            d.setTime(d.getTime() + (exdays*24*60*60*1000));
            var expires = 'expires=' + d.toUTCString();
            document.cookie = cname + '=' + encodeURIComponent(cvalue) + ';' + expires;
        }

        function getCookie(cname) {
            var name = cname + '=';
            var decodedCookie = decodeURIComponent(document.cookie);
            var ca = decodedCookie.split(';');
            for(var i = 0; i < ca.length; i++) {
                var c = ca[i];
                while (c.charAt(0) == ' ') {
                    c = c.substring(1);
                }
                if (c.indexOf(name) == 0) {
                    var result = c.substring(name.length, c.length);
                    result = result.replace(/\+/g, '%20');
                    return decodeURIComponent(result);
                }
            }
            return '';
        };

        document.addEventListener("DOMContentLoaded", function() {
            var audio = document.getElementById('audio');
            
            audio.addEventListener('error', function() {
                document.getElementById('error').innerHTML = 'Playback error';
                document.getElementById('error').style.display = 'block';
                setTimeout(function(){ advance('next'); }, 2000);
            });

            audio.addEventListener('ended', function() {
                advance('next');
            });
            
            
            audio.addEventListener('volumechange', function() {
                setCookie('nm_volume', audio.volume, 14);
            });
            
            var volume = getCookie('nm_volume');
            if (volume != null && volume) {
                audio.volume = volume;
            }

            {$onloadgoto}
        }, false);
        
        document.onkeydown = function(e){
            switch (e.keyCode) {
                case 90: // z
                    advance('previous');
                    break;
                case 88: // x
                    document.getElementById('audio').play();
                    document.getElementById('audio').fastSeek(0);
                    break;
                case 67: // c
                    var audio = document.getElementById('audio');
                    if (audio.paused) {
                        audio.play();
                    } else {
                        audio.pause();
                    }
                    break;
                case 86: // v
                    document.getElementById('audio').pause();
                    document.getElementById('audio').fastSeek(0);
                    break;
                case 66: // b
                    advance('next');
                    break;
                case 37: // left
                    advance('previous');
                    break;
                case 39: // right
                    advance('next');
                    break;
            }
        };
        
        function swipedetect(el, callback){
            // based on code from JavaScript Kit @ http://www.javascriptkit.com/javatutors/touchevents2.shtml
            var touchsurface = el,
                swipedir,
                startX,
                startY,
                distX,
                distY,
                threshold = 50,
                handleswipe = callback || function(swipedir){}
            touchsurface.addEventListener('touchstart', function(e){
                var touchobj = e.changedTouches[0]
                swipedir = 'none'
                dist = 0
                startX = touchobj.pageX
                startY = touchobj.pageY
            }, false)
            touchsurface.addEventListener('touchend', function(e){
                var touchobj = e.changedTouches[0]
                distX = touchobj.pageX - startX
                distY = touchobj.pageY - startY
                if (Math.abs(distX) >= threshold && Math.abs(distX) > Math.abs(distY)){
                    swipedir = (distX < 0)? 'left' : 'right'
                } else if (Math.abs(distY) >= threshold && Math.abs(distY) > Math.abs(distX)){
                    swipedir = (distY < 0)? 'up' : 'down'
                }
                handleswipe(swipedir)
            }, false)
        };
        window.addEventListener('load', function(){
            var el = document.getElementById('interactioncontainer');
            swipedetect(el, function(swipedir){
                if (swipedir == 'left'){
                    advance('next');
                } else if (swipedir == 'right'){
                    advance('previous');
                }
            })
	    // Get and set volume with cookie
            var audio = document.getElementById('audio');
            audio.addEventListener('volumechange', function() {
                setCookie('volume', audio.volume, 14);
            });
            var volume = getCookie('volume');
            if (volume != null && volume) {
                audio.volume = volume;
            }
        }, false);
    </script>

    <style>
        html, body {
                width: 100%;
                margin: 0px; padding: 0px;
                font-family: sans-serif; }

            html {
                    background: {$background} url('{$backgroundimg}') no-repeat fixed center top;
                    background-size: cover;}

            body {
                    min-height: 100vh;
                    box-sizing: border-box;
                    padding-bottom: 5px;
                    background-color: rgba(0, 0, 0, 0.25);  }

        #stickycontainer {
                position: sticky;
                top: 0;
                margin-bottom: 10px; }

            #playercontainer {
                    padding: 20px 0;
                    background-color: #333;
                    background-image: linear-gradient({$gradient1}, {$gradient2}); }

                #player {
                        width: {$width};
                        margin: 0 auto;
                        display: flex;
                        box-sizing: border-box;
                        padding: 10px;
                        background-color: #111; }

                    #albumart {
                            display: {$artdisplay};
                            width: 7.25vw;
                            height: 7.25vw;
                            margin-right: 10px;
                            background: url({$art}) center center / contain no-repeat; }

                    #song {
                            flex-grow: 1;
                            display: flex;
                            flex-direction: column;
                            justify-content: space-between; }

                        #songinfo { }

                            #songinfo div {
                                    color: grey;
                                    text-align: {$songinfoalign};
                                    font-size: 1.2vw;
                                    height: 1.4vw;
                                    width: 100%;
                                    overflow: hidden; }

                            #artist {
                                    display: {$artistdisplay}; }

                            #album {
                                    display: {$albumdisplay}; }

                            #year {
                                    margin-left: .35em;
                                    display: {$yeardisplay}; }

                                #year:before {
                                        content: "("; }

                                #year:after {
                                        content: ")"; }

                        #player audio {
                                width: 100%;
                                height: 1.3vw;
                                margin-top: 1.5vw; }

                #divider {
                        height: 2px;
                        background-color: {$accentbg}; }

        #error {
                box-sizing: border-box;
                width: {$width};
                display: {$errordisplay};
                color: white;
                text-align: center;
                word-break: break-all;
                margin: 20px auto 10px auto;
                background-color: #a00;
                padding: 10px; }

        #interactioncontainer {
                box-sizing: border-box;
                line-height: 1.5; }

            #header {
                    display: flex;
                    justify-content: flex-start;
                    flex-direction: row-reverse;
                    overflow: hidden;
                    flex-wrap: wrap;
                    font-size: 0;
                    width: {$width};
                    margin: 0 auto 10px auto; }

                #playlisttitle, #breadcrumbs, #passwordrequest, #Create {
                        font-size: medium;
                        margin-top: 10px;
                        flex-grow: 1;
                        color: #333;
                        background-color: {$menubg}; }

                    #Create {
                        font-weight: bold;
                        padding: 10px; }

                    #playlisttitle {
                            font-weight: bold;
                            padding: 10px; }
                            
                    #passwordrequest {
                            display: flex;
                            padding: 10px; }
                            
                    #passwordrequest form {
                            display: flex;
                            flex-grow: 1; }
                            
                        #passwordrequest #passwordinput {
                                margin: 0 10px;
                                flex-grow: 1; }

                    .breadcrumb, #breadcrumbactive {
                            display: inline-block;
                            padding: 10px; }

                    .breadcrumb:hover {
                            cursor: pointer;
                            background-color: {$menushadow}; }

                    #breadcrumbactive {
                            font-weight: bold; }

                .buttons {
                        display: flex;
                        font-size: medium;
                        margin-left: 10px;
                        margin-top: 10px; }

                    .button {
                            padding: 10px;
                            background-color: {$menubg};  }

                        .button:hover {
                                cursor: pointer;
                                background-color: {$menushadow}; }

                        .border {
                            border-right: 1px solid {$menushadow}; }

                        .active {
                                font-weight: bold;  }

                            .active span {
                                    border-bottom: 2px solid {$accentbg}; }

                .separator {
                        color: #bbb;
                        padding: 0 5px; }

            .list div {
                    width: {$width};
                    box-sizing: border-box;
                    margin: 0 auto;
                    padding: 5px 10px;
                    color: #333;
                    background-color: {$menubg};
                    border-bottom: 1px solid {$menushadow}; }

                .list div:last-child {
                        margin-bottom: 10px;
                        border: 0; }

                .list .dir:hover, .list .file:hover {
                        cursor: pointer;
                        background-color: {$menushadow};
                        font-weight: bold; }

                .list .nowplaying {
                        background-color: {$accentbg};
                        font-weight: bold; }

                    .nowplaying > div {
                            background-color: {$accentbg}; }

                    .nowplaying:hover > div {
                            background-color: {$menubg}; }

                .list .file {
                        display: flex;
                        flex-wrap: nowrap;
                        justify-content: flex-start; }


                .list .file a {
                        display: block;
                        flex-grow: 1;
                        color: #333;
                        word-break: break-all;
                        text-decoration: none; }
                        
                .list .nowplaying a {
                        color: {$accentfg}; }

                .list .file a:active {
                        display: block;
                        color: #fff;
                        text-decoration: none; }

                .list .file .filebutton {
                        border-radius: 100%;
                        border: 0;
                        width: 25px;
                        min-width: 25px;
                        height: 25px;
                        min-height: 25px;
                        color: {$filebuttonfg};
                        text-align: center;
                        font-weight: normal;
                        margin: 0;
                        font-size: medium;
                        padding: 0;
                        display: block; }

                    .list .file .filebutton:hover {
                            color: {$accentfg};
                            background-color: {$accentbg}; }
                            
                .list .file .playlistdirectory {
                        width: 100%;
                        font-size: x-small; }

        @media screen and (max-width: 900px) and (orientation:portrait) {
                #player, #error, #header, .list div { width: 95%; }
                #albumart { width: 24vw; height: 24vw; }
                #songinfo div { height: 5vw; font-size: 4vw; }
                #player audio { height: 5vw; }
                #playlisttitle, #breadcrumbs, #passwordrequest, .buttons, .list { font-size: small; }
        }

        @media screen and (max-width: 900px) and (orientation:landscape) {
                #stickycontainer { position: static; }
                #player, #error, #header, .list div { width: 80%; }
                #albumart { width: 12vw; height: 12vw; }
                #songinfo div { height: 2.5vw; font-size: 2vw; }
                #player audio { height: 2.5vw; }
                #playlisttitle, #breadcrumbs, #passwordrequest, .buttons, .list { font-size: small; }
        }
    </style>
</head>

<body>

<div id="stickycontainer">
    <div id="playercontainer">
        <div id="player">
            <div id="albumart"></div>
            <div id="song">
                <div id="songinfo">
                    <div id="songTitle"><b>{$songtitle}</b></div>
                    <div id="artist">{$artist}</div>
                    <div id="album">{$album}<span id="year">{$year}</span></div>
                </div>
                <div id="audiocontainer">
                    <audio id="audio" autoplay controls{$songsrc}></audio>
                </div>
            </div>
        </div>
    </div>
    <div id="divider"></div>
</div>

<div id="error">{$error}</div>
<div id="interactioncontainer"></div>

</body>
</html>
HTML;
}

?>
