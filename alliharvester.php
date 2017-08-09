<?php

/*
 *  This file is part of Alliharvester
 *
 *  Jira-SLA is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  Alliharvester is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with Alliharvester.  If not, see <http://www.gnu.org/licenses/>.
 */


use chobie\Jira\Api;
use chobie\Jira\Issue;
use chobie\Jira\Api\Authentication\Basic;
use chobie\Jira\Issues\Walker;

require_once 'vendor/autoload.php';

$cfFile =     'alliharvester.ini';
$reportName = 'Finna-release-summary-' . date("Ymd-Hi") . '.txt';
/*****************************************
if(count($argv) != 3 && count($argv) != 5) {
  usage();
  exit(0);
}


function usage() {
  global $argv;
  echo "usage: $argv[0] [-cf configfile] 1H|2H|ALL year\n";
  echo "       generate finna ticket statistics for either half, or the whole of a given year\n";
}
*****************************************/
try {
    $ini_array = parse_ini_file($cfFile, TRUE);
}	   
catch(Throwable $t) {
    echo "Failed to read configuration file \"$cfFile\":";
    echo $t->getMessage();
    exit(3);
}
catch(Exception $e) {
    echo "Failed to read configuration file \"$cfFile\":";
    echo $t->getMessage();
    exit(3);
}

if(array_key_exists('jira', $ini_array)) {
  $host = $ini_array['jira']['host'];
  $username = $ini_array['jira']['username'];
  $password = $ini_array['jira']['password'];
  $jql = $ini_array['jira']['jql'];
}
else {
  echo "$argv[0]: Cannot find stanza '[jira]' in configuration file '$cfFile' - aborting...\n";
  exit(3);
}



/*
 *  Select tickets (this goes to alliharvester.ini) that have
 *  status: 		resolved	status/name:	"Resolved"
 *  fix version/s: 	none		"fixVersions": [],
 *
 *
 *  We need to show the following fields of each ticket:
 *  Resolution				resolution/name
 *  Summary (title)			summary
 *  Type				    issuetype/name
 *  Comments				[expand comments]
 *  Assignee				assignee/displayName
 *  Reporter?				creator/displayName
 *  Issue links?		    issuelinks/outwardIssue/{key,summary,status}
 *
 *  There must be a more efficient way of doing this, instead of using the walker and
 *  refetching each issue with expanded comments.  Should get issue keys only with
 *  the initial search.  But now we must get the job done quickly.
 *
 *  Please note that we don't catch web links related to the issue at the moment as
 *  they are not included in the json data by default.  Don't know yet what to expand.
 */
function getTicketData($issue) {
    global $api;
    
    $key = $issue->getKey();
    $res = $api->getIssue($key, 'comment');
    if(!$res) {
        return FALSE;
    }
    //echo var_dump($res); 
    $xIssue = new Issue($res->getResult());  // error handling missing...

    $TData = array(
        'issue'       => $key,
        'priority'    => $xIssue->get('priority')['name'],  // getPriority() etc won't work...?!?
        'type'        => $xIssue->get('issuetype')['name'],
        'resolution'  => $xIssue->get('resolution')['name'],
        'assignee'    => $xIssue->get('assignee')['displayName'],
        'creator'     => $xIssue->get('creator')['displayName'],
        'reporter'    => $xIssue->get('reporter')['displayName'],
        'title'       => $xIssue->get('summary'),
        'created'     => preg_replace("/^([^T]+)T.*/", "$1", $xIssue->get('created')),
        'description' => $xIssue->get('description'),
        'links'       => array(),
        'comments'    => array(),
    );    
    $linkList = $xIssue->get('issuelinks');
    // outwardIssue.key
    // outwardIssue.fields.status.name
    // outwardIssue.summary
    // outwardIssue.issuetype.name
    // outwardIssue.priority.name
    foreach($linkList as $lnk) {
        if(isset($lnk['outwardIssue'])) {
            array_push($TData['links'], [
                'key'      => $lnk['outwardIssue']['key'],
                'status'   => $lnk['outwardIssue']['fields']['status']['name'],
                'title'    => $lnk['outwardIssue']['fields']['summary'],
                'type'     => $lnk['outwardIssue']['fields']['issuetype']['name'],
                'priority' => $lnk['outwardIssue']['fields']['priority']['name'],
            ]
            );
        }
    }
    
    $commentBlock = $xIssue->get('comment');

    foreach($commentBlock['comments'] as $c) {    // grab author.displayName, body, updated
        array_push($TData['comments'], array(
            'author' => $c['author']['displayName'],
            'title'  => $c['body'],
            'time'   => preg_replace("/^([^T]+)T.*/", "$1", $c['updated']),
        )
        );
    }
    return $TData;
}

