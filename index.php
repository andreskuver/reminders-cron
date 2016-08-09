<?php

// Set default time zone
date_default_timezone_set('America/Argentina/Cordoba');

// Master connection data
define('MASTER_NAME', 'audex360_master');
define('MASTER_HOST', '127.0.0.1;port=3306');
define('MASTER_USER', 'audex360_domains');
define('MASTER_PASS', '5PpB#$u8&!Rf');

$dsn = 'mysql:dbname='.MASTER_NAME.';host='.MASTER_HOST;

// Try to connect to master database
$master = null;
try {
    $master = new \PDO($dsn, MASTER_USER, MASTER_PASS);
} catch (Exception $e) {
    var_dump('Error: '.$e->getMessage());
    die;
}

$currentDate = date("Y-m-d H:i:s");

/*
 *  Get all reminders with <nextAlarm date> less than or equals to now
 */
$sql = 'SELECT AlertID, AlertName, AlertMessage, AlertType, StartDate, EndDate, AlertRecurrence,'
        . ' RecurrencePattern, RecurrenceIncrement, NextAlert, LastSent, ToList, CCList, BCCList,'
        . ' ad.TheSubDomain as subdomain, ad.TheDomain as domain'
        . ' FROM  tblAlerts '
        . ' LEFT JOIN audex__domains ad USING(audexid)'
        . ' WHERE'
        . " isactive = true AND NextAlert <= '" . $currentDate ."'";

$prepared = $master->prepare($sql);
$prepared->execute();
$reminders = $prepared->fetchAll(\PDO::FETCH_ASSOC);

// Not scheduled reminders
if(count($reminders)== 0) {
    print_r("Not reminders.");
    die;
}

/* 
 * For each reminder, do action (send email ,etc.. ) and update
 */