function printIssue($fp, $ticket, $fullData) {

    fprintf($fp, "%-14s%s\n", $ticket['issue'], $ticket['title']);
    fprintf($fp, "%-14s%-12s%s\n", $ticket['type'], $ticket['priority'], $ticket['resolution']);
    if($fullData) {
        fprintf($fp, "%-14s%s\n\n%s\n\n", $ticket['created'], $ticket['assignee'], $ticket['description']);
        if(count($ticket['links']) > 0) {
            fprintf($fp, "Aiheeseen liittyvät tiketit:\n");
        }
        foreach($ticket['links'] as $l) {            //links
            fprintf($fp, "\t%-20s%s\n\t%s/%s/%s\n\n",
            $l['key'], $l['title'], $l['type'], $l['priority'], $l['status']);
        }
        foreach($ticket['comments'] as $c) {
            fprintf($fp, "%s (%s): %s\n\n", $c['author'], $c['time'], $c['title']);
        }
    }
    fprintf($fp, "\n\n");
}

if($password == '') {
  echo "Password: ";
  `/bin/stty -echo`;
  $password = trim(fgets(STDIN));
  `/bin/stty echo`;
  echo "\n";
  // echo "got \"$password\"\n";
}

$api = new Api($host, new Basic($username, $password));
$walker = new Walker($api);
$walker->push($jql);

$resolutions = [
    "Fixed" => 'fixes',
    "Answered" => 'noop',
    "Partly fixed" => 'fixes',
    "Won't Fix" => 'noop',
    "Duplicate" => 'noop',
    "Incomplete" => 'noop',
    "Continued in another issue" => 'noop',
    "Noted for Later Evaluation" => 'noop',
    "Not Ours" => 'noop',
    "Cannot Reproduce" => 'noop',
    "Spam" => 'skip',									   // don't report
    "No action required" => 'noop',
    "Done" => 'done',
    "Won't Do" => 'noop',
    "To be reviewed for development (Melinda)" => 'skip',  // don't report
];

$iCat = [
    'Improvement' => 'done',
    'Bug' => 'fixes',
    'Feature Request' => 'done',
];

$issues = [
    'done' => [],
    'fixes' => [],
    'noop' => [],
    'weird' => [],
];

// Report title for ticket category; full data flag (0 = print key and title only)
$titles = [
    'done'  => [ 'Parannukset', 1 ],
    'fixes' => [ 'Vikakorjaukset', 1 ],
    'noop'  => [ 'Ei tarvitse välittää', 0 ],
    'weird' => [ 'Tarkista nämä!', 0 ],
];

// Iterator calls the Api->search() function that returns Api::Result objects.
// Should still divide really implemented tickets into enhancements and bug fixes -
// could it be done reliably according to resolution (done/fixed)?
//
foreach ( $walker as $issue ) {
    $tmp = getTicketData($issue);
    $r = $tmp['resolution'];
    if(!isset($resolutions[$r])) {
        echo "unexpected resolution for issue ", $tmp['issue'], ", please check: \"$r\"";
        $r = 'weird';
    }
    else {
        $r = $resolutions[$r];
    }
    if($r != 'skip') {
        if($r != 'noop') {
            $r = $iCat[$tmp['type']];  // use issue type instead of done/fixed
        }
        array_push($issues[$r], $tmp);
    }
}

// Print listings of
// 1. implemented improvements
// 2. implemented bug fixes
// 3. non-issues (in short form)
// - is it possible to distinguish between mgmt if, finna.fi and organisation view automatically?
//    printTicket($issue);
//
//  Reporting order: done, fixed, ignored, weird

$sections = [ 'done', 'fixes', 'noop', 'weird' ];

$out = fopen($reportName, 'w');

foreach($sections as $s) {
    if(isset($issues[$s][0])) { fprintf($out, "%s\n\n", $titles[$s][0]); }
    foreach($issues[$s] as $i) {
        printIssue($out, $i, $titles[$s][1]);
    }
    fprintf($out, "\n\n\n");
}
$d = count($issues['done']);
$f = count($issues['fixes']);
$n = count($issues['noop']);

fprintf($out,
"Yhteensä %d parannusta, %d vikakorjausta ja %d tarpeetonta muutospyyntöä, kaikkiaan %d kappaletta.\n\n",
$d, $f, $n, $d+$f+$n);

fclose($out);

$s = sprintf("Done. %d improvements, %d fixes, %d to be ignored.  %d issues total.\n", $d, $f, $n, $d+$f+$n);
echo $s;

?>