foreach ($reminders as $reminder) {

    // For deployed app
    // Build the partner URL for execute alarm actions
    // Ex: partner.audex360.com/cron/:alertId
    $actionURL = 'http://' . $reminder['subdomain'] . '.'. $reminder['domain'] . 'com' . '/cron/' . $reminder['AlertID'];
    
    // For local test uncomment this line and comment the url action for deploy
    //$actionURL = 'http://locala360' . '/cron/' . $reminder['AlertID'];

    switch ($reminder['RecurrencePattern']){

        /*
         * Only once
         */
        case "once-a":
            /*
             * Once Time Alarm:
             *  Send email and set the alarm inactive
             */
            setReminderInactive($master, $reminder['AlertID']);
            break;

        /*
         * Every n number of days
         */
        case "daily-a":
            /*
             * Every n number of days alarm:
             *  Send email and set the next alarm date or set this alarm to inactive state
             */
            $endDate = $reminder['EndDate'];
            $oldNextAlert = $reminder['NextAlert'];
            $increment = $reminder['RecurrenceIncrement'];

            $nextAlert = date("Y-m-d H:i:s", strtotime($oldNextAlert. ' + '.$increment.' days'));

            if($nextAlert > $endDate)
                setReminderInactive($master, $reminder['AlertID']);
            else
               updateNextAlert($master, $reminder['AlertID'], $nextAlert);

            break;

        /*
         * Every 'n' days
         */
        case "daily-b":
            $endDate = $reminder['EndDate'];
            $oldNextAlert = $reminder['NextAlert'];

            $nextAlert = date("Y-m-d H:i:s", strtotime($oldNextAlert. ' + 1 days'));

            if($nextAlert > $endDate)
                setReminderInactive($master, $reminder['AlertID']);
            else
               updateNextAlert($master, $reminder['AlertID'], $nextAlert);

            break;

        /*
         * Every days
         */
        case "weekly-a":
            $increment = $reminder['RecurrenceIncrement'];
            list($weeksQty, $weekday) = explode(":", $increment);
            $oldNextAlert = $reminder['NextAlert'];

            $weeksQty--;

            if($weeksQty > 0) {
                $nextAlert = date("Y-m-d H:i:s", strtotime($oldNextAlert. ' + 7 days'));
                updateNextAlertAndRecurrenceIncrement($master, $reminder['AlertID'], 
                    $nextAlert, implode(':', array($weeksQty, $weekday)));
            }
            else{
                setReminderInactive($master, $reminder['AlertID']);

            }

            break;

        /*
         * Day 'n' every 'm' months
         */
        case "monthly-a":
            $increment = $reminder['RecurrenceIncrement'];
            list($monthsQty, $monthday) = explode(":", $increment);
            $oldNextAlert = $reminder['NextAlert'];

            $monthsQty--;

            if($monthsQty > 0){
                 $nextAlert = date("Y-m-d H:i:s", strtotime($oldNextAlert. ' + 1 months'));
                 $increment = implode(':', array($monthsQty, $monthday));
                 updateNextAlertAndRecurrenceIncrement($master, $reminder['AlertID'], $nextAlert, $increment);
            } else {
                setReminderInactive($master, $reminder['AlertID']);
            }


            break;

        /*
         * The 'nth' day every 'm' months
         */
        case "monthly-b":
            $increment = $reminder['RecurrenceIncrement'];
            list($nth, $weekday, $monthsQty) = explode(":", $increment);
            $oldNextAlert = $reminder['NextAlert'];

            $monthsQty--;

            if($monthsQty > 0){
                $time = mktime(substr($oldNextAlert, 11, 2), substr($oldNextAlert, 14, 2), substr($oldNextAlert, 17, 2),
                                substr($oldNextAlert, 5, 2), substr($oldNextAlert, 7, 2), substr($oldNextAlert, 0, 4));

                $year = date("Y", $time);
                $month = date("n", $time) + 1;
                $w = 0;
                $monthDay = 0;
                while ($w < $nth) {
                    $monthDay++;
                    if (date("l", mktime(0, 0, 0, $month, $monthDay, $year)) == $weekday) {
                        $w++;
                    }
                }
                $nextAlert = mktime(substr($startDateStr, 11, 2), substr($startDateStr, 14, 2), substr($startDateStr, 17, 2), $month, $monthDay, $year);
                $nextAlert = date("Y-m-d H:i:s", $nextAlert);

                $increment = implode(':', array($nth, $weekday, $monthsQty));
                updateNextAlertAndRecurrenceIncrement($master, $reminder['AlertID'], $nextAlert, $increment);
            } else {
                setReminderInactive($master, $reminder['AlertID']);
            }


            break;

        case "yearly-a":
            $endDate = $reminder['EndDate'];
            $oldNextAlert = $reminder['NextAlert'];

            $nextAlert = date("Y-m-d H:i:s", strtotime($oldNextAlert. ' + 1 years'));

            if($nextAlert > $endDate)
                setReminderInactive($master, $reminder['AlertID']);
            else
               updateNextAlert($master, $reminder['AlertID'], $nextAlert);


            break;

        case "yearly-b":
            $increment = $reminder['RecurrenceIncrement'];
            $weekdayNth = explode(":", $increment)[0];
            $weekday = explode(":", $increment)[1];
            $month = explode(":", $increment)[2];

            $endDate = $reminder['EndDate'];
            $oldNextAlert = $reminder['NextAlert'];

            $startDate = strtotime($startDateStr);

            $year = date("Y", $startDate);
            $w = 0;
            $monthDay = 0;
            while($w < $weekdayNth) {
                $monthDay++;
                if (date("l", mktime(0, 0, 0, $month, $monthDay, $year)) == $weekday) {
                    $w++;
                }
            }

            $nextYear = substr($startDateStr, 0, 4) + 1;
            $nextAlert = mktime(substr($oldNextAlert, 11, 2), substr($oldNextAlert, 14, 2), substr($oldNextAlert, 17, 2), $month, $monthDay, substr($startDateStr, 0, 4), $nextYear);
            $nextAlert = date("Y-m-d H:i:s", $nextAlert);

            if($nextAlert > $endDate)
                setReminderInactive($master, $reminder['AlertID']);
            else
               updateNextAlert($master, $reminder['AlertID'], $nextAlert);


            break;

        default:
            setReminderInactive($master, $reminder['AlertID']);
            break;

    }

    file_get_contents($actionURL);
}

/*
 * Change alarm status to inactive
 */
function setReminderInactive($master, $reminderID)
{
    $sql = 'UPDATE tblalerts SET isactive = 0 WHERE AlertID = :reminderID';
    $prepared = $master->prepare($sql);
    return $prepared->execute(array('reminderID'=>$reminderID));
}

function updateNextAlert($master, $reminderID, $nextAlert)
{
    $sql = "UPDATE tblalerts SET NextAlert = :nextAlert WHERE AlertID = :reminderID";
    $prepared = $master->prepare($sql);
    $prepared->bindParam('reminderID',$reminderID);
    $prepared->bindParam('nextAlert', $nextAlert);

    return $prepared->execute();
}

function updateNextAlertAndRecurrenceIncrement($master, $reminderID, $nextAlert, $increment)
{
    $sql = 'UPDATE tblalerts SET NextAlert = :nextAlert, RecurrenceIncrement = :increment '
         . 'WHERE AlertID = :reminderID';
    $prepared = $master->prepare($sql);
    return $prepared->execute(array('reminderID'=>$reminderID,'nextAlert'=>$nextAlert, 'increment'=>$increment));
}